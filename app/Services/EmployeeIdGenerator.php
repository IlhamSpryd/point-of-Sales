<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Domain 1 (XD-03 / DB-001) — SATU-SATUNYA strategi pembuatan
 * `users.employee_id`.
 *
 * Kontrak yang berlaku (diverifikasi dari schema + data, bukan diasumsikan):
 *  - `users.employee_id` di-unique-kan GLOBAL oleh index
 *    `users_employee_id_unique` (dibuat migrasi
 *    `..._add_business_ids_to_master_tables`). Tidak ada unique per-tenant
 *    untuk kolom ini.
 *  - Karena itu nilai harus unik lintas SELURUH tenant. Mengubah kontrak
 *    menjadi per-tenant TIDAK dilakukan: product owner belum memutuskan
 *    (OD-29 tidak membahas identifier staf), sehingga mengubahnya akan
 *    mengubah kontrak data yang sudah dipakai 35 baris pengguna produksi
 *    tanpa dasar. Yang diperbaiki adalah CARA menghasilkan nilai, bukan
 *    kontraknya.
 *
 * Strategi yang dipakai sekarang:
 *  - `employee_id` = `"<PREFIX>-<tenantId>-<counter>"`; counter berasal dari
 *    tabel `employee_id_sequences` (satu baris per tenant), dinaikkan secara
 *    ATOMIK lewat `UPDATE ... SET last_value = LAST_INSERT_ID(last_value + 1)`
 *    sehingga dua penulis konkuren tidak mungkin mendapat nomor yang sama.
 *  - Keunikan GLOBAL dijamin `tenantId` di dalam nilai identifier, jadi dua
 *    tenant tidak dapat bertabrakan walau counter-nya identik.
 *  - Tidak ada `max(id) + 1` dan tidak ada loop `exists()`: keduanya balapan
 *    pada request konkuren dan tidak dapat mendeteksi tabrakan global.
 */
class EmployeeIdGenerator
{
    /**
     * Prefix lama instalasi Yovel — dipertahankan agar ID tidak berpindah gaya.
     */
    public const DEFAULT_PREFIX = 'YVL-EMP';

    /**
     * Batas panjang kenyamanan operasional untuk identifier staf.
     */
    private const MAX_LENGTH = 64;

    /**
     * Hasilkan employee_id baru yang unik secara GLOBAL untuk user yang
     * sedang dibuat.
     *
     * @param  int  $tenantId  Tenant pemilik user — bagian dari nilai, bukan
     *                         sekadar metadata.
     */
    public static function next(int $tenantId): string
    {
        $prefix = self::prefix();

        // Migration backfills existing users. This lazy insert covers newly-
        // created tenants without a sequence row; its composite primary key
        // serializes concurrent first use on the database engine.
        self::ensureSequenceRow($tenantId, $prefix);

        $next = self::allocate($tenantId, $prefix);
        $identifier = $prefix.'-'.$tenantId.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        if (strlen($identifier) > self::MAX_LENGTH) {
            throw new RuntimeException('Generated employee_id exceeds the database column length.');
        }

        return $identifier;
    }

    /**
     * Satu kali alokasi atomik: `last_value = last_value + 1` lalu baca
     * nilainya kembali lewat koneksi yang sama (transaksional di MariaDB/MySQL
     * karena UPDATE ... LAST_INSERT_ID(...) memakai koneksi milik query).
     */
    public static function allocate(int $tenantId, string $prefix): int
    {
        // `LAST_INSERT_ID(expr)` berlaku per-koneksi, jadi nilai yang dibaca
        // kembali pasti milik UPDATE ini — bukan milik penulis konkuren.
        $updated = DB::table('employee_id_sequences')
            ->where('tenant_id', $tenantId)
            ->where('prefix', $prefix)
            ->update(['last_value' => DB::raw('LAST_INSERT_ID(last_value + 1)')]);

        if ($updated === 0) {
            throw new RuntimeException(
                "employee_id sequence hilang untuk tenant {$tenantId} (prefix {$prefix})."
            );
        }

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }

    /**
     * Pastikan baris sequence ada. Dipakai oleh migrasi (backfill) dan oleh
     * jalur defensif di {@see next()}. Idempoten terhadap request konkuren:
     * `insertOrIgnore` tidak melempar pada PK duplikat.
     */
    public static function ensureSequenceRow(int $tenantId, ?string $prefix = null): void
    {
        $prefix ??= self::prefix();

        DB::table('employee_id_sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'prefix' => $prefix,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function prefix(): string
    {
        $prefix = config('pos.employee_id_prefix', self::DEFAULT_PREFIX);

        return is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }
}
