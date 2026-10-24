<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Penulis batch yang aman untuk generator dataset.
 *
 * Prinsip:
 *  - TIDAK memakai TRUNCATE, DELETE massal, atau melepas FK/trigger.
 *  - Menggunakan transaksi pendek per batch (bukan satu transaksi 12 bulan).
 *  - Idempoten secara logis: pemanggil disediakan checkpoint + marker.
 *  - Mencatat progres & error tanpa membocorkan credential.
 */
final class DatasetWriter
{
    private int $batchSize;

    private ?Command $console;

    /** @var array<string,int> */
    private array $written = [];

    /** @var array<string, list<array<string,mixed>>> buffer tabel => rows */
    private array $buffer = [];

    public function __construct(int $batchSize, ?Command $console = null)
    {
        $this->batchSize = max(10, $batchSize);
        $this->console = $console;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    /** Info progres. Tanpa secret. */
    public function info(string $message): void
    {
        $this->console?->info($message);
    }

    public function warn(string $message): void
    {
        $this->console?->warn($message);
    }

    /**
     * Insert satu baris ke buffer tabel. Buffer di-flush otomatis saat penuh.
     *
     * @param  array<string,mixed>  $row
     */
    public function insert(string $table, array $row): void
    {
        $this->buffer[$table][] = $row;
        if (count($this->buffer[$table]) >= $this->batchSize) {
            $this->flushTable($table);
        }
    }

    /**
     * Insert banyak baris sekaligus (tetap dipecah per batch).
     *
     * @param  array<int, array<string,mixed>>  $rows
     */
    public function insertMany(string $table, array $rows, bool $flush = false): void
    {
        foreach ($rows as $row) {
            $this->buffer[$table][] = $row;
        }

        if ($flush) {
            $this->flushTable($table);
        }
    }

    /** Ambil ID yang baru dibuat dari buffer (untuk relasi parent-child). */
    public function flushTable(string $table): void
    {
        if (empty($this->buffer[$table])) {
            return;
        }

        $rows = $this->buffer[$table];
        $this->buffer[$table] = [];
        // Semua baris tabel yang sama wajib memiliki kolom yang sama.
        $count = count($rows);
        $this->retry(function () use ($table, $rows) {
            DB::table($table)->insert($rows);
        }, $table);
        $this->written[$table] = ($this->written[$table] ?? 0) + $count;
    }

    public function flushAll(): void
    {
        foreach (array_keys($this->buffer) as $table) {
            $this->flushTable($table);
        }
    }

    /** Jalankan closure dalam transaksi pendek dengan retry deadlock terbatas. */
    public function transaction(Closure $callback, string $label = 'batch'): mixed
    {
        return $this->retry(function () use ($callback) {
            return DB::transaction($callback);
        }, $label, 3, true);
    }

    /**
     * Insert dengan resolusi ID otomatis (untuk tabel yang butuh ID segera).
     * Dipakai hemat — hanya untuk master data / parent yang dibutuhkan relasi.
     *
     * @param  array<string,mixed>  $row
     */
    public function insertGetId(string $table, array $row): int
    {
        $this->flushTable($table);

        return (int) $this->retry(fn () => DB::table($table)->insertGetId($row), $table);
    }

    /**
     * Update terbatas pada baris dataset berdasarkan primary key.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $where
     */
    public function update(string $table, array $where, array $values): int
    {
        $this->flushTable($table);

        return (int) $this->retry(fn () => DB::table($table)->where($where)->update($values), $table);
    }

    /** @return array<string,int> */
    public function counters(): array
    {
        return $this->written;
    }

    public function markWritten(string $table, int $count): void
    {
        $this->written[$table] = ($this->written[$table] ?? 0) + $count;
    }

    /**
     * Retry untuk deadlock (1205) / lock wait timeout (1213) / duplicate (1062 kadang
     * akibat race deterministik). Retry terbatas, lalu dilempar sebagai exception jelas.
     */
    private function retry(Closure $fn, string $label, int $attempts = 3, bool $wrapped = false): mixed
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $lastError = $e;
                if (! $this->isRetryable($e) || $attempt === $attempts) {
                    throw new DatasetGenerationException(
                        "Gagal menulis '{$label}' (attempt {$attempt}/{$attempts}): ".$this->sanitize($e->getMessage()),
                        0,
                        $e
                    );
                }
                usleep((int) (20000 * (2 ** ($attempt - 1))));
            }
        }

        throw $lastError;
    }

    private function isRetryable(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Deadlock')
            || str_contains($message, 'Lock wait timeout')
            || str_contains($message, '1213')
            || str_contains($message, '1205');
    }

    private function sanitize(string $message): string
    {
        // Hindari menampilkan string koneksi/credential jika muncul di pesan error.
        return preg_replace('/(password|pwd)=[^;\s]+/i', '$1=***', $message) ?? $message;
    }
}
