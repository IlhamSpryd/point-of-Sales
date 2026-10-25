<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Services\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DocumentSequenceService — Generator nomor dokumen pengadaan sekuensial aman-konkurensi
 * untuk Modul 5 (Apeiron POS).
 *
 * Menerapkan:
 * - Pessimistic row locking (`SELECT ... FOR UPDATE`)
 * - Bounded retry mechanism terhadap deadlock / lock wait timeout (pola EmployeeIdGenerator)
 * - Pemisahan ruang urut per (tenant_id, scope_key, doc_type, period_key)
 */
class DocumentSequenceService
{
    public const TYPE_SUPPLIER = 'SUP';

    public const TYPE_PURCHASE_REQUEST = 'PR';

    public const TYPE_PURCHASE_ORDER = 'PO';

    public const TYPE_GOODS_RECEIVED_NOTE = 'GRN';

    public const TYPE_SUPPLIER_INVOICE = 'PINV';

    public const TYPE_PURCHASE_RETURN = 'PRET';

    public const TYPE_DEBIT_NOTE = 'DN';

    public const TYPE_LANDED_COST = 'LC';

    /**
     * Resolusi kunci cakupan: 'global' jika tanpa store_id, atau 'store:{store_id}'.
     */
    public static function resolveScopeKey(?int $storeId): string
    {
        return $storeId !== null ? "store:{$storeId}" : 'global';
    }

    /**
     * Resolusi kunci periode: 'global' untuk supplier, atau 'YYYYMM' untuk dokumen bertanggal.
     */
    public static function resolvePeriodKey(string $docType, ?string $period = null): string
    {
        if ($period !== null && $period !== '') {
            return $period;
        }

        if (strtoupper($docType) === self::TYPE_SUPPLIER) {
            return 'global';
        }

        return date('Ym');
    }

    /**
     * Prefix standar bawaan per tipe dokumen M5.
     */
    public static function defaultPrefix(string $docType): string
    {
        return match (strtoupper($docType)) {
            self::TYPE_SUPPLIER => 'SUP',
            self::TYPE_PURCHASE_REQUEST => 'PR',
            self::TYPE_PURCHASE_ORDER => 'PO',
            self::TYPE_GOODS_RECEIVED_NOTE => 'GRN',
            self::TYPE_SUPPLIER_INVOICE => 'PINV',
            self::TYPE_PURCHASE_RETURN => 'PRET',
            self::TYPE_DEBIT_NOTE => 'DN',
            self::TYPE_LANDED_COST => 'LC',
            default => strtoupper($docType),
        };
    }

    /**
     * Format nomor dokumen akhir.
     */
    public static function formatNumber(
        string $prefix,
        ?int $storeId,
        string $period,
        int $sequence,
        int $padding = 4
    ): string {
        $padded = str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);

        $hasPeriod = $period !== '' && $period !== 'global' && $period !== 'none';

        if ($storeId !== null) {
            return $hasPeriod
                ? "{$prefix}-{$storeId}-{$period}-{$padded}"
                : "{$prefix}-{$storeId}-{$padded}";
        }

        return $hasPeriod
            ? "{$prefix}-{$period}-{$padded}"
            : "{$prefix}-{$padded}";
    }

    /**
     * Alokasikan nomor urut berikutnya secara atomik menggunakan SELECT ... FOR UPDATE.
     * Dilengkapi bounded retry terhadap deadlock atau lock wait timeout.
     */
    public function nextValue(
        int $tenantId,
        string $docType,
        ?int $storeId = null,
        ?string $periodKey = null,
        ?string $customPrefix = null
    ): int {
        $scopeKey = self::resolveScopeKey($storeId);
        $period = self::resolvePeriodKey($docType, $periodKey);
        $prefix = $customPrefix ?? self::defaultPrefix($docType);

        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return (int) DB::transaction(function () use ($tenantId, $storeId, $scopeKey, $docType, $period, $prefix) {
                    // 1. Pastikan baris record sequence telah ada
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

                    // 2. Kunci baris sequence dengan SELECT ... FOR UPDATE
                    $seq = DB::table('document_sequences')
                        ->where('tenant_id', $tenantId)
                        ->where('scope_key', $scopeKey)
                        ->where('doc_type', $docType)
                        ->where('period_key', $period)
                        ->lockForUpdate()
                        ->first();

                    if (! $seq) {
                        throw new RuntimeException("Gagal mengunci sequence dokumen: tenant {$tenantId}, {$docType}, {$scopeKey}, {$period}.");
                    }

                    $nextVal = (int) $seq->last_value + 1;

                    // 3. Perbarui nilai sequence yang dikunci
                    DB::table('document_sequences')
                        ->where('id', $seq->id)
                        ->update([
                            'last_value' => $nextVal,
                            'updated_at' => now(),
                        ]);

                    return $nextVal;
                }, attempts: 1);
            } catch (\Throwable $e) {
                $driverCode = 0;
                if ($e instanceof QueryException) {
                    $driverCode = DatabaseErrorClassifier::getDriverCode($e);
                } elseif ($e instanceof \PDOException) {
                    $driverCode = (int) ($e->errorInfo[1] ?? 0);
                }

                $isLockConflict = ($driverCode === DatabaseErrorClassifier::MYSQL_ER_LOCK_DEADLOCK
                    || $driverCode === DatabaseErrorClassifier::MYSQL_ER_LOCK_WAIT_TIMEOUT);

                if ($isLockConflict && $attempt < $maxAttempts) {
                    usleep(random_int(10_000, 50_000)); // 10ms - 50ms jitter

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Hasilkan nomor dokumen terformat lengkap.
     */
    public function nextNumber(
        int $tenantId,
        string $docType,
        ?int $storeId = null,
        ?string $periodKey = null,
        ?string $prefix = null,
        int $padding = 4
    ): string {
        $actualPrefix = $prefix ?? self::defaultPrefix($docType);
        $period = self::resolvePeriodKey($docType, $periodKey);

        $seq = $this->nextValue($tenantId, $docType, $storeId, $period, $actualPrefix);

        return self::formatNumber($actualPrefix, $storeId, $period, $seq, $padding);
    }

    /**
     * Helper khusus: Kode Pemasok (SUP-XXXX).
     */
    public function nextSupplierCode(int $tenantId, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_SUPPLIER,
            storeId: null,
            periodKey: 'global',
            prefix: 'SUP',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Purchase Request (PR-{store}-{period}-{seq}).
     */
    public function nextPurchaseRequestNumber(int $tenantId, int $storeId, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_PURCHASE_REQUEST,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'PR',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Purchase Order (PO-{store}-{period}-{seq}).
     */
    public function nextPurchaseOrderNumber(int $tenantId, int $storeId, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_PURCHASE_ORDER,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'PO',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Goods Received Note (GRN-{store}-{period}-{seq}).
     */
    public function nextGrnNumber(int $tenantId, int $storeId, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_GOODS_RECEIVED_NOTE,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'GRN',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Faktur Internal Pemasok (PINV-[store-]{period}-{seq}).
     */
    public function nextSupplierInvoiceNumber(int $tenantId, ?int $storeId = null, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_SUPPLIER_INVOICE,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'PINV',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Retur Pembelian (PRET-{store}-{period}-{seq}).
     */
    public function nextPurchaseReturnNumber(int $tenantId, int $storeId, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_PURCHASE_RETURN,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'PRET',
            padding: $padding
        );
    }

    /**
     * Helper khusus: Nomor Nota Debit (DN-[store-]{period}-{seq}).
     */
    public function nextDebitNoteNumber(int $tenantId, ?int $storeId = null, ?string $period = null, int $padding = 4): string
    {
        return $this->nextNumber(
            tenantId: $tenantId,
            docType: self::TYPE_DEBIT_NOTE,
            storeId: $storeId,
            periodKey: $period,
            prefix: 'DN',
            padding: $padding
        );
    }
}
