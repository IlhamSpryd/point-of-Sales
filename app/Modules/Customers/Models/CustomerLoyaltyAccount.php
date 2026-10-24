<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Enums\LoyaltyLedgerTypeEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $customer_id
 * @property int $current_points
 * @property int $lifetime_points_earned
 * @property int|null $current_tier_id
 */
class CustomerLoyaltyAccount extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $guarded = ['id'];

    /**
     * [D2-BE-009] Kontrak trigger `trg_loyalty_ledger_sync_account`:
     * satu-satunya penulis kolom saldo (current_points / lifetime_points_earned)
     * adalah trigger DB yang bereaksi pada INSERT `loyalty_ledger`.
     * Mutasi manual dari PHP akan menyebabkan double-counting, jadi ditolak.
     */
    protected static function booted(): void
    {
        static::updating(function (self $account) {
            if ($account->isDirty('current_points') || $account->isDirty('lifetime_points_earned')) {
                throw new \RuntimeException(
                    'Saldo poin loyalitas hanya boleh diubah oleh trigger trg_loyalty_ledger_sync_account '
                    .'melalui penulisan LoyaltyLedger. Mutasi manual ditolak (anti double-counting).'
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'current_tier_id');
    }

    public static function lockAndGetAccount(int $customerId): ?self
    {
        return self::where('customer_id', $customerId)->lockForUpdate()->first();
    }

    /**
     * [CONC-08 Mitigasi] Penukaran poin loyalitas yang aman dari race condition (INV-LDG-01).
     * Selalu mengunci baris CustomerLoyaltyAccount (FOR UPDATE), memeriksa kecukupan saldo,
     * lalu menyisipkan entri append-only ke LoyaltyLedger (dimutasi oleh trigger DB).
     */
    public static function redeemPoints(
        int $customerId,
        int $pointsToRedeem,
        ?int $orderId = null,
        ?string $reference = null,
        ?int $createdById = null,
        ?int $tenantId = null,
        ?int $storeId = null
    ): LoyaltyLedger {
        if ($pointsToRedeem <= 0) {
            throw new \InvalidArgumentException('Jumlah poin yang ditukarkan harus lebih dari nol.');
        }

        return DB::transaction(function () use (
            $customerId,
            $pointsToRedeem,
            $orderId,
            $reference,
            $createdById,
            $tenantId,
            $storeId
        ) {
            $account = self::lockAndGetAccount($customerId);

            if (! $account) {
                throw new \DomainException('Akun loyalitas pelanggan tidak ditemukan.');
            }

            $currentPoints = (int) $account->current_points;
            if ($currentPoints < $pointsToRedeem) {
                throw new \DomainException("Poin loyalitas tidak mencukupi. (Tersedia: {$currentPoints}, Dibutuhkan: {$pointsToRedeem})");
            }

            $resolvedTenantId = $tenantId ?? $account->tenant_id;
            $resolvedStoreId = $storeId ?? $account->store_id;

            $ledger = LoyaltyLedger::create([
                'tenant_id' => $resolvedTenantId,
                'store_id' => $resolvedStoreId,
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'type' => LoyaltyLedgerTypeEnum::Redeem,
                'points' => -$pointsToRedeem,
                'balance_after' => $currentPoints - $pointsToRedeem,
                'reference' => $reference ?? "Penukaran {$pointsToRedeem} poin loyalitas",
                'created_by' => $createdById,
            ]);

            $account->refresh();

            return $ledger;
        });
    }
}
