<?php

declare(strict_types=1);

namespace App\Services\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service Pemeliharaan Partisi Bulanan MariaDB (TR-008).
 * Mengelola pembuatan partisi bergulir otomatis (Rolling Monthly Partitions)
 * serta verifikasi partition pruning query.
 */
class PartitionManagementService
{
    /**
     * Dapatkan daftar partisi aktif dari information_schema.
     *
     * @return array<int, array{
     *     name: string,
     *     position: int,
     *     description: string,
     *     table_rows: int
     * }>
     */
    public function getPartitions(string $tableName): array
    {
        $rows = DB::select('
            SELECT PARTITION_NAME, PARTITION_ORDINAL_POSITION, PARTITION_DESCRIPTION, TABLE_ROWS
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND PARTITION_NAME IS NOT NULL
            ORDER BY PARTITION_ORDINAL_POSITION ASC
        ', [$tableName]);

        return array_map(function ($row) {
            return [
                'name' => (string) $row->PARTITION_NAME,
                'position' => (int) $row->PARTITION_ORDINAL_POSITION,
                'description' => (string) ($row->PARTITION_DESCRIPTION ?? ''),
                'table_rows' => (int) ($row->TABLE_ROWS ?? 0),
            ];
        }, $rows);
    }

    /**
     * Pastikan partisi bulanan masa depan tersedia untuk N bulan ke depan.
     *
     * @return array{
     *     table: string,
     *     created: array<int, string>,
     *     ddl: array<int, string>
     * }
     */
    public function ensureUpcomingMonthlyPartitions(string $tableName, int $monthsAhead = 3, bool $dryRun = false): array
    {
        $existing = array_column($this->getPartitions($tableName), 'name');
        $hasPmax = in_array('pmax', $existing, true);

        $created = [];
        $ddlStatements = [];

        $current = Carbon::now()->startOfMonth();

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $targetMonth = (clone $current)->addMonths($i);
            $partName = 'p'.$targetMonth->format('Y_m');

            if (in_array($partName, $existing, true)) {
                continue;
            }

            // Batas atas partisi adalah tanggal 1 bulan berikutnya pukul 00:00:00
            $upperBound = (clone $targetMonth)->addMonth()->format('Y-m-d 00:00:00');

            if ($hasPmax) {
                $sql = "ALTER TABLE {$tableName} REORGANIZE PARTITION pmax INTO (
                    PARTITION {$partName} VALUES LESS THAN ('{$upperBound}'),
                    PARTITION pmax VALUES LESS THAN MAXVALUE
                )";
            } else {
                $sql = "ALTER TABLE {$tableName} ADD PARTITION (
                    PARTITION {$partName} VALUES LESS THAN ('{$upperBound}')
                )";
            }

            $ddlStatements[] = $sql;
            $created[] = $partName;

            if (! $dryRun) {
                DB::statement($sql);
                // Perbarui daftar existing agar iterasi bulan berikutnya mengenali partisi baru
                $existing[] = $partName;
                Log::info("TR-008: Berhasil membuat partisi baru {$partName} pada tabel {$tableName}.", [
                    'upper_bound' => $upperBound,
                ]);
            }
        }

        return [
            'table' => $tableName,
            'created' => $created,
            'ddl' => $ddlStatements,
        ];
    }

    /**
     * Eksekusi EXPLAIN PARTITIONS untuk memverifikasi efektivitas query pruning.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function explainPartitionQuery(string $query, array $bindings = []): array
    {
        $explainQuery = 'EXPLAIN PARTITIONS '.$query;

        return DB::select($explainQuery, $bindings);
    }
}

