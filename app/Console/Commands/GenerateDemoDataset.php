<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Dataset\DatabaseSafetyGuard;
use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetOrchestrator;
use App\Support\Dataset\DatasetVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateDemoDataset extends Command
{
    protected $signature = 'pos:dataset:generate
        {--account=* : Batasi ke email akun demo tertentu (boleh diulang)}
        {--months=12 : Jumlah bulan kalender dataset}
        {--start-date= : Tanggal mulai (Y-m-d). Default dihitung dari months}
        {--end-date= : Tanggal akhir (Y-m-d). Default hari ini}
        {--seed= : Random seed (reproducible)}
        {--batch= : Ukuran batch insert}
        {--limit-days= : Smoke test: batasi jumlah hari}
        {--orders-per-store-per-day= : Override volume transaksi harian per cabang}
        {--dry-run : Tampilkan rencana, guard, dan estimasi TANPA menulis data}
        {--resume : Lanjutkan dari checkpoint hari terakhir}
        {--password= : Password akun demo (atau env DATASET_DEMO_PASSWORD)}
        {--confirm-database= : Wajib: nama database aktual sebagai bukti target}
        {--force : Jalankan walau guard menemukan data operasional (tidak menghapus apa pun)}
        {--verify-only : Hanya menjalankan verifikasi invariant}
        {--json : Keluarkan ringkasan mesin-terbaca (JSON)}';

    protected $description = 'Generate dataset POS realistis multi-bulan untuk 2 akun demo (idempoten, aman, tanpa TRUNCATE).';

    public function handle(): int
    {
        $configFile = (array) config('dataset', []);

        $overrides = array_filter([
            'months' => $this->option('months'),
            'start_date' => $this->option('start-date'),
            'end_date' => $this->option('end-date'),
            'seed' => $this->option('seed'),
            'batch_size' => $this->option('batch'),
            'limit_days' => $this->option('limit-days'),
            'dry_run' => (bool) $this->option('dry-run'),
            'resume' => (bool) $this->option('resume'),
        ], fn ($v) => $v !== null && $v !== false);

        if ($this->option('orders-per-store-per-day') !== null) {
            $overrides['volume']['orders_per_store_per_day'] = (int) $this->option('orders-per-store-per-day');
        }

        // Akun: dukung banyak --account. DatasetConfig::accounts() hanya menerima satu,
        // jadi filter dilakukan setelah validasi.
        $requestedAccounts = array_values(array_filter((array) $this->option('account')));

        $password = $this->option('password') ?: config('dataset.demo_password');

        try {
            $config = DatasetConfig::fromArray($configFile, $overrides, $password);
        } catch (Throwable $e) {
            $this->error('Konfigurasi dataset tidak valid: '.$e->getMessage());

            return self::FAILURE;
        }

        // ── Guard database (selalu, termasuk dry-run) ──────────────────
        $safety = (array) ($configFile['safety'] ?? []);
        $guard = new DatabaseSafetyGuard($safety);
        $report = $guard->inspect(
            $this->option('confirm-database'),
            (bool) $this->option('force')
        );

        if (! $this->option('json')) {
            $this->renderGuard($report);
        }

        if ($this->option('verify-only')) {
            return $this->runVerification($config, $report);
        }

        if (! $report['ok']) {
            $this->error('Dibatalkan: guard database tidak lolos. Tidak ada data yang ditulis.');

            return self::FAILURE;
        }

        if (! $config->hasPassword() && ! $config->dryRun) {
            $this->error('Password demo wajib: gunakan --password atau env DATASET_DEMO_PASSWORD.');
            $this->line('  Password tidak disimpan di config, tidak ditulis ke log, dan di-hash dengan Hash::make().');

            return self::FAILURE;
        }
        if ($config->dryRun) {
            $this->warn('DRY RUN: password tidak diperlukan dan tidak pernah dicetak.');
        }

        // Filter akun yang diminta.
        if ($requestedAccounts !== []) {
            $filtered = array_values(array_filter(
                $config->accounts,
                fn (array $a) => in_array(strtolower($a['email']), array_map('strtolower', $requestedAccounts), true)
            ));
            if ($filtered === []) {
                $this->error('Tidak ada akun demo yang cocok dengan --account.');

                return self::FAILURE;
            }
            $config = $this->withAccounts($config, $filtered);
        }

        $this->renderPlan($config);

        if ($config->dryRun) {
            $estimates = $this->estimateRows($config);
            $this->info('');
            $this->info('DRY RUN — estimasi baris (tidak ada data ditulis):');
            $this->table(['Domain', 'Estimasi baris'], collect($estimates)->map(fn ($v, $k) => [$k, number_format($v)])->values()->all());
            $this->info('');
            $this->info('Siap dijalankan. Tambahkan --confirm-database='.$report['database'].' tanpa --dry-run untuk eksekusi.');

            if ($this->option('json')) {
                $this->line(json_encode([
                    'dry_run' => true,
                    'run_id' => $config->runId,
                    'guard_ok' => $report['ok'],
                    'estimates' => $estimates,
                ], JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        }

        $this->warn('Mulai eksekusi. Tidak ada TRUNCATE/DELETE; data yang ada dipertahankan.');

        // ── Eksekusi ───────────────────────────────────────────────────
        $orchestrator = new DatasetOrchestrator($config, $this);
        $started = microtime(true);

        try {
            $result = $orchestrator->run();
        } catch (Throwable $e) {
            $this->error('Eksekusi gagal: '.$this->sanitize($e->getMessage()));
            $this->line('  Gunakan --resume untuk melanjutkan dari checkpoint hari terakhir.');

            return self::FAILURE;
        }

        // ── Verifikasi ────────────────────────────────────────────────
        $tenantIds = array_map(fn ($a) => (int) $a['tenant_id'], $result['accounts']);
        $checks = (new DatasetVerifier)->verify($tenantIds);

        $this->info('');
        $this->table(
            ['Verifikasi', 'Status', 'Detail'],
            array_map(fn ($c) => [$c['name'], $c['status'], $c['detail']], $checks)
        );

        $failed = array_filter($checks, fn ($c) => $c['status'] === 'FAIL');
        $elapsed = round(microtime(true) - $started, 1);

        if ($this->option('json')) {
            $this->line(json_encode(array_merge($result, ['verification' => $checks]), JSON_PRETTY_PRINT));
        } else {
            $this->renderSummary($result, $checks);
        }

        if ($failed !== []) {
            $this->error(count($failed).' verifikasi gagal.');

            return self::FAILURE;
        }

        $this->info("Selesai dalam {$elapsed} detik.");

        return self::SUCCESS;
    }

    private function runVerification(DatasetConfig $config, array $guardReport): int
    {
        $rows = DB::table('tenants')
            ->where('provisioning_idempotency_key', 'like', 'ds:%')
            ->pluck('id')->all();

        if ($rows === []) {
            $this->warn('Tidak ada tenant dataset (marker ds:%) untuk diverifikasi.');

            return self::FAILURE;
        }

        $checks = (new DatasetVerifier)->verify(array_map('intval', $rows));
        $this->table(['Verifikasi', 'Status', 'Detail'], array_map(fn ($c) => [$c['name'], $c['status'], $c['detail']], $checks));

        return array_filter($checks, fn ($c) => $c['status'] === 'FAIL') === [] ? self::SUCCESS : self::FAILURE;
    }

    private function renderGuard(array $report): void
    {
        $this->info('┌─ Guard database ─────────────────────────────────────');
        $this->line("│ database : {$report['database']}");
        $this->line("│ host:port: {$report['host']}:{$report['port']}");
        $this->line("│ driver   : {$report['driver']} (server {$report['server']})");
        $this->line("│ APP_ENV  : {$report['environment']}");
        $existing = collect($report['existing'])->map(fn ($v, $k) => "{$k}={$v}")->implode(' ');
        $this->line("│ existing : {$existing}");
        foreach ($report['warnings'] as $warning) {
            $this->line("│ ⚠ {$warning}");
        }
        foreach ($report['errors'] as $error) {
            $this->line("│ ✗ {$error}");
        }
        $this->line('└──────────────────────────────────────────────────────');
    }

    private function renderPlan(DatasetConfig $config): void
    {
        $this->info('');
        $this->info('Rencana dataset');
        $this->table(['Parameter', 'Nilai'], [
            ['Run ID', $config->runId],
            ['Fingerprint', $config->fingerprint()],
            ['Seed', (string) $config->seed],
            ['Periode', $config->startDate->toDateString().' → '.$config->effectiveEndDate()->toDateString()." ({$config->totalDays()} hari)"],
            ['Akun', (string) count($config->accounts)],
            ['Batch size', (string) $config->batchSize],
            ['Transaksi/hari/cabang', (string) $config->volumeInt('orders_per_store_per_day', 60)],
            ['Produk', (string) $config->volumeInt('products', 120)],
            ['Pelanggan', (string) $config->volumeInt('customers', 900)],
            ['Pemasok', (string) $config->volumeInt('suppliers', 8)],
            ['Pegawai/cabang', (string) $config->volumeInt('employees_per_store', 7)],
            ['Mode', $config->dryRun ? 'DRY RUN' : ($config->resume ? 'RESUME' : 'EXECUTE')],
        ]);
    }

    /** @return array<string,int> */
    private function estimateRows(DatasetConfig $config): array
    {
        $days = $config->totalDays();
        $accountCount = count($config->accounts);
        $ordersPerStoreDay = $config->volumeInt('orders_per_store_per_day', 60);
        $storesPerAccount = 0;

        foreach ($config->accounts as $account) {
            $storesPerAccount += min(count($account['stores']), $config->volumeInt('max_stores', 6));
        }
        $storesPerAccount = (int) round($storesPerAccount / max(1, $accountCount));

        // Rata-rata multiplier weekday/weekend/season dihitung kasar.
        $avgMultiplier = 1.0 * (($config->volume['weekend_multiplier'] ?? 1.45) ** (2 / 7))
            * ($config->behaviorFloat('store_char_multipliers', 1.0) ?: 1.0);
        $avgItems = 0.0;
        foreach (($config->volume['items_per_order'] ?? [1 => 34]) as $items => $weight) {
            $avgItems += $items * $weight;
        }
        $avgItems = $avgItems / max(1, array_sum($config->volume['items_per_order'] ?? [1 => 34]));

        $orders = (int) ($ordersPerStoreDay * $storesPerAccount * $days * $accountCount * 1.1);

        return [
            'orders' => $orders,
            'order_items' => (int) ($orders * $avgItems),
            'order_item_modifiers' => (int) ($orders * $avgItems * 0.25),
            'payments' => (int) ($orders * 1.06),
            'stock_movements' => (int) ($orders * $avgItems * 0.12),
            'ingredient_stock_movements' => (int) ($orders * $avgItems * 4),
            'loyalty_ledger' => (int) ($orders * $config->behaviorFloat('customer_attached_rate', 0.38) * 0.7),
            'shifts' => (int) ($storesPerAccount * $days * $accountCount * 2),
            'cash_drawer_movements' => (int) ($storesPerAccount * $days * $accountCount * 0.7),
            'purchase_orders' => (int) ($storesPerAccount * ($days / 7) * 3 * $accountCount),
            'ingredient_stock_balances' => (int) ($storesPerAccount * $config->volumeInt('ingredients', 60) * $accountCount),
        ];
    }

    private function renderSummary(array $result, array $checks): void
    {
        $this->info('');
        $this->info('Ringkasan per akun');
        foreach ($result['accounts'] as $account) {
            $this->info('');
            $this->line("  {$account['email']} (tenant #{$account['tenant_id']})");
            $this->table(['Metrik', 'Jumlah'], [
                ['Cabang', number_format($account['counts']['stores'])],
                ['Pegawai', number_format($account['counts']['users'])],
                ['Perangkat', number_format($account['counts']['devices'])],
                ['Produk', number_format($account['counts']['products'])],
                ['Pelanggan', number_format($account['counts']['customers'])],
                ['Pemasok', number_format($account['counts']['suppliers'])],
                ['Order', number_format($account['counts']['orders'])],
                ['Order items', number_format($account['counts']['order_items'])],
                ['Pembayaran', number_format($account['counts']['payments'])],
                ['Retur', number_format($account['counts']['order_returns'])],
                ['Purchase order', number_format($account['counts']['purchase_orders'])],
                ['Goods receipt', number_format($account['counts']['goods_receipts'])],
                ['Stock movement', number_format($account['counts']['stock_movements'])],
                ['Ingredient movement', number_format($account['counts']['ingredient_stock_movements'])],
                ['Loyalty ledger', number_format($account['counts']['loyalty_ledger'])],
                ['Shift', number_format($account['counts']['shifts'])],
                ['EOD settlement', number_format($account['counts']['eod_settlements'])],
                ['Rentang tanggal', "{$account['date_range']['min']} → {$account['date_range']['max']}"],
                ['Bulan berisi penjualan', (string) $account['months_with_sales']],
                ['Durasi akun (detik)', (string) $account['duration_seconds']],
            ]);
        }

        $this->info('');
        $this->info("Total durasi: {$result['duration_seconds']} detik | run_id={$result['run_id']} | fingerprint={$result['fingerprint']}");
    }

    private function withAccounts(DatasetConfig $config, array $accounts): DatasetConfig
    {
        return new DatasetConfig(
            runId: $config->runId,
            seed: $config->seed,
            batchSize: $config->batchSize,
            daysPerCheckpoint: $config->daysPerCheckpoint,
            startDate: $config->startDate,
            endDate: $config->endDate,
            accounts: $accounts,
            volume: $config->volume,
            behavior: $config->behavior,
            procurement: $config->procurement,
            period: $config->period,
            planCode: $config->planCode,
            cashierPin: $config->cashierPin,
            password: $config->password,
            markerPrefix: $config->markerPrefix,
            dryRun: $config->dryRun,
            limitDays: $config->limitDays,
            onlyAccountEmail: null,
            resume: $config->resume,
        );
    }

    private function sanitize(string $message): string
    {
        return preg_replace('/(password|pwd|secret)=[^;\s,]+/i', '$1=***', $message) ?? $message;
    }
}
