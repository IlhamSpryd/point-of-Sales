<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Pricing\Services\GiftCardService;
use App\Modules\Pricing\Services\StoreCreditService;
use App\Modules\Sales\Application\Handlers\OnlinePaymentAndWebhookHandler;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Handles payment leg parsing, tender execution, cash drawer movements, and pending order payments (ADR-108).
 */
class OrderPaymentTenderService
{
    public function __construct(
        protected OnlinePaymentAndWebhookHandler $onlinePaymentHandler
    ) {}

    /**
     * @param  array<int|string, mixed>  $rawLegs
     * @return array<int, array{method: PaymentMethod, amount: int, reference_number: ?string, idempotency_key: ?string, amount_tendered?: int, change_amount?: int}>
     */
    public function parsePaymentLegs(array $rawLegs): array
    {
        $parsed = [];

        foreach ($rawLegs as $i => $leg) {
            if (! is_array($leg)) {
                throw ValidationException::withMessages([
                    'payments' => 'Payment leg format must be an array.',
                    "payments.{$i}" => 'Payment leg format must be an array.',
                ]);
            }

            $methodRaw = $leg['payment_method'] ?? $leg['method'] ?? null;
            if ($methodRaw instanceof PaymentMethod) {
                $method = $methodRaw;
            } elseif (is_string($methodRaw)) {
                $method = PaymentMethod::tryFrom(strtolower(trim($methodRaw)));
            } else {
                $method = null;
            }

            if ($method === null) {
                throw ValidationException::withMessages([
                    'payments' => 'Invalid payment method: '.json_encode($methodRaw),
                    "payments.{$i}.payment_method" => 'Invalid payment method: '.json_encode($methodRaw),
                ]);
            }

            $amountRaw = $leg['amount'] ?? null;
            if (! is_numeric($amountRaw) || (int) $amountRaw <= 0) {
                throw ValidationException::withMessages([
                    'payments' => 'Payment leg amount must be greater than zero.',
                    "payments.{$i}.amount" => 'Payment leg amount must be greater than zero.',
                ]);
            }
            $amount = (int) $amountRaw;

            $tendered = isset($leg['amount_tendered']) && is_numeric($leg['amount_tendered']) ? (int) $leg['amount_tendered'] : $amount;
            $change = isset($leg['change_amount']) && is_numeric($leg['change_amount']) ? (int) $leg['change_amount'] : 0;

            if ($method === PaymentMethod::Cash && $tendered < $amount) {
                throw ValidationException::withMessages([
                    'payments' => 'Cash tendered (Rp'.number_format($tendered, 0, ',', '.').') cannot be less than cash applied (Rp'.number_format($amount, 0, ',', '.').').',
                    "payments.{$i}.amount_tendered" => 'Cash tendered (Rp'.number_format($tendered, 0, ',', '.').') cannot be less than cash applied (Rp'.number_format($amount, 0, ',', '.').').',
                ]);
            }

            $ref = isset($leg['reference_number'])
                ? trim((string) $leg['reference_number'])
                : (isset($leg['card_number']) ? trim((string) $leg['card_number']) : null);
            $ref = ($ref === '') ? null : $ref;

            $idem = isset($leg['idempotency_key']) ? trim((string) $leg['idempotency_key']) : null;
            $idem = ($idem === '') ? null : $idem;

            $parsed[] = array_merge($leg, [
                'method' => $method,
                'amount' => $amount,
                'amount_tendered' => $tendered,
                'change_amount' => $change,
                'reference_number' => $ref,
                'idempotency_key' => $idem,
            ]);
        }

        return $parsed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function processPaymentLegTenders(Order $order, array $legs, ?int $userId): void
    {
        foreach ($legs as $i => $leg) {
            $method = $leg['method'] instanceof PaymentMethod
                ? $leg['method']
                : PaymentMethod::from((string) $leg['method']);

            if ($method === PaymentMethod::GiftCard) {
                $cardNumber = $leg['card_number'] ?? $leg['reference_number'] ?? null;
                if (empty($cardNumber)) {
                    throw ValidationException::withMessages([
                        'payments' => 'Gift card number is required for gift card payments.',
                    ]);
                }
                app(GiftCardService::class)->redeemGiftCard(
                    cardNumber: (string) $cardNumber,
                    amount: (string) $leg['amount'],
                    storeId: (int) $order->store_id,
                    pin: $leg['pin'] ?? null,
                    referenceType: 'ORDER_PAYMENT',
                    referenceId: (int) $order->id,
                    actor: $userId ? \App\Modules\Organization\Models\User::find($userId) : Auth::user(),
                    idempotencyKey: $leg['idempotency_key'] ?? ('gc_redeem:'.$order->order_code.':'.$i)
                );
            } elseif ($method === PaymentMethod::StoreCredit) {
                if (empty($order->customer_id)) {
                    throw ValidationException::withMessages([
                        'payments' => 'A customer must be selected for store credit payments.',
                    ]);
                }
                $customer = \App\Modules\Customers\Models\Customer::find($order->customer_id);
                if (! $customer) {
                    throw ValidationException::withMessages([
                        'payments' => 'Customer not found for store credit payment.',
                    ]);
                }
                app(StoreCreditService::class)->redeemStoreCredit(
                    customer: $customer,
                    amount: (string) $leg['amount'],
                    entryType: \App\Modules\Pricing\Enums\StoreCreditLedgerTypeEnum::REDEEM_ORDER,
                    storeId: (int) $order->store_id,
                    referenceType: 'ORDER_PAYMENT',
                    referenceId: (int) $order->id,
                    actor: $userId ? \App\Modules\Organization\Models\User::find($userId) : Auth::user(),
                    idempotencyKey: $leg['idempotency_key'] ?? ('sc_redeem:'.$order->order_code.':'.$i)
                );
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $rawPaymentLegs
     */
    public function payPendingOrder(Order $order, array $rawPaymentLegs, ?int $userId = null, ?int $shiftId = null, ?int $deviceId = null): Order
    {
        return DB::transaction(function () use ($order, $rawPaymentLegs, $userId, $shiftId, $deviceId) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->order_status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'order' => 'Only Pending orders can be paid.',
                ]);
            }

            $currentCashierId = $userId ?? Auth::id() ?? $order->user_id;
            $currentShiftId = $shiftId ?? $order->shift_id;
            $currentDeviceId = $deviceId ?? $order->created_by_device_id;

            $parsedLegs = $this->parsePaymentLegs($rawPaymentLegs);
            $sumLegs = array_sum(array_column($parsedLegs, 'amount'));

            if ($sumLegs !== (int) $order->order_amount) {
                throw ValidationException::withMessages([
                    'payments' => 'Total payments (Rp'.number_format($sumLegs, 0, ',', '.')
                        .') does not match the order amount (Rp'.number_format((int) $order->order_amount, 0, ',', '.').').',
                ]);
            }

            $this->processPaymentLegTenders($order, $parsedLegs, $currentCashierId);

            $legsByAmountDesc = collect($parsedLegs)->sortByDesc('amount');
            /** @var array{method: PaymentMethod, amount: int, reference_number: string|null, idempotency_key: string|null} $dominantLeg */
            $dominantLeg = $legsByAmountDesc->first();
            $dominantPaymentMethod = $dominantLeg['method']->value;

            $rawCashLegs = collect($rawPaymentLegs)->filter(
                fn ($raw) => is_array($raw) && (($raw['payment_method'] ?? $raw['method'] ?? null) === 'cash' || ($raw['payment_method'] ?? $raw['method'] ?? null) === PaymentMethod::Cash)
            );
            $cashLegTotal = (int) collect($parsedLegs)->where('method', PaymentMethod::Cash)->sum('amount');
            $cashTenderedTotal = (int) $rawCashLegs->sum(fn (array $raw) => (int) ($raw['amount_tendered'] ?? $raw['amount'] ?? 0));
            $cashChangeTotal = (int) $rawCashLegs->sum(fn (array $raw) => (int) ($raw['change_amount'] ?? max(0, (int) ($raw['amount_tendered'] ?? $raw['amount'] ?? 0) - (int) ($raw['amount'] ?? 0))));
            $cashReceivedForReceipt = $cashTenderedTotal > 0 ? $cashTenderedTotal : ($cashLegTotal > 0 ? $cashLegTotal : null);
            $orderChangeForReceipt = $cashChangeTotal > 0 ? $cashChangeTotal : max(0, (int) ($cashReceivedForReceipt ?? 0) - $cashLegTotal);

            $order->forceFill([
                'order_status' => OrderStatus::Paid,
                'order_lifecycle_status' => OrderLifecycleStatus::Confirmed->value,
                'payment_status' => OrderPaymentStatus::Paid->value,
                'fulfillment_status' => OrderFulfillmentStatus::InPreparation->value,
                'payment_method' => $dominantPaymentMethod,
                'cash_received' => $cashReceivedForReceipt ?? $order->cash_received,
                'order_change' => $orderChangeForReceipt,
            ])->save();

            $order->orderItems()->update(['preparation_status' => 'pending']);

            foreach ($parsedLegs as $i => $leg) {
                Payment::create([
                    'tenant_id' => $order->tenant_id,
                    'store_id' => $order->store_id,
                    'device_id' => $currentDeviceId,
                    'order_id' => $order->id,
                    'shift_id' => $currentShiftId,
                    'payment_method' => $leg['method'],
                    'amount' => $leg['amount'],
                    'amount_tendered' => $leg['amount_tendered'] ?? $leg['amount'],
                    'change_amount' => $leg['change_amount'] ?? 0,
                    'reference_number' => $leg['reference_number'] ?? null,
                    'status' => PaymentStatusEnum::Captured,
                    'captured_at' => now(),
                    'processed_by' => $currentCashierId,
                    'idempotency_key' => $leg['idempotency_key'] ?? ('payment:'.$order->order_code.':paypending:'.$i),
                ]);
            }

            if ($order->customer_id) {
                $dppAmount = max(0, (float) $order->subtotal_amount - (float) ($order->discount_amount ?? 0));
                $this->onlinePaymentHandler->grantLoyaltyPoints((int) $order->customer_id, $order, $dppAmount);
            }

            app(FinancialOutboxDispatcherService::class)->dispatchPosOrderPaid($order);

            return $order;
        }, attempts: 3);
    }

    /**
     * Resolves multi-leg or single-method checkout payment state and tolerates bounded offline cash catalog variance.
     *
     * @param  array<int, array{method: PaymentMethod, amount: int, reference_number: ?string, idempotency_key: ?string, amount_tendered?: int, change_amount?: int}>|null  $parsedPaymentLegs
     * @param  array<string, mixed>  $data
     * @return array{
     *     dominant_payment_method: string,
     *     cash_received: int|float|null,
     *     order_change: int,
     *     final_payment_legs: array<int, array<string, mixed>>,
     *     is_deferred_cash: bool,
     *     initial_status: OrderStatus,
     *     source_reference: ?string
     * }
     */
    public function resolveCheckoutPayments(
        ?array $parsedPaymentLegs,
        array $data,
        int $totalAmount,
        ?int $tenantId,
        ?int $activeStoreId
    ): array {
        $sourceReference = isset($data['source_reference']) ? (string) $data['source_reference'] : null;

        if ($parsedPaymentLegs !== null) {
            $sumApplied = array_sum(array_column($parsedPaymentLegs, 'amount'));
            $allCashLegs = collect($parsedPaymentLegs)->every(
                fn (array $leg): bool => $leg['method'] === PaymentMethod::Cash
            );

            if ($sumApplied !== $totalAmount) {
                $variance = abs($sumApplied - $totalAmount);
                $maxOfflineVariance = max(10000, (int) round($totalAmount * 0.25));

                if (! empty($data['is_offline_sync']) && $allCashLegs && $variance <= $maxOfflineVariance) {
                    Log::warning('[OFFLINE SYNC] Minor catalog price variance tolerated for offline cash order.', [
                        'tenant_id' => $tenantId,
                        'store_id' => $activeStoreId,
                        'offline_paid' => $sumApplied,
                        'catalog_total' => $totalAmount,
                    ]);
                    $sourceReference = trim((string) ($sourceReference ?? '')." [OFFLINE CATALOG VARIANCE: paid={$sumApplied} catalog={$totalAmount}]");
                    $firstCashLeg = $parsedPaymentLegs[0];
                    $tendered = max((int) ($firstCashLeg['amount_tendered'] ?? $sumApplied), $sumApplied);
                    $parsedPaymentLegs = [[
                        'method' => PaymentMethod::Cash,
                        'amount' => $totalAmount,
                        'amount_tendered' => $tendered,
                        'change_amount' => max(0, $tendered - $sumApplied),
                        'reference_number' => $firstCashLeg['reference_number'] ?? null,
                        'idempotency_key' => $firstCashLeg['idempotency_key'] ?? null,
                    ]];
                } else {
                    throw ValidationException::withMessages([
                        'payments' => 'Total payment across methods (Rp'.number_format($sumApplied, 0, ',', '.')
                            .') does not match order total (Rp'.number_format($totalAmount, 0, ',', '.').').',
                    ]);
                }
            }

            $legsByAmountDesc = collect($parsedPaymentLegs)->sortByDesc('amount');
            /** @var array{method: PaymentMethod, amount: int, reference_number: string|null, idempotency_key: string|null} $firstLeg */
            $firstLeg = $legsByAmountDesc->first();
            $rawCashLegs = collect($data['payments'] ?? [])->filter(
                fn ($raw) => is_array($raw) && (($raw['payment_method'] ?? $raw['method'] ?? null) === 'cash' || ($raw['payment_method'] ?? $raw['method'] ?? null) === PaymentMethod::Cash)
            );
            $cashLegTotal = (int) collect($parsedPaymentLegs)->where('method', PaymentMethod::Cash)->sum('amount');
            $cashTenderedTotal = (int) $rawCashLegs->sum(fn (array $raw) => (int) ($raw['amount_tendered'] ?? $raw['amount'] ?? 0));
            $cashChangeTotal = (int) $rawCashLegs->sum(fn (array $raw) => (int) ($raw['change_amount'] ?? max(0, (int) ($raw['amount_tendered'] ?? $raw['amount'] ?? 0) - (int) ($raw['amount'] ?? 0))));
            $cashReceivedForReceipt = $cashTenderedTotal > 0 ? $cashTenderedTotal : ($cashLegTotal > 0 ? $cashLegTotal : null);
            $orderChangeForReceipt = $cashChangeTotal > 0 ? $cashChangeTotal : max(0, (int) ($cashReceivedForReceipt ?? 0) - $cashLegTotal);

            return [
                'dominant_payment_method' => $firstLeg['method']->value,
                'cash_received' => $cashReceivedForReceipt,
                'order_change' => $orderChangeForReceipt,
                'final_payment_legs' => $parsedPaymentLegs,
                'is_deferred_cash' => false,
                'initial_status' => OrderStatus::Paid,
                'source_reference' => $sourceReference,
            ];
        }

        $dominantPaymentMethod = (string) ($data['payment_method'] ?? 'cash');
        $cashReceived = $data['cash_received'] ?? null;
        $isDeferredCash = $dominantPaymentMethod === 'cash' && ! empty($data['is_self_order_cash']);
        $isImmediateCash = $dominantPaymentMethod === 'cash' && ! $isDeferredCash;

        if ($isImmediateCash && ! empty($data['is_offline_sync'])) {
            $submittedExpected = isset($data['order_amount']) ? (int) $data['order_amount'] : $totalAmount;
            $variance = abs($submittedExpected - $totalAmount);
            $maxOfflineVariance = max(10000, (int) round($totalAmount * 0.25));
            if ($variance <= $maxOfflineVariance && ($cashReceived === null || (int) $cashReceived >= $submittedExpected)) {
                if ($submittedExpected !== $totalAmount) {
                    $sourceReference = trim((string) ($sourceReference ?? '')." [OFFLINE CATALOG VARIANCE: paid={$submittedExpected} catalog={$totalAmount}]");
                }
                $cashReceived = max((int) ($cashReceived ?? $totalAmount), $totalAmount);
            }
        }

        if ($isImmediateCash && (int) $cashReceived < $totalAmount) {
            throw ValidationException::withMessages([
                'cash_received' => 'Cash received is less than total amount due.',
            ]);
        }

        $cashReceivedForReceipt = $isImmediateCash ? ($cashReceived ?? $totalAmount) : null;
        $orderChangeForReceipt = ($isImmediateCash && $cashReceived) ? max(0, (int) $cashReceived - $totalAmount) : 0;

        return [
            'dominant_payment_method' => $dominantPaymentMethod,
            'cash_received' => $cashReceivedForReceipt,
            'order_change' => $orderChangeForReceipt,
            'final_payment_legs' => [],
            'is_deferred_cash' => $isDeferredCash,
            'initial_status' => $isImmediateCash ? OrderStatus::Paid : OrderStatus::Pending,
            'source_reference' => $sourceReference,
        ];
    }
}
