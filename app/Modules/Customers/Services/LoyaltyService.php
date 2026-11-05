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
     * Resolusi dan validasi konsistensi tenant ID secara Fail-Closed (Batch 1A).
     *
     * @throws \RuntimeException
     */
    protected function resolveAndValidateTenantId(Customer $customer): int
    {
        $contextTenantId = $this->tenantContext->getTenantId();
        $rawCustomerTenant = $customer->getAttribute('tenant_id');
        $customerTenantId = $rawCustomerTenant !== null ? (int) $rawCustomerTenant : null;

        // 1. Validasi tenant ID pelanggan jika ada
        if ($customerTenantId !== null && $customerTenantId <= 0) {
            throw new \RuntimeException('Tenant ID pelanggan tidak valid.');
        }

        // 2. Dapatkan authoritative tenant ID dari context server-side atau requireTenantId()
        if ($contextTenantId === null || $contextTenantId <= 0) {
            $contextTenantId = $this->tenantContext->requireTenantId();
        }

        if ($contextTenantId <= 0) {
            throw new \RuntimeException('Konteks tenant yang valid wajib tersedia untuk kalkulasi poin loyalitas.');
        }

        // 3. Pastikan customer belongs to the active tenant context (fail-closed jika konflik)
        if ($customerTenantId !== null && $customerTenantId !== $contextTenantId) {
            throw new \RuntimeException("Konflik isolasi tenant: konteks tenant ({$contextTenantId}) tidak sesuai dengan tenant pelanggan ({$customerTenantId}).");
        }

        return $contextTenantId;
    }

    /**
     * Mengonversi input numerik menjadi numeric-string aman bagi BCMath.
     *
     * @return numeric-string
     */
    private function toNumericString(string|int|float $value, int $scale = 4): string
    {
        $raw = is_string($value) && is_numeric($value) ? $value : (string) $value;
        /** @var numeric-string $safeRaw */
        $safeRaw = is_numeric($raw) ? $raw : '0';

        return bcadd($safeRaw, '0', $scale);
    }

    /**
     * Hitung perolehan poin (earn) murni dari DPP berbasis BCMath:
     * EarnPoints = floor(DPP / 10000) * TierMultiplier
     */
    public function calculateEarnablePoints(Customer $customer, string|int|float $dpp): int
    {
        $dppStr = $this->toNumericString($dpp, 4);
        if (bccomp($dppStr, '0.0000', 4) <= 0) {
            return 0;
        }

        $tenantId = $this->resolveAndValidateTenantId($customer);

        $account = DB::table('customer_loyalty_accounts')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        $multiplier = '1.0000';
        if ($account && $account->current_tier_id) {
            $tier = DB::table('loyalty_tiers')
                ->where('tenant_id', $tenantId)
                ->where('id', $account->current_tier_id)
                ->where('is_active', true)
                ->first();
            if ($tier && is_numeric((string) $tier->points_multiplier)) {
                $multiplier = $this->toNumericString((string) $tier->points_multiplier, 4);
            }
        } else {
            $baseTier = DB::table('loyalty_tiers')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('min_points', 0)
                ->first();
            if ($baseTier && is_numeric((string) $baseTier->points_multiplier)) {
                $multiplier = $this->toNumericString((string) $baseTier->points_multiplier, 4);
            }
        }

        $dppIntPart = (int) explode('.', $dppStr)[0];
        $baseUnits = intdiv($dppIntPart, 10000);
        if ($baseUnits <= 0) {
            return 0;
        }

        $baseUnitsStr = (string) $baseUnits;
        $rawPoints = bcmul($baseUnitsStr, $multiplier, 4);
        $earned = (int) explode('.', $rawPoints)[0];

        return max(0, $earned);
    }

    /**
     * Konversi poin loyalitas ke nilai rupiah diskon (1 Poin = Rp100) dalam numeric-string.
     *
     * @return numeric-string
     */
    public function calculateRedeemValue(int $points): string
    {
        if ($points <= 0) {
            return '0.00';
        }

        $pointsStr = (string) $points;

        return bcmul($pointsStr, '100', 2);
    }

    /**
     * Konversi nilai rupiah diskon ke kebutuhan poin (1 Poin = Rp100, dibulatkan ke atas) menggunakan BCMath.
     */
    public function calculatePointsForDiscount(string|int|float $discountAmount): int
    {
        $discStr = $this->toNumericString($discountAmount, 4);
        if (bccomp($discStr, '0.0000', 4) <= 0) {
            return 0;
        }

        $rawQuotient = bcdiv($discStr, '100', 6);
        $parts = explode('.', $rawQuotient);
        $quotient = (int) $parts[0];
        if (isset($parts[1]) && rtrim($parts[1], '0') !== '') {
            $quotient++;
        }

        return $quotient;
    }

    /**
     * Evaluasi akumulasi belanja seumur hidup (lifetime_points_earned) terhadap
     * loyalty_tiers.min_points dan perbarui current_tier_id jika memenuhi syarat.
     */
    public function checkAndUpgradeTier(Customer $customer, ?int $pointsOrSpend = null): ?LoyaltyTier
    {
        $tenantId = $this->resolveAndValidateTenantId($customer);

        $account = DB::table('customer_loyalty_accounts')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $account) {
            return null;
        }

        $metric = $pointsOrSpend !== null ? $pointsOrSpend : (int) $account->lifetime_points_earned;

        $eligibleTier = LoyaltyTier::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('min_points', '<=', $metric)
            ->orderByDesc('min_points')
            ->first();

        if ($eligibleTier && (int) $account->current_tier_id !== (int) $eligibleTier->id) {
            DB::table('customer_loyalty_accounts')
                ->where('tenant_id', $tenantId)
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
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
     *
     * @return array<string, mixed>
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
        $tenantId = $this->resolveAndValidateTenantId($customer);

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
                ->where('tenant_id', $tenantId)
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
        $tenantId = $this->resolveAndValidateTenantId($customer);

        $account = DB::table('customer_loyalty_accounts')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        return $account ? (int) $account->current_points : 0;
    }
}
