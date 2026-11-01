<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Models\Store;
use App\Services\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * DocumentSequenceService — Generator nomor dokumen deterministik anti-tabrakan.
 *
 * Menerapkan:
 * - Pessimistic row locking (`SELECT ... FOR UPDATE`) dalam batas transaksi database.
 * - Format penomoran dokumen kanonikal MDM: `{PREFIX}/{STORE_CODE}/{YYYYMM}/{PADDED_NUMBER}`.
 * - Jaminan anti-duplikasi dan nomor urut berurutan (monotonik) meskipun di bawah konkurensi tinggi.
 * - Bounded retry mechanism terhadap InnoDB deadlock / lock wait timeout.
 */
class DocumentSequenceService
{
    /**
     * Alokasikan nomor urut integer berikutnya secara atomik dengan SELECT ... FOR UPDATE.
     *
     * @throws Throwable
     */
    public function nextValue(
        int $tenantId,
        string $docType,
        ?int $storeId = null,
        ?string $periodKey = null
    ): int {
        $scopeKey = $storeId !== null ? "store:{$storeId}" : 'global';
        $period = $periodKey !== null && $periodKey !== '' ? $periodKey : date('Ym');
        $prefix = strtoupper($docType);

        $maxAttempts = 5;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return (int) DB::transaction(function () use ($tenantId, $storeId, $scopeKey, $docType, $period, $prefix) {
                    // 1. Pastikan baris sequence tersedia
                    DB::table('document_sequences')->insertOrIgnore([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'scope_key' => $scopeKey,
                        'doc_type' => $docType,
                        'period_key' => $period,
                        'prefix' => $prefix,
                        'last_value' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // 2. Kunci baris dengan SELECT ... FOR UPDATE (pessimistic lock)
                    $seq = DB::table('document_sequences')
                        ->where('tenant_id', $tenantId)
                        ->where('scope_key', $scopeKey)
                        ->where('doc_type', $docType)
                        ->where('period_key', $period)
                        ->lockForUpdate()
                        ->first();

                    if (! $seq) {
                        throw new RuntimeException("Gagal mengunci sekuens dokumen untuk tenant {$tenantId}, {$docType}, {$scopeKey}, {$period}.");
                    }

                    $nextVal = (int) $seq->last_value + 1;

                    // 3. Update nilai sekuens
                    DB::table('document_sequences')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $seq->id)
                        ->update([
                            'last_value' => $nextVal,
                            'updated_at' => now(),
                        ]);

                    return $nextVal;
                }, attempts: 1);
            } catch (Throwable $e) {
                $driverCode = 0;
                if ($e instanceof QueryException) {
                    $driverCode = DatabaseErrorClassifier::getDriverCode($e);
                } elseif ($e instanceof PDOException) {
                    $driverCode = (int) ($e->errorInfo[1] ?? 0);
                }

                $isLockConflict = ($driverCode === DatabaseErrorClassifier::MYSQL_ER_LOCK_DEADLOCK
                    || $driverCode === DatabaseErrorClassifier::MYSQL_ER_LOCK_WAIT_TIMEOUT);

                if ($isLockConflict && $attempt < $maxAttempts) {
                    usleep(random_int(10_000, 40_000)); // 10ms - 40ms jitter

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Hasilkan string nomor dokumen terformat kanonikal:
     * `{PREFIX}/{STORE_CODE}/{YYYYMM}/{PADDED_NUMBER}`
     *
     * @param  int  $tenantId  Tenant ID
     * @param  string  $docType  Tipe dokumen (misal: INV, PO, SO, DN, PR)
     * @param  int|null  $storeId  ID cabang fisik
     * @param  string|null  $storeCode  Kode cabang (misal: STR01, JKT01)
     * @param  string|null  $periodKey  Periode format YYYYMM (default: tahun & bulan sekarang)
     * @param  int  $paddingLength  Jumlah digit padding angka (default: 4 -> 0001)
     */
    public function generateNextNumber(
        int $tenantId,
        string $docType,
        ?int $storeId = null,
        ?string $storeCode = null,
        ?string $periodKey = null,
        int $paddingLength = 4
    ): string {
        $prefix = strtoupper($docType);
        $period = $periodKey !== null && $periodKey !== '' ? $periodKey : date('Ym');

        // Resolusi kode store jika storeId ada namun storeCode kosong
        $code = $storeCode;
        if ($code === null && $storeId !== null) {
            $store = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($storeId);
            $code = $store?->code ?: 'STR'.str_pad((string) $storeId, 2, '0', STR_PAD_LEFT);
        }

        $sequence = $this->nextValue(
            tenantId: $tenantId,
            docType: $docType,
            storeId: $storeId,
            periodKey: $period
        );

        $paddedNumber = str_pad((string) $sequence, $paddingLength, '0', STR_PAD_LEFT);

        return $code !== null && $code !== ''
            ? "{$prefix}/{$code}/{$period}/{$paddedNumber}"
            : "{$prefix}/{$period}/{$paddedNumber}";
    }
}
