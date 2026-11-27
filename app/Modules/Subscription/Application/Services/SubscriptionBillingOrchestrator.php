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
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        $normalizedCycle = strtolower($billingCycle) === 'annual' ? 'annual' : 'monthly';
        $cycleMultiplier = $normalizedCycle === 'annual' ? 12 : 1;
        $planPrice = Money::fromDecimal((string) $targetPlan->price, 'IDR');
        $totalAmount = $cycleMultiplier > 1 ? $planPrice->multiply($cycleMultiplier) : $planPrice;

        $orderCode = 'SUB-'.$tenantId.'-'.$targetPlan->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        $idempotencyKey = 'upgrade:'.$tenantId.':'.$targetPlan->id.':'.now()->timestamp.':'.Str::random(6);
        $fingerprint = hash('sha256', 'upgrade|'.$tenantId.'|'.$targetPlan->id.'|'.$normalizedCycle.'|'.$userEmail);

        $orderRecord = ProvisioningRequest::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $userEmail,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'result_tenant_id' => $tenantId,
            'result_user_id' => $user?->id,
            'plan_id' => $targetPlan->id,
            'purchased_billing_cycle' => $normalizedCycle,
            'expected_amount' => (string) $totalAmount->toDecimal(),
            'notes' => "Self-serve upgrade ke {$targetPlan->name} ({$normalizedCycle}) - Order {$orderCode}",
            'correlation_id' => $orderCode,
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        if ($this->paymentGateway === null) {
            $orderRecord->update([
                'status' => ProvisioningRequest::STATUS_FAILED,
                'error_message' => 'Payment gateway service is not configured.',
            ]);

            throw new HttpException(503, 'Payment gateway is currently unavailable. Please try again later.');
        }

        $paymentToken = null;
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
            Log::error('Payment gateway error during subscription upgrade token generation.', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'order_id' => $orderCode,
            ]);

            if (! app()->environment('local')) {
                $orderRecord->update([
                    'status' => ProvisioningRequest::STATUS_FAILED,
                    'error_message' => 'Midtrans gateway error: '.$e->getMessage(),
                ]);

                throw new HttpException(502, 'Unable to initialize Midtrans payment session.', $e);
            }
        }

        if ($paymentToken === null || empty($paymentToken)) {
            $orderRecord->update([
                'status' => ProvisioningRequest::STATUS_FAILED,
                'error_message' => 'Midtrans gateway returned empty snap token.',
            ]);

            throw new HttpException(502, 'Payment gateway returned an empty checkout token.');
        }

        return [
            'order_id' => $orderCode,
            'payment_token' => $paymentToken,
            'amount' => $totalAmount,
            'amount_formatted' => $totalAmount->format(),
            'plan_id' => $targetPlan->id,
            'plan_name' => $targetPlan->name,
            'billing_cycle' => $normalizedCycle,
            'provisioning_request_id' => $orderRecord->id,
        ];
    }

    /**
     * Atomically activates a paid subscription upon verified payment settlement.
     * Updates TenantSubscription under pessimistic lock, refreshes tenant entitlement cache, and writes an immutable audit entry.
     */
    public function activatePaidSubscription(
        int $tenantId,
        int $planId,
        string $paymentReference,
        ?string $billingCycle = null
    ): TenantSubscription {
        return DB::transaction(function () use ($tenantId, $planId, $paymentReference, $billingCycle) {
            $targetPlan = Plan::findOrFail($planId);
            $tenant = \App\Modules\Organization\Models\Tenant::where('id', $tenantId)->lockForUpdate()->firstOrFail();

            $matchingRequest = ProvisioningRequest::where('correlation_id', $paymentReference)
                ->lockForUpdate()
                ->first();

            $subRow = DB::table('tenant_subscriptions')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($subRow !== null) {
                $existingMetadata = json_decode((string) ($subRow->metadata ?? '{}'), true) ?: [];
                if (($existingMetadata['payment_reference'] ?? null) === $paymentReference && (int) $subRow->plan_id === $targetPlan->id) {
                    return TenantSubscription::findOrFail($subRow->id)->fresh(['plan']);
                }
            }

            $requestedCycle = $matchingRequest !== null ? $matchingRequest->purchased_billing_cycle : null;
            $resolvedCycle = strtolower((string) ($billingCycle ?? $requestedCycle ?? $targetPlan->billing_cycle ?? 'monthly'));
            $normalizedCycle = $resolvedCycle === 'annual' ? 'annual' : 'monthly';
            $cycleMonths = $normalizedCycle === 'annual' ? 12 : 1;
            $startsAt = now();
            $endsAt = now()->addMonths($cycleMonths);

            if ($subRow !== null) {
                $existingMetadata = json_decode((string) ($subRow->metadata ?? '{}'), true) ?: [];
                $updatedMetadata = array_merge($existingMetadata, [
                    'payment_reference' => $paymentReference,
                    'activated_at' => now()->toIso8601String(),
                    'billing_cycle' => $normalizedCycle,
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

                $subscription = TenantSubscription::findOrFail($subRow->id);
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
                        'billing_cycle' => $normalizedCycle,
                    ],
                ]);
            }

            // Sync tenant subscription plan code
            $tenant->update(['subscription_plan' => $targetPlan->code]);

            // Complete matching upgrade provisioning request (and any stale pending upgrade for the same plan)
            if ($matchingRequest !== null && $matchingRequest->status === ProvisioningRequest::STATUS_PENDING) {
                $matchingRequest->update([
                    'status' => ProvisioningRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            ProvisioningRequest::where('result_tenant_id', $tenantId)
                ->where('plan_id', $targetPlan->id)
                ->where('status', ProvisioningRequest::STATUS_PENDING)
                ->lockForUpdate()
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
                    'billing_cycle' => $normalizedCycle,
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
        return DB::transaction(function () use ($orderId, $transactionStatus, $fraudStatus, $rawPayload): bool {
            // Determine whether transaction status indicates a settled payment
            $isSettled = ($transactionStatus === 'settlement')
                || ($transactionStatus === 'capture' && ($fraudStatus === 'accept' || $fraudStatus === null));

            // Locate & lock provisioning request record
            $orderRecord = ProvisioningRequest::where('correlation_id', $orderId)->lockForUpdate()->first();
            if ($orderRecord === null) {
                $orderRecord = ProvisioningRequest::where('notes', 'like', "%{$orderId}%")->lockForUpdate()->first();
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

            // Strict Idempotency Check 2 (locked): TenantSubscription already activated with this payment reference
            $existingSub = TenantSubscription::where('tenant_id', $tenantId)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existingSub !== null) {
                $metadata = $existingSub->metadata ?? [];

                if (($metadata['payment_reference'] ?? null) === $orderId) {
                    Log::info("[SUBSCRIPTION WEBHOOK] Tenant {$tenantId} subscription already activated for reference {$orderId}. Idempotent skip.");

                    return true;
                }
            }

            // If payment is settled, activate the subscription atomically
            if ($isSettled) {
                $this->activatePaidSubscription(
                    (int) $tenantId,
                    (int) $planId,
                    $orderId,
                    $orderRecord->purchased_billing_cycle
                );

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
        });
    }
}
