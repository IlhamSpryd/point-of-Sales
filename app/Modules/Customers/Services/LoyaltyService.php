<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Customer;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Hitung perolehan poin (earn) murni dari DPP:
     * EarnPoints = floor(DPP / 10000) * TierMultiplier
     */
    public function calculateEarnablePoints(Customer $customer, float $dpp): int
    {
        if ($dpp <= 0) {
            return 0;
        }

        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->getTenantId());

        $account = DB::table('customer_loyalty_accounts')
            ->where('customer_id', $customer->id)
            ->first();

        $multiplier = 1.00;
        if ($account && $account->current_tier_id) {
            $tier = DB::table('loyalty_tiers')
                ->where('id', $account->current_tier_id)
                ->where('is_active', true)
                ->first();
            if ($tier) {
                $multiplier = (float) $tier->points_multiplier;
            }
        } elseif ($tenantId > 0) {
            $baseTier = DB::table('loyalty_tiers')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('min_points', 0)
                ->first();
            if ($baseTier) {
                $multiplier = (float) $baseTier->points_multiplier;
            }
        }

        $baseUnits = floor($dpp / 10000);
        $earned = (int) floor($baseUnits * $multiplier);

        return max(0, $earned);
    }

    /**
     * Konversi poin loyalitas ke nilai rupiah diskon (1 Poin = Rp100).
     */
    public function calculateRedeemValue(int $points): float
    {
        if ($points <= 0) {
            return 0.0;
        }

        return (float) bcmul((string) $points, '100', 2);
    }

    /**
     * Konversi nilai rupiah diskon ke kebutuhan poin (1 Poin = Rp100).
     */
    public function calculatePointsForDiscount(float $discountAmount): int
    {
        if ($discountAmount <= 0) {
            return 0;
        }

        return (int) ceil($discountAmount / 100);
    }

    /**
     * Evaluasi akumulasi belanja seumur hidup (lifetime_points_earned) terhadap
     * loyalty_tiers.min_points dan perbarui current_tier_id jika memenuhi syarat.
     */
    public function checkAndUpgradeTier(Customer $customer, ?int $pointsOrSpend = null): ?LoyaltyTier
    {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->getTenantId());

        $account = DB::table('customer_loyalty_accounts')
            ->where('customer_id', $customer->id)
            ->first();

        if (! $account) {
            return null;
        }

        $metric = $pointsOrSpend !== null ? $pointsOrSpend : (int) $account->lifetime_points_earned;

        $query = LoyaltyTier::where('is_active', true)
            ->where('min_points', '<=', $metric)
            ->orderByDesc('min_points');

        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        $eligibleTier = $query->first();

        if (! $eligibleTier && $tenantId > 0) {
            // Fallback: search global active tiers
            $eligibleTier = LoyaltyTier::where('is_active', true)
                ->where('min_points', '<=', $metric)
                ->orderByDesc('min_points')
                ->first();
        }

        if ($eligibleTier && (int) $account->current_tier_id !== (int) $eligibleTier->id) {
            DB::table('customer_loyalty_accounts')
                ->where('id', $account->id)
                ->update([
                    'current_tier_id' => $eligibleTier->id,
                    'updated_at' => Carbon::now(),
                ]);
        }

        return $eligibleTier;
    }

    /**
     * Berikan poin loyalitas (Earn) kepada pelanggan atas transaksi.
     *
     * @return array{customer_id: int, points_earned: int, balance_after: int, ledger_id: int}
     */
    public function earnPoints(
        Customer $customer,
        int|string $points,
        ?int $orderId = null,
        ?string $reference = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $earnedPoints = (int) $points;
        if ($earnedPoints <= 0) {
            throw new DomainException('Jumlah poin yang diperoleh harus lebih besar dari 0.');
        }

        return $this->recordLoyaltyMutation(
            customer: $customer,
            type: 'earn',
            pointsDelta: $earnedPoints,
            orderId: $orderId,
            reference: $reference,
            actor: $actor,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Tukarkan poin loyalitas (Redeem) untuk diskon / reward.
     *
     * @return array{customer_id: int, points_redeemed: int, balance_after: int, ledger_id: int}
     */
    public function redeemPoints(
        Customer $customer,
        int|string $points,
        ?int $orderId = null,
        ?string $reference = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $redeemPoints = (int) $points;
        if ($redeemPoints <= 0) {
            throw new DomainException('Jumlah poin yang ditukarkan harus lebih besar dari 0.');
        }

        return $this->recordLoyaltyMutation(
            customer: $customer,
            type: 'redeem',
            pointsDelta: -$redeemPoints,
            orderId: $orderId,
            reference: $reference,
            actor: $actor,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Reversal pemotongan poin akibat pembatalan atau retur penjualan.
     *
     * @return array{customer_id: int, points_reversed: int, balance_after: int, ledger_id: int}
     */
    public function reversePoints(
        Customer $customer,
        int|string $points,
        ?int $orderId = null,
        string $reason = '',
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $reversePoints = (int) $points;
        if ($reversePoints <= 0) {
            throw new DomainException('Jumlah poin yang dibalikkan harus lebih besar dari 0.');
        }

        return $this->recordLoyaltyMutation(
            customer: $customer,
            type: 'reversal',
            pointsDelta: -$reversePoints, // Memotong kembali poin yang sempat diperoleh
            orderId: $orderId,
            reference: $reason ?: 'Reversal transaksi retur',
            actor: $actor,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Penyesuaian manual (Adjustment / Bonus / Expiry) oleh user berwenang.
     */
    public function adjustPoints(
        Customer $customer,
        int|string $deltaPoints,
        string $type = 'adjustment',
        string $reason = '',
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): array {
        $delta = (int) $deltaPoints;
        if ($delta === 0) {
            throw new DomainException('Delta poin penyesuaian tidak boleh 0.');
        }

        if (! in_array($type, ['adjustment', 'bonus', 'expire', 'reversal'], true)) {
            throw new DomainException("Tipe penyesuaian poin tidak valid: {$type}");
        }

        return $this->recordLoyaltyMutation(
            customer: $customer,
            type: $type,
            pointsDelta: $delta,
            orderId: null,
            reference: $reason ?: 'Manual adjustment',
            actor: $actor,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Eksekusi mutasi buku besar poin loyalitas dengan penguncian deterministik A0/A8
     * dan koeksistensi trigger DB trg_loyalty_ledger_sync_account.
     */
    protected function recordLoyaltyMutation(
        Customer $customer,
        string $type,
        int $pointsDelta,
        ?int $orderId,
        ?string $reference,
        ?User $actor,
        ?string $idempotencyKey
    ): array {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());

        return DB::transaction(function () use (
            $tenantId,
            $customer,
            $type,
            $pointsDelta,
            $orderId,
            $reference,
            $actor,
            $idempotencyKey
        ) {
            // 1. Kunci baris akun loyalitas customer (SELECT ... FOR UPDATE)
            $account = DB::table('customer_loyalty_accounts')
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = $account ? (int) $account->current_points : 0;

            if ($type === 'reversal' && ($currentBalance + $pointsDelta) < 0) {
                // Jangan sampai saldo poin menjadi negatif akibat refund berlebih
                $pointsDelta = -$currentBalance;
            }

            $newBalance = $currentBalance + $pointsDelta;

            if ($newBalance < 0) {
                throw new DomainException("Saldo poin loyalitas tidak mencukupi. Saldo saat ini: {$currentBalance}, mutasi: {$pointsDelta}.");
            }

            // 2. Cek idempotency key jika diberikan
            if ($idempotencyKey) {
                $existing = DB::table('loyalty_ledger')
                    ->where('tenant_id', $tenantId)
                    ->where('reference', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'customer_id' => (int) $existing->customer_id,
                        'points_delta' => (int) $existing->points,
                        'balance_after' => (int) $existing->balance_after,
                        'ledger_id' => (int) $existing->id,
                        'idempotent_replay' => true,
                    ];
                }
            }

            // 3. Catat mutasi pada loyalty_ledger (Append-Only)
            // Trigger trg_loyalty_ledger_hash_chain akan otomatis mengisi prev_hash dan entry_hash!
            // Trigger trg_loyalty_ledger_sync_account akan otomatis memperbarui customer_loyalty_accounts!
            $ledgerId = DB::table('loyalty_ledger')->insertGetId([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'order_id' => $orderId,
                'type' => $type,
                'points' => $pointsDelta,
                'balance_after' => $newBalance,
                'reference' => $idempotencyKey ?: $reference,
                'created_by' => $actor?->id,
                'created_at' => Carbon::now(),
            ]);

            DB::table('customer_loyalty_accounts')
                ->where('customer_id', $customer->id)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);

            return [
                'customer_id' => (int) $customer->id,
                'points_delta' => $pointsDelta,
                'balance_after' => $newBalance,
                'ledger_id' => (int) $ledgerId,
                'idempotent_replay' => false,
            ];
        });
    }

    /**
     * Dapatkan saldo poin saat ini untuk pelanggan.
     */
    public function getCurrentPoints(Customer $customer): int
    {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());

        $account = DB::table('customer_loyalty_accounts')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        return $account ? (int) $account->current_points : 0;
    }
}
