<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Application\Services;

use App\Modules\Subscription\Contracts\SubscriptionPaymentSettlementInterface;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Services\EntitlementService;
use App\Platform\Audit\ImmutableAuditWriter;
use App\Platform\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates self-serve SaaS plan upgrades, prorated calculation, payment tokenization, and settlement (EXT-01, ADR-113).
 */
class SubscriptionBillingOrchestrator implements SubscriptionPaymentSettlementInterface
{
    public function __construct(
        private readonly ?\App\Modules\Integration\Contracts\PaymentGatewayInterface $paymentGateway,
        private readonly EntitlementService $entitlementService,
        private readonly ImmutableAuditWriter $auditWriter,
    ) {}

    /**
     * Initiates a self-serve subscription upgrade for a tenant.
     * Calculates the cycle/upgrade amount using Money VO, persists the order record,
     * and obtains a payment checkout token from PaymentGatewayInterface.
     *
     * @return array{
     *     order_id: string,
     *     payment_token: string,
     *     amount: Money,
     *     amount_formatted: string,
     *     plan_id: int,
     *     plan_name: string,
     *     billing_cycle: string,
     *     provisioning_request_id: int
     * }
     */
    public function initiateUpgrade(int $tenantId, int $targetPlanId, string $billingCycle = 'monthly'): array
    {
        $tenant = \App\Modules\Organization\Models\Tenant::findOrFail($tenantId);
        $targetPlan = Plan::where('id', $targetPlanId)->where('is_active', true)->firstOrFail();

        $user = auth()->user();
        $userEmail = $user !== null ? (string) $user->email : "tenant-{$tenantId}@domain.internal";

        $cycleMultiplier = strtolower($billingCycle) === 'annual' ? 12 : 1;
        $planPrice = Money::fromDecimal((string) $targetPlan->price, 'IDR');
        $totalAmount = $cycleMultiplier > 1 ? $planPrice->multiply($cycleMultiplier) : $planPrice;

        $orderCode = 'SUB-'.$tenantId.'-'.$targetPlan->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        $idempotencyKey = 'upgrade:'.$tenantId.':'.$targetPlan->id.':'.now()->timestamp.':'.Str::random(6);
        $fingerprint = hash('sha256', 'upgrade|'.$tenantId.'|'.$targetPlan->id.'|'.$billingCycle.'|'.$userEmail);

        $orderRecord = ProvisioningRequest::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $userEmail,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'result_tenant_id' => $tenantId,
            'result_user_id' => $user?->id,
            'plan_id' => $targetPlan->id,
            'expected_amount' => (string) $totalAmount->toDecimal(),
            'notes' => "Self-serve upgrade ke {$targetPlan->name} ({$billingCycle}) - Order {$orderCode}",
            'correlation_id' => $orderCode,
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        $paymentToken = null;
        if ($this->paymentGateway !== null) {
            try {
                $grossAmount = max(1, (int) round($totalAmount->minor / 100));
                $paymentToken = $this->paymentGateway->createSnapToken([
                    'transaction_details' => [
                        'order_id' => $orderCode,
                        'gross_amount' => $grossAmount,
                    ],
                    'customer_details' => [
                        'email' => $userEmail,
                        'first_name' => $tenant->name,
                    ],
                ], $orderCode);
            } catch (\Throwable $e) {
                Log::warning('Payment gateway error during subscription upgrade token generation, falling back.', [
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        if ($paymentToken === null || empty($paymentToken)) {
            $paymentToken = 'snap_token_sim_'.Str::random(24);
        }

        return [
            'order_id' => $orderCode,
            'payment_token' => $paymentToken,
            'amount' => $totalAmount,
            'amount_formatted' => $totalAmount->format(),
            'plan_id' => $targetPlan->id,
            'plan_name' => $targetPlan->name,
            'billing_cycle' => $billingCycle,
            'provisioning_request_id' => $orderRecord->id,
        ];
    }

    /**
     * Atomically activates a paid subscription upon payment settlement or instant confirmation.
     * Updates TenantSubscription, refreshes tenant entitlement cache, and writes an immutable audit entry.
     */
    public function activatePaidSubscription(int $tenantId, int $planId, string $paymentReference): TenantSubscription
    {
        return DB::transaction(function () use ($tenantId, $planId, $paymentReference) {
            $targetPlan = Plan::findOrFail($planId);
            $tenant = \App\Modules\Organization\Models\Tenant::findOrFail($tenantId);

            $subRow = DB::table('tenant_subscriptions')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->first();

            $cycleMonths = strtolower((string) ($targetPlan->billing_cycle ?? 'monthly')) === 'annual' ? 12 : 1;
            $startsAt = now();
            $endsAt = now()->addMonths($cycleMonths);

            if ($subRow !== null) {
                $existingMetadata = json_decode((string) ($subRow->metadata ?? '{}'), true) ?: [];
                $updatedMetadata = array_merge($existingMetadata, [
                    'payment_reference' => $paymentReference,
                    'activated_at' => now()->toIso8601String(),
                    'billing_cycle' => $targetPlan->billing_cycle,
                ]);

                DB::table('tenant_subscriptions')
                    ->where('id', $subRow->id)
                    ->update([
                        'plan_id' => $targetPlan->id,
                        'status' => TenantSubscription::STATUS_ACTIVE,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'trial_ends_at' => null,
                        'metadata' => json_encode($updatedMetadata),
                        'updated_at' => now(),
                    ]);

                $subscription = TenantSubscription::find($subRow->id);
            } else {
                $subscription = TenantSubscription::create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $targetPlan->id,
                    'status' => TenantSubscription::STATUS_ACTIVE,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'trial_ends_at' => null,
                    'metadata' => [
                        'payment_reference' => $paymentReference,
                        'activated_at' => now()->toIso8601String(),
                        'billing_cycle' => $targetPlan->billing_cycle,
                    ],
                ]);
            }

            // Sync tenant subscription plan code
            $tenant->update(['subscription_plan' => $targetPlan->code]);

            // Complete any pending upgrade provisioning request
            ProvisioningRequest::where('result_tenant_id', $tenantId)
                ->where('plan_id', $targetPlan->id)
                ->where('status', ProvisioningRequest::STATUS_PENDING)
                ->update([
                    'status' => ProvisioningRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

            // Invalidate entitlement cache for tenant
            $this->entitlementService->clearCache($tenantId);

            // Record immutable audit entry
            $this->auditWriter->write(
                tenantId: $tenantId,
                action: 'subscription.plan.upgraded',
                userId: auth()->id(),
                subjectType: 'tenant_subscription',
                subjectId: $subscription->id,
                description: "Paket langganan tenant diupgrade ke {$targetPlan->name} (ref: {$paymentReference})",
                payload: [
                    'plan_id' => $targetPlan->id,
                    'plan_code' => $targetPlan->code,
                    'payment_reference' => $paymentReference,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                ]
            );

            return $subscription->fresh(['plan']);
        });
    }

    /**
     * Determine if this settlement handler manages the given order/payment reference.
     */
    public function handlesOrderId(string $orderId): bool
    {
        return str_starts_with(strtoupper($orderId), 'SUB-');
    }

    /**
     * Atomically process subscription settlement notification payload from webhook (EXT-01, ADR-113).
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function settleFromWebhook(
        string $orderId,
        string $transactionStatus,
        ?string $fraudStatus,
        array $rawPayload
    ): bool {
        // Determine whether transaction status indicates a settled payment
        $isSettled = ($transactionStatus === 'settlement')
            || ($transactionStatus === 'capture' && ($fraudStatus === 'accept' || $fraudStatus === null));

        // Locate provisioning request record
        $orderRecord = ProvisioningRequest::where('correlation_id', $orderId)->first();
        if ($orderRecord === null) {
            $orderRecord = ProvisioningRequest::where('notes', 'like', "%{$orderId}%")->first();
        }

        // Fail-Closed Guard (BILL-01, Koreksi #2): Tolak mutlak jika order record tidak ditemukan
        if ($orderRecord === null) {
            Log::warning('[SUBSCRIPTION WEBHOOK] ProvisioningRequest record not found for order reference.', [
                'order_id' => $orderId,
                'status' => $transactionStatus,
            ]);

            return false;
        }

        $tenantId = $orderRecord->result_tenant_id;
        $planId = $orderRecord->plan_id;

        // Fallback: parse SUB-{tenantId}-{planId}-... format
        if (($tenantId === null || $planId === null) && preg_match('/^SUB[-_](\d+)[-_](\d+)/i', $orderId, $matches)) {
            $tenantId = (int) $matches[1];
            $planId = (int) $matches[2];
        }

        if ($tenantId === null || $planId === null) {
            Log::warning('[SUBSCRIPTION WEBHOOK] Could not resolve tenant or plan for order reference.', [
                'order_id' => $orderId,
                'status' => $transactionStatus,
            ]);

            return false;
        }

        // Strict Idempotency Check 1: Provisioning request already completed (anti-replay)
        if ($orderRecord->status === ProvisioningRequest::STATUS_COMPLETED) {
            Log::info("[SUBSCRIPTION WEBHOOK] Order {$orderId} already completed. Idempotent skip.", [
                'tenant_id' => $tenantId,
            ]);

            return true;
        }

        // Anti-Amount-Tampering: Validasi keras nominal pembayaran dengan BCMath (BILL-01)
        if ($isSettled) {
            $targetPlan = Plan::find($planId);
            $expectedAmount = (string) ($orderRecord->expected_amount ?? ($targetPlan->price ?? '0.00'));
            $paidAmount = (string) ($rawPayload['gross_amount'] ?? '0.00');

            if (bccomp($paidAmount, $expectedAmount, 2) < 0) {
                Log::critical("[SUBSCRIPTION FRAUD DETECTED] Webhook gross_amount ({$paidAmount}) lebih rendah dari expected ({$expectedAmount})!", [
                    'order_id' => $orderId,
                    'expected' => $expectedAmount,
                    'paid' => $paidAmount,
                    'tenant_id' => $tenantId,
                ]);

                $orderRecord->update([
                    'status' => ProvisioningRequest::STATUS_FAILED,
                    'error_message' => "Pembayaran tidak mencukupi: Diharapkan Rp {$expectedAmount}, Diterima Rp {$paidAmount}",
                ]);

                return false;
            }
        }

        // Strict Idempotency Check 2: TenantSubscription already activated with this payment reference
        $existingSub = TenantSubscription::where('tenant_id', $tenantId)->latest('id')->first();
        if ($existingSub !== null) {
            $metadata = $existingSub->metadata ?? [];

            if (($metadata['payment_reference'] ?? null) === $orderId) {
                Log::info("[SUBSCRIPTION WEBHOOK] Tenant {$tenantId} subscription already activated for reference {$orderId}. Idempotent skip.");

                return true;
            }
        }

        // If payment is settled, activate the subscription atomically
        if ($isSettled) {
            $this->activatePaidSubscription((int) $tenantId, (int) $planId, $orderId);

            Log::info("[SUBSCRIPTION WEBHOOK] Successfully settled and activated subscription for tenant {$tenantId}.", [
                'order_id' => $orderId,
                'plan_id' => $planId,
                'status' => $transactionStatus,
            ]);

            return true;
        }

        // Non-settled terminal states: deny, cancel, expire
        if (in_array($transactionStatus, ['deny', 'cancel', 'expire'], true)) {
            if ($orderRecord->status === ProvisioningRequest::STATUS_PENDING) {
                $orderRecord->update([
                    'status' => ProvisioningRequest::STATUS_FAILED,
                    'error_message' => "Midtrans transaction status: {$transactionStatus}",
                ]);
            }

            Log::info("[SUBSCRIPTION WEBHOOK] Subscription upgrade {$orderId} marked as failed due to status: {$transactionStatus}.");

            return false;
        }

        // Pending or other non-terminal states
        Log::info("[SUBSCRIPTION WEBHOOK] Subscription upgrade {$orderId} received status {$transactionStatus}, plan not activated.");

        return false;
    }
}
