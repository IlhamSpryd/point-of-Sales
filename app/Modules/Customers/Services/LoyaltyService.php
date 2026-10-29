<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Customer;
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
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = $account ? (int) $account->current_points : 0;
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
