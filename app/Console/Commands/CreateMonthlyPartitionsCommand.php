<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Database\PartitionManagementService;
use Illuminate\Console\Command;

/**
 * Artisan Command: Pemeliharaan Partisi Bulanan MariaDB (TR-008).
 * Menjamin ketersediaan partisi N bulan ke depan sebelum pergantian bulan kalender.
 */
class CreateMonthlyPartitionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:create-monthly-partitions
                            {--table= : Target nama tabel tertentu (opsional)}
                            {--months=3 : Jumlah bulan ke depan yang dijamin keberadaannya}
                            {--dry-run : Pratinjau DDL tanpa mengeksekusi mutasi skema}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Buat partisi bulanan masa depan secara bergulir untuk tabel transaksi skala besar (TR-008).';

    public function handle(PartitionManagementService $service): int
    {
        $targetTable = $this->option('table');
        $monthsAhead = (int) $this->option('months');
        $isDryRun = (bool) $this->option('dry-run');

        $tables = $targetTable
            ? [(string) $targetTable]
            : ['order_items_partitioned', 'stock_movements_partitioned'];

        $this->info("Menjalankan pemeliharaan partisi bulanan (Months Ahead: {$monthsAhead}, Dry-Run: ".($isDryRun ? 'YA' : 'TIDAK').')...');

        $summary = [];

        foreach ($tables as $table) {
            $result = $service->ensureUpcomingMonthlyPartitions($table, $monthsAhead, $isDryRun);

            $summary[] = [
                'Tabel' => $table,
                'Partisi Dibuat' => empty($result['created']) ? '(Sudah Lengkap)' : implode(', ', $result['created']),
                'Status' => $isDryRun ? 'PRATINJAU DDL' : (empty($result['created']) ? 'UP TO DATE' : 'BERHASIL'),
            ];

            if ($isDryRun && ! empty($result['ddl'])) {
                foreach ($result['ddl'] as $ddl) {
                    $this->line("  [DDL] {$ddl}");
                }
            }
        }

        $this->table(['Tabel', 'Partisi Dibuat', 'Status'], $summary);

        return self::SUCCESS;
    }
}

