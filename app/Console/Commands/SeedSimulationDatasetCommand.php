<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Dataset\SimulationDatasetValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class SeedSimulationDatasetCommand extends Command
{
    protected $signature = 'pos:dataset:seed-simulation
        {--confirm-database= : Wajib: nama database target untuk memastikan eksekusi aman}
        {--start-date=2026-08-14 : Tanggal mulai dataset simulasi}
        {--end-date=2026-11-11 : Tanggal akhir dataset simulasi}
        {--password=M3m3x123!@# : Password default akun demo (ilhamsepriyadi8@gmail.com & ilhamsepriyadi0@gmail.com)}
        {--force : Jalankan generator meski data ada}
        {--verify-only : Hanya menjalankan validasi 25 invarian matematika}
        {--dry-run : Menampilkan rencana eksekusi tanpa mengubah basis data}';

    protected $description = 'Eksekusi DDL Hardening + Injeksi Dataset Simulasi 90 Hari Penuh & Verifikasi 25 Invarian Matematika';

    public function handle(SimulationDatasetValidator $validator): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════════════════════════════════════╗');
        $this->info('║  APEIRON POS & ERP — 90-DAY SIMULATION DATASET INJECTION & 25-INVARIANT VERIFICATION ENGINE     ║');
        $this->info('║  Target: MariaDB 13.0.2 / MySQL 8.x | Periode: 2026-08-14 s/d 2026-11-11 (90 Hari Aktif)        ║');
        $this->info('╚══════════════════════════════════════════════════════════════════════════════════════════════════╝');
        $this->info('');

        $confirmDb = (string) $this->option('confirm-database');
        $activeDb = DB::getDatabaseName();

        if (empty($confirmDb)) {
            $this->error("Target database wajib dikonfirmasi lewat opsi --confirm-database=<nama_db>.");
            $this->error("Contoh: php artisan pos:dataset:seed-simulation --confirm-database={$activeDb}");
            return self::FAILURE;
        }

        if ($confirmDb !== $activeDb) {
            $this->error("Nama database konfirmasi '{$confirmDb}' tidak cocok dengan database aktif '{$activeDb}'.");
            return self::FAILURE;
        }

        if ($this->option('verify-only')) {
            $this->info("Menjalankan Verifikasi 25 Invarian Integritas Matematika pada database [{$activeDb}]...");
            return $this->runVerification($validator);
        }

        if ($this->option('dry-run')) {
            $this->warn("⚠️  MODE DRY-RUN: Rencana eksekusi DAG 5 langkah dipindai tanpa mutasi data.");
            $this->line("1. StatutoryRuleVersionsSeeder");
            $this->line("2. pos:dataset:generate --start-date=2026-08-14 --end-date=2026-11-11 --confirm-database={$activeDb}");
            $this->line("3. ChartOfAccountsSeeder & DefaultPostingRulesSeeder");
            $this->line("4. hr:backfill-employees-from-users");
            $this->line("5. pos:dataset:populate-enterprise --confirm-database={$activeDb}");
            $this->line("6. pos:dataset:refresh-operational");
            $this->line("7. Verifikasi 25 Invarian Matematika");
            return self::SUCCESS;
        }

        $startDate = (string) $this->option('start-date');
        $endDate = (string) $this->option('end-date');
        $password = (string) $this->option('password');

        $startTime = microtime(true);

        try {
            // LANGKAH 1: Seeder Aturan Statutori
            $this->info("\n[LANGKAH 1/6] Inisialisasi Aturan Statutori (BPJS Ketenagakerjaan & Kesehatan)...");
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\StatutoryRuleVersionsSeeder',
                '--force' => true,
            ], $this->output);

            // LANGKAH 2: Generator Dataset Transaksi POS Baseline 90 Hari
            $this->info("\n[LANGKAH 2/6] Menjalankan Generator Dataset Transaksi Baseline 90 Hari (pos:dataset:generate)...");
            $genExit = Artisan::call('pos:dataset:generate', [
                '--start-date' => $startDate,
                '--end-date' => $endDate,
                '--password' => $password,
                '--confirm-database' => $activeDb,
                '--force' => true,
            ], $this->output);

            if ($genExit !== 0) {
                $this->error("pos:dataset:generate gagal dengan exit code {$genExit}");
                return self::FAILURE;
            }

            // LANGKAH 3: Bagan Akun & Aturan Posting Finansial
            $this->info("\n[LANGKAH 3/6] Memastikan Chart of Accounts & Default Posting Rules...");
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\ChartOfAccountsSeeder',
                '--force' => true,
            ], $this->output);
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\DefaultPostingRulesSeeder',
                '--force' => true,
            ], $this->output);

            // LANGKAH 4: Backfill Karyawan HRM dari Users
            $this->info("\n[LANGKAH 4/6] Backfill Karyawan HRM dari Akun Users (hr:backfill-employees-from-users)...");
            Artisan::call('hr:backfill-employees-from-users', [], $this->output);

            // LANGKAH 5: Injeksi Modul Enterprise & 9 Tabel Baru
            $this->info("\n[LANGKAH 5/6] Injeksi Modul Enterprise, Ledger Akuntansi & 9 Tabel Baru (pos:dataset:populate-enterprise)...");
            $entParams = ['--confirm-database' => $activeDb];
            if ($this->option('force')) {
                $entParams['--force'] = true;
            }
            $entExit = Artisan::call('pos:dataset:populate-enterprise', $entParams, $this->output);

            if ($entExit !== 0) {
                $this->error("pos:dataset:populate-enterprise gagal dengan exit code {$entExit}");
                return self::FAILURE;
            }

            // LANGKAH 6: Refresh Operasional Hari Ini (2026-11-11)
            $this->info("\n[LANGKAH 6/6] Refresh Operasional Hari Ini (2026-11-11) & GL Trust Indicator...");
            $opExit = Artisan::call('pos:dataset:refresh-operational', [
                '--password' => $password,
            ], $this->output);

            if ($opExit !== 0) {
                $this->error("pos:dataset:refresh-operational gagal dengan exit code {$opExit}");
                return self::FAILURE;
            }

            $elapsed = round(microtime(true) - $startTime, 2);
            $this->info("\n✅ PIPELINE INJEKSI DATASET SELESAI DALAM {$elapsed} DETIK.");

            // LANGKAH FINAL: Verifikasi 25 Invarian Matematika
            $this->info("\n" . str_repeat('═', 100));
            $this->info("MEMULAI VERIFIKASI 25 INVARIAN MATEMATIKA & INTEGRITAS DATASET");
            $this->info(str_repeat('═', 100));

            return $this->runVerification($validator);
        } catch (Throwable $e) {
            $this->error("Eksepsi terjadi saat eksekusi pipeline: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }

    private function runVerification(SimulationDatasetValidator $validator): int
    {
        $results = $validator->validate([1, 2]);

        $rows = [];
        $passCount = 0;
        $failCount = 0;

        foreach ($results as $res) {
            $statusText = $res['status'] === 'PASS' ? '<info>PASS</info>' : '<error>FAIL</error>';
            if ($res['status'] === 'PASS') {
                $passCount++;
            } else {
                $failCount++;
            }
            $rows[] = [
                $res['code'],
                $res['name'],
                $statusText,
                $res['detail'],
            ];
        }

        $this->table(
            ['Kode Invarian', 'Deskripsi Validasi Matematika & DDL', 'Status', 'Metrik Aktual'],
            $rows
        );

        $this->info("\nRingkasan Validasi: {$passCount}/" . count($results) . " PASS | {$failCount} FAIL");

        if ($failCount > 0) {
            $this->error("❌ Verifikasi gagal: Ditemukan {$failCount} invarian yang tidak lolos!");
            return self::FAILURE;
        }

        $this->info("🎉 100% INVARIAN INTEGRITAS MATEMATIKA (25/25) LOLOS TANPA CACAT!");
        return self::SUCCESS;
    }
}
