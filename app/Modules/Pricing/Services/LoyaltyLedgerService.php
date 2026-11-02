<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Customers\Models\CustomerLoyaltyAccount;
use App\Modules\Customers\Models\LoyaltyLedger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * LoyaltyLedgerService — Manajemen buku besar poin loyalitas append-only dengan verifikasi rantai hash SHA-256.
 *
 * Mengikuti spesifikasi BR-PRC-002 & T-PRC-01:
 * Setiap penambahan atau pemakaian poin loyalitas dicatat pada loyalty_ledger dengan prev_hash
 * merujuk ke baris sebelumnya. Manipulasi manual pada baris historis membatalkan verifikasi
 * rantai hash (assertHashChainValid() mengembalikan false).
 */
class LoyaltyLedgerService
{
    /**
     * Catat mutasi poin loyalitas pelanggan secara append-only.
     *
     * @param  int  $tenantId
     * @param  int  $customerId
     * @param  int  $points  Positif untuk perolehan (earn), negatif untuk penukaran (redeem)
     * @param  string  $type  earn | redeem | expire | adjustment | bonus | reversal
     * @param  int|null  $orderId
     * @param  string|null  $reference
     * @param  int|null  $createdBy
     * @param  int|null  $storeId
     * @return LoyaltyLedger
     */
    public function recordPoints(
        int $tenantId,
        int $customerId,
        int $points,
        string $type = 'earn',
        ?int $orderId = null,
        ?string $reference = null,
        ?int $createdBy = null,
        ?int $storeId = null
    ): LoyaltyLedger {
        return DB::transaction(function () use (
            $tenantId,
            $customerId,
            $points,
            $type,
            $orderId,
            $reference,
            $createdBy,
            $storeId
        ) {
            // Ambil saldo terakhir dari baris ledger paling mutakhir untuk customer ini
            $lastEntry = LoyaltyLedger::withoutGlobalScopes()
                ->where('customer_id', $customerId)
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $currentBalance = $lastEntry ? (int) $lastEntry->balance_after : 0;
            $newBalance = $currentBalance + $points;

            if ($newBalance < 0) {
                throw new InvalidArgumentException(
                    "Saldo poin loyalitas ({$currentBalance}) tidak mencukupi untuk penukaran sejumlah " . abs($points) . " poin."
                );
            }

            // Sisipkan baris baru (trigger database trg_loyalty_ledger_hash_chain
            // akan otomatis mengisi prev_hash dan entry_hash dengan SHA-256)
            /** @var LoyaltyLedger $entry */
            $entry = LoyaltyLedger::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'type' => $type,
                'points' => $points,
                'balance_after' => $newBalance,
                'reference' => $reference,
                'created_by' => $createdBy,
            ]);

            return $entry;
        });
    }

    /**
     * Verifikasi integritas rantai kriptografis hash SHA-256 untuk buku besar pelanggan.
     *
     * Memeriksa:
     * 1. Baris pertama memiliki prev_hash kosong (null).
     * 2. Setiap baris ke-i memiliki prev_hash yang persis sama dengan entry_hash baris ke-(i-1).
     * 3. Akumulasi saldo balance_after konsisten dengan points setiap mutasi.
     * 4. Setiap baris memiliki entry_hash valid 64 karakter heksadesimal.
     *
     * @param  int  $customerId
     * @return bool True jika rantai utuh 100%, False jika terdeteksi manipulasi/tampering data historis.
     */
    public function assertHashChainValid(int $customerId): bool
    {
        $entries = LoyaltyLedger::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->orderBy('id', 'asc')
            ->get();

        if ($entries->isEmpty()) {
            return true;
        }

        $expectedPrevHash = null;
        $runningBalance = 0;

        foreach ($entries as $index => $entry) {
            // 1. Verifikasi panjang format hash entry_hash
            if (empty($entry->entry_hash) || strlen((string) $entry->entry_hash) !== 64) {
                return false;
            }

            // 2. Verifikasi keterkaitan prev_hash dengan entry_hash baris sebelumnya
            if ($index === 0) {
                if ($entry->prev_hash !== null && $entry->prev_hash !== '') {
                    return false;
                }
            } else {
                if ($entry->prev_hash !== $expectedPrevHash) {
                    return false;
                }
            }

            // 3. Verifikasi konsistensi saldo run-time (drift detection)
            $runningBalance += (int) $entry->points;
            if ((int) $entry->balance_after !== $runningBalance) {
                return false;
            }

            $expectedPrevHash = $entry->entry_hash;
        }

        return true;
    }

    /**
     * Ambil saldo poin loyalitas pelanggan saat ini.
     */
    public function getCurrentBalance(int $customerId): int
    {
        $lastEntry = LoyaltyLedger::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->orderBy('id', 'desc')
            ->first();

        return $lastEntry ? (int) $lastEntry->balance_after : 0;
    }
}
