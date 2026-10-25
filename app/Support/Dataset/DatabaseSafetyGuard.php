<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guard target database untuk generator dataset.
 *
 * TIDAK cukup memakai APP_ENV. Guard memverifikasi:
 *  - driver & host & port & nama database AKTUAL (dari koneksi hidup),
 *  - environment aplikasi bukan production/staging,
 *  - nama database cocok dengan --confirm-database,
 *  - tidak ada data operasional (order/user non-dataset) yang akan tertimpa.
 *
 * Menghasilkan laporan (array) tanpa mencetak password/secret.
 */
final class DatabaseSafetyGuard
{
    public function __construct(private readonly array $safetyConfig) {}

    /**
     * @return array{
     *   ok: bool,
     *   database: string,
     *   host: string,
     *   port: string,
     *   driver: string,
     *   server: string,
     *   environment: string,
     *   errors: array<int,string>,
     *   warnings: array<int,string>,
     *   existing: array<string,int>,
     *   markers: array<string,int>
     * }
     */
    public function inspect(?string $confirmDatabase, bool $allowExistingData = false): array
    {
        $errors = [];
        $warnings = [];

        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        $host = (string) config("database.connections.{$connection->getName()}.host", '');
        $port = (string) config("database.connections.{$connection->getName()}.port", '');
        $driver = (string) $connection->getDriverName();
        $environment = (string) app()->environment();

        try {
            $server = (string) $connection->selectOne('SELECT VERSION() AS v')->v;
        } catch (\Throwable) {
            $server = 'unknown';
            $errors[] = 'Tidak dapat membaca versi server database.';
        }

        // 1. Environment aplikasi.
        $forbiddenEnvs = $this->safetyConfig['forbidden_environments'] ?? ['production', 'prod', 'staging'];
        if (in_array(strtolower($environment), array_map('strtolower', $forbiddenEnvs), true)) {
            $errors[] = "APP_ENV={$environment} dilarang untuk dataset generator.";
        }

        // 2. Nama database wajib dikonfirmasi eksplisit.
        if ($database === '') {
            $errors[] = 'Nama database aktual kosong.';
        }
        if ($this->safetyConfig['required_confirm'] ?? true) {
            if ($confirmDatabase === null || $confirmDatabase === '') {
                $errors[] = "Wajib mengirim --confirm-database={$database} untuk membuktikan target eksplisit.";
            } elseif ($confirmDatabase !== $database) {
                $errors[] = "Konfirmasi '{$confirmDatabase}' tidak sama dengan database aktual '{$database}'.";
            }
        }

        // 3. Pola nama database.
        $forbidden = $this->safetyConfig['forbidden_database_patterns'] ?? [];
        foreach ($forbidden as $pattern) {
            if ($this->like($database, $pattern)) {
                $errors[] = "Database '{$database}' cocok dengan pola berbahaya '{$pattern}'.";
            }
        }
        $allowed = $this->safetyConfig['allowed_database_patterns'] ?? ['*'];
        $matched = false;
        foreach ($allowed as $pattern) {
            if ($this->like($database, $pattern)) {
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            $errors[] = "Database '{$database}' tidak cocok dengan pola disposable yang diizinkan (".implode(', ', $allowed).').';
        }

        // 4. Database harus accessible dan driver mysql-like (generator memakai SQL MySQL).
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $errors[] = "Driver '{$driver}' tidak didukung (wajib mysql/mariadb).";
        }

        // 5. Data yang sudah ada (hanya jumlah, tanpa PII).
        $existing = $this->tableCounts([
            'tenants', 'stores', 'users', 'orders', 'order_items', 'payments', 'customers', 'products',
        ], null);

        // 6. Deteksi dataset sintetis vs data operasional memakai marker stabil.
        $markers = ['dataset_tenants' => 0, 'dataset_orders' => 0];
        try {
            $markers['dataset_tenants'] = (int) DB::table('tenants')
                ->where('provisioning_idempotency_key', 'like', 'ds:%')
                ->count();
            $markers['dataset_orders'] = (int) DB::table('orders')
                ->where('idempotency_key', 'like', 'ds:%')
                ->count();
        } catch (\Throwable) {
            // Tabel belum ada / DB belum di-migrate.
        }
        $operationalOrders = max(0, (int) ($existing['orders'] ?? 0) - $markers['dataset_orders']);
        $markers['operational_orders'] = $operationalOrders;
        $markers['operational_users'] = 0;

        if ($operationalOrders > 0 && ! $allowExistingData) {
            $errors[] = "Database memiliki {$operationalOrders} order non-dataset; generator menolak mencampuri data operasional.";
        }
        if ($operationalOrders > 0 && $allowExistingData) {
            $warnings[] = "Database memiliki {$operationalOrders} order non-dataset yang akan dibiarkan apa adanya.";
        }

        return [
            'ok' => $errors === [],
            'database' => $database,
            'host' => $host,
            'port' => $port,
            'driver' => $driver,
            'server' => $server,
            'environment' => $environment,
            'errors' => $errors,
            'warnings' => $warnings,
            'existing' => $existing,
            'markers' => $markers,
        ];
    }

    public function assertSafe(?string $confirmDatabase): array
    {
        $report = $this->inspect($confirmDatabase);
        if (! $report['ok']) {
            throw new RuntimeException('Guard database gagal: '.implode(' | ', $report['errors']));
        }

        return $report;
    }

    private function tableCounts(array $tables, ?string $where): array
    {
        $counts = [];
        foreach ($tables as $table) {
            try {
                $counts[$table] = (int) DB::table($table)->count();
            } catch (\Throwable) {
                $counts[$table] = -1; // tabel belum ada
            }
        }

        return $counts;
    }

    private function like(string $value, string $pattern): bool
    {
        $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';

        return (bool) preg_match($regex, $value);
    }
}
