<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Handlers;

use App\Core\Context\TenantContext;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use App\Modules\Sales\Application\Services\PosTableAndCustomerSyncService;
use App\Modules\Sales\Models\CashDrawerMovement;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Models\Shift;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Handles online payment attempts, gateway Snap tokens, settlement reconciliation, and webhooks (ADR-107 / ADR-108).
 * Completely decoupled from third-party vendor SDKs via PaymentGatewayInterface.
 */
class OnlinePaymentAndWebhookHandler
{
    public function __construct(
        protected PaymentGatewayInterface $paymentGateway,
        protected VoidAndRestoreOrderHandler $voidHandler,
        protected PosTableAndCustomerSyncService $tableSyncService
    ) {}

    public function updateStatusFromMidtransNotification(
        string $orderCode,
        string $transactionStatus,
        ?string $fraudStatus,
        ?int $grossAmount = null,
        ?int $originalAmount = null,
    ): Order {
        return DB::transaction(function () use ($orderCode, $transactionStatus, $fraudStatus, $grossAmount, $originalAmount) {
            $order = Order::withoutGlobalScopes()->where('order_code', $orderCode)->lockForUpdate()->firstOrFail();

            if ($order->order_status->isFinal()) {
                return $order;
            }

            $expectedAmount = (int) $order->order_amount;
            $isAmountValid = false;

            if ($grossAmount === null || $grossAmount === $expectedAmount) {
                $isAmountValid = true;
            } elseif ($originalAmount !== null && $originalAmount === $expectedAmount) {
                $isAmountValid = true;
            } elseif ($grossAmount > $expectedAmount && ($grossAmount - $expectedAmount) <= max(10000, (int) round($expectedAmount * 0.15))) {
                $isAmountValid = true;
            }

            if (! $isAmountValid) {
                Log::critical('Payment gateway notification GROSS AMOUNT MISMATCH -- status TIDAK diubah demi keamanan.', [
                    'order_code' => $orderCode,
                    'order_amount_di_database' => (int) $order->order_amount,
                    'gross_amount_dari_gateway' => $grossAmount,
                    'original_amount' => $originalAmount,
                    'transaction_status' => $transactionStatus,
                ]);

                return $order;
            }

            $newStatus = match (true) {
                in_array($transactionStatus, ['capture', 'settlement'], true) && $fraudStatus !== 'challenge' => OrderStatus::Paid,
                $transactionStatus === 'deny' => OrderStatus::Failed,
                $transactionStatus === 'cancel' => OrderStatus::Cancelled,
                $transactionStatus === 'expire' => OrderStatus::Expired,
                default => null,
            };

            if ($newStatus !== null) {
                if ($newStatus === OrderStatus::Paid && $order->order_status !== OrderStatus::Paid) {
                    $paymentMethod = $order->payment_method ?? PaymentMethod::Cash;

                    // Pay the pending order
                    $order->forceFill([
                        'order_status' => OrderStatus::Paid,
                        'order_lifecycle_status' => OrderLifecycleStatus::Confirmed->value,
                        'payment_status' => OrderPaymentStatus::Paid->value,
                        'fulfillment_status' => OrderFulfillmentStatus::InPreparation->value,
                    ])->save();

                    Payment::create([
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'device_id' => $order->created_by_device_id,
                        'order_id' => $order->id,
                        'shift_id' => $order->shift_id,
                        'payment_method' => $paymentMethod,
                        'amount' => (int) $order->order_amount,
                        'amount_tendered' => (int) $order->order_amount,
                        'change_amount' => 0,
                        'reference_number' => 'GATEWAY-'.$transactionStatus,
                        'status' => PaymentStatusEnum::Captured,
                        'captured_at' => now(),
                        'processed_by' => null,
                        'idempotency_key' => 'payment:'.$order->order_code.':gateway',
                    ]);

                    if ($order->customer_id) {
                        $dppAmount = max(0, (float) $order->subtotal_amount - (float) ($order->discount_amount ?? 0));
                        $this->grantLoyaltyPoints((int) $order->customer_id, $order, $dppAmount);
                    }

                    app(FinancialOutboxDispatcherService::class)->dispatchPosOrderPaid($order);
                } else {
                    $order->forceFill([
                        'order_status' => $newStatus,
                        'order_lifecycle_status' => OrderLifecycleStatus::Cancelled->value,
                        'payment_status' => OrderPaymentStatus::Unpaid->value,
                    ])->save();

                    if (in_array($newStatus, [OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                        $this->voidHandler->restoreStockForOrder($order);
                        $this->tableSyncService->syncTableStatusOnOrderFinalized($order);

                        if ($order->discount_id) {
                            \App\Modules\Pricing\Models\DiscountRedemption::where('order_id', $order->id)
                                ->where('status', \App\Modules\Pricing\Models\DiscountRedemption::STATUS_APPLIED)
                                ->update([
                                    'status' => \App\Modules\Pricing\Models\DiscountRedemption::STATUS_VOIDED_REVERSED,
                                    'reversed_at' => now(),
                                ]);
                        }

                        try {
                            app(\App\Modules\Pricing\Services\VoucherService::class)->restoreVoucherForOrder($order->id);
                        } catch (\Throwable $e) {
                            Log::warning("[VOUCHER] Gagal restore voucher untuk order #{$order->id}: ".$e->getMessage());
                        }
                    }
                }
            }

            return $order;
        }, attempts: 3);
    }

    public function initiatePaymentAttempt(
        Order $order,
        PaymentMethod|string $method,
        string|int $amount,
        string $idempotencyKey,
        ?int $deviceId = null,
        ?int $shiftId = null,
        ?int $userId = null,
        ?string $provider = null,
    ): PaymentAttempt {
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) ($order->tenant_id ?? $tenantContext->requireTenantId());
        $storeId = (int) ($order->store_id ?? $tenantContext->requireStoreId());

        $existing = PaymentAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $rawMethod = $method instanceof PaymentMethod ? $method->value : (string) $method;
        $numAmount = (int) $amount;

        if ($numAmount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if (! $provider) {
            $provider = match (strtolower($rawMethod)) {
                'cash' => 'cash',
                'qris', 'midtrans', 'bank_transfer', 'ewallet' => 'midtrans',
                'card' => 'manual_edc',
                default => 'midtrans',
            };
        }

        $providerOrderId = null;
        if ($provider === 'midtrans') {
            $existingCount = PaymentAttempt::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->where('provider', 'midtrans')
                ->count();
            $providerOrderId = $order->order_code.($existingCount > 0 ? '-ATT'.($existingCount + 1) : '');
        }

        return DB::transaction(function () use (
            $order, $rawMethod, $numAmount, $idempotencyKey,
            $deviceId, $shiftId, $userId, $provider, $providerOrderId,
            $tenantId, $storeId
        ) {
            $lockedOrder = Order::withoutGlobalScopes()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            $remaining = $lockedOrder->getRemainingBalance();
            if ($numAmount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount (Rp'.number_format($numAmount, 0, ',', '.').') exceeds remaining balance (Rp'.number_format($remaining, 0, ',', '.').').',
                ]);
            }

            return PaymentAttempt::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'order_id' => $lockedOrder->id,
                'device_id' => $deviceId ?? $lockedOrder->created_by_device_id,
                'shift_id' => $shiftId ?? $lockedOrder->shift_id,
                'idempotency_key' => $idempotencyKey,
                'provider' => $provider,
                'payment_method' => $rawMethod,
                'provider_order_id' => $providerOrderId,
                'amount' => $numAmount,
                'currency' => 'IDR',
                'status' => PaymentAttemptStatus::Initiated,
                'initiated_by_user_id' => $userId ?? Auth::id() ?? $lockedOrder->user_id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $providerMeta
     */
    public function settlePaymentAttempt(PaymentAttempt $attempt, array $providerMeta = []): Payment
    {
        $tenantContext = app(TenantContext::class);

        return $tenantContext->runAs($attempt->tenant_id, $attempt->store_id, function () use ($attempt, $providerMeta) {
            return DB::transaction(function () use ($attempt, $providerMeta) {
                $order = Order::withoutGlobalScopes()->where('id', $attempt->order_id)->lockForUpdate()->firstOrFail();
                $lockedAttempt = PaymentAttempt::withoutGlobalScopes()->where('id', $attempt->id)->lockForUpdate()->firstOrFail();

                if ($lockedAttempt->status === PaymentAttemptStatus::Succeeded && $lockedAttempt->payment_id) {
                    return Payment::withoutGlobalScopes()->findOrFail($lockedAttempt->payment_id);
                }

                if (in_array($lockedAttempt->status, [PaymentAttemptStatus::Expired, PaymentAttemptStatus::Cancelled], true)) {
                    throw new DomainException("Payment attempt #{$lockedAttempt->id} has expired or was cancelled.");
                }

                $tendered = isset($providerMeta['amount_tendered']) ? (int) $providerMeta['amount_tendered'] : (int) $lockedAttempt->amount;
                $change = isset($providerMeta['change_amount']) ? (int) $providerMeta['change_amount'] : 0;
                $appliedAmount = (int) $lockedAttempt->amount;

                $currentPaid = (int) Payment::withoutGlobalScopes()
                    ->where('order_id', $order->id)
                    ->whereIn('status', [PaymentStatusEnum::Captured, 'settled'])
                    ->sum('amount');

                if ($currentPaid + $appliedAmount > (int) $order->order_amount) {
                    throw new DomainException('Payment settlement of Rp'.number_format($appliedAmount, 0, ',', '.').' would exceed total order amount (Already paid: Rp'.number_format($currentPaid, 0, ',', '.').', Total due: Rp'.number_format((int) $order->order_amount, 0, ',', '.').').');
                }

                $pmEnum = PaymentMethod::tryFrom($lockedAttempt->payment_method) ?? PaymentMethod::Cash;

                $payment = Payment::forceCreate([
                    'tenant_id' => $order->tenant_id,
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'shift_id' => $lockedAttempt->shift_id ?? $order->shift_id,
                    'device_id' => $lockedAttempt->device_id ?? $order->created_by_device_id,
                    'payment_method' => $pmEnum,
                    'amount' => $appliedAmount,
                    'amount_tendered' => $tendered,
                    'change_amount' => $change,
                    'reference_number' => $providerMeta['transaction_id'] ?? $lockedAttempt->provider_order_id ?? $lockedAttempt->idempotency_key,
                    'status' => PaymentStatusEnum::Captured,
                    'captured_at' => now(),
                    'processed_by' => $lockedAttempt->initiated_by_user_id,
                    'idempotency_key' => 'attempt-settle-'.$lockedAttempt->id,
                ]);

                $lockedAttempt->status = PaymentAttemptStatus::Succeeded;
                $lockedAttempt->payment_id = $payment->id;
                $lockedAttempt->provider_transaction_id = $providerMeta['transaction_id'] ?? $lockedAttempt->provider_transaction_id;
                $lockedAttempt->raw_response_meta = $providerMeta;
                $lockedAttempt->settled_at = now();
                $lockedAttempt->save();

                if ($pmEnum === PaymentMethod::Cash && ($lockedAttempt->shift_id ?? $order->shift_id)) {
                    $shiftId = (int) ($lockedAttempt->shift_id ?? $order->shift_id);
                    $netCash = $tendered - $change;
                    if ($netCash > 0) {
                        $activeShift = Shift::withoutGlobalScopes()->where('id', $shiftId)->lockForUpdate()->first();
                        if ($activeShift && $activeShift->status === 'open') {
                            CashDrawerMovement::forceCreate([
                                'tenant_id' => $order->tenant_id,
                                'store_id' => $order->store_id,
                                'device_id' => $lockedAttempt->device_id ?? $order->created_by_device_id,
                                'shift_id' => $activeShift->id,
                                'created_by' => $lockedAttempt->initiated_by_user_id ?? $order->user_id,
                                'type' => 'cash_in',
                                'category' => 'restock_change',
                                'amount' => $netCash,
                                'reason' => "Payment for order #{$order->order_code}",
                            ]);
                        }
                    }
                }

                $totalCaptured = Payment::withoutGlobalScopes()
                    ->where('order_id', $order->id)
                    ->whereIn('status', [PaymentStatusEnum::Captured, 'settled'])
                    ->sum('amount');

                if ($totalCaptured >= (int) $order->order_amount) {
                    $wasAlreadyPaid = $order->order_status === OrderStatus::Paid;

                    $order->forceFill([
                        'order_status' => OrderStatus::Paid,
                        'payment_method' => $pmEnum,
                        'cash_received' => $pmEnum === PaymentMethod::Cash ? $tendered : $order->cash_received,
                        'order_change' => $pmEnum === PaymentMethod::Cash ? $change : $order->order_change,
                    ])->save();

                    if (! $wasAlreadyPaid) {
                        $order->orderItems()->update(['preparation_status' => 'pending']);

                        if ($order->customer_id) {
                            $dppAmount = max(0, (float) $order->subtotal_amount - (float) ($order->discount_amount ?? 0));
                            $this->grantLoyaltyPoints((int) $order->customer_id, $order, $dppAmount);
                        }

                        app(FinancialOutboxDispatcherService::class)->dispatchPosOrderPaid($order);
                    }
                }

                return $payment;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $verifiedPayload
     */
    public function handleMidtransWebhook(array $verifiedPayload): void
    {
        $orderId = (string) ($verifiedPayload['order_id'] ?? '');
        $transactionStatus = (string) ($verifiedPayload['transaction_status'] ?? '');
        $fraudStatus = $verifiedPayload['fraud_status'] ?? null;
        $grossAmount = isset($verifiedPayload['gross_amount']) ? (int) round((float) $verifiedPayload['gross_amount']) : null;
        $originalAmount = null;

        if (! empty($verifiedPayload['metadata'])) {
            $meta = is_object($verifiedPayload['metadata']) ? (array) $verifiedPayload['metadata'] : $verifiedPayload['metadata'];
            if (! empty($meta['extra_info'])) {
                $extraInfo = is_object($meta['extra_info']) ? (array) $meta['extra_info'] : $meta['extra_info'];
                if (! empty($extraInfo['gross_amount_info'])) {
                    $grossInfo = is_object($extraInfo['gross_amount_info']) ? (array) $extraInfo['gross_amount_info'] : $extraInfo['gross_amount_info'];
                    if (isset($grossInfo['original_amount'])) {
                        $originalAmount = (int) round((float) $grossInfo['original_amount']);
                    }
                }
            }
        }

        if ($originalAmount === null && isset($verifiedPayload['original_amount'])) {
            $originalAmount = (int) round((float) $verifiedPayload['original_amount']);
        }

        if (! $orderId || ! $transactionStatus) {
            return;
        }

        $attempt = PaymentAttempt::withoutGlobalScopes()->where('provider_order_id', $orderId)->first();
        $order = null;

        if ($attempt) {
            $order = Order::withoutGlobalScopes()->where('id', $attempt->order_id)->first();
        } else {
            $order = Order::withoutGlobalScopes()->where('order_code', $orderId)->first();
        }

        if (! $order) {
            Log::warning('handleMidtransWebhook: Order tidak ditemukan untuk payload:', ['order_id' => $orderId]);

            return;
        }

        $tenantContext = app(TenantContext::class);
        $tenantContext->runAs((int) $order->tenant_id, $order->store_id, function () use ($order, $attempt, $transactionStatus, $fraudStatus, $grossAmount, $originalAmount, $verifiedPayload) {
            DB::transaction(function () use ($order, $attempt, $transactionStatus, $fraudStatus, $grossAmount, $originalAmount, $verifiedPayload) {
                $lockedOrder = Order::withoutGlobalScopes()->where('id', $order->id)->lockForUpdate()->firstOrFail();

                if ($lockedOrder->order_status->isFinal()) {
                    if ($attempt && $attempt->status !== PaymentAttemptStatus::Succeeded) {
                        $attempt->status = PaymentAttemptStatus::Succeeded;
                        $attempt->save();
                    }

                    return;
                }

                $expectedAmount = $attempt ? (int) $attempt->amount : (int) $lockedOrder->order_amount;
                $isAmountValid = false;

                if ($grossAmount === null || $grossAmount === $expectedAmount) {
                    $isAmountValid = true;
                } elseif ($originalAmount !== null && $originalAmount === $expectedAmount) {
                    $isAmountValid = true;
                } elseif ($grossAmount > $expectedAmount && ($grossAmount - $expectedAmount) <= max(10000, (int) round($expectedAmount * 0.15))) {
                    $isAmountValid = true;
                }

                if (! $isAmountValid) {
                    Log::critical('handleMidtransWebhook: GROSS AMOUNT MISMATCH', [
                        'order_id' => $lockedOrder->id,
                        'expected' => $expectedAmount,
                        'got' => $grossAmount,
                        'original_amount' => $originalAmount,
                    ]);

                    return;
                }

                if (in_array($transactionStatus, ['capture', 'settlement'], true) && $fraudStatus !== 'challenge') {
                    if ($attempt) {
                        $this->settlePaymentAttempt($attempt, [
                            'transaction_id' => $verifiedPayload['transaction_id'] ?? null,
                            'transaction_status' => $transactionStatus,
                            'gross_amount' => $grossAmount,
                            'raw' => $verifiedPayload,
                        ]);
                    } else {
                        $this->updateStatusFromMidtransNotification(
                            orderCode: $lockedOrder->order_code,
                            transactionStatus: $transactionStatus,
                            fraudStatus: $fraudStatus,
                            grossAmount: $grossAmount
                        );
                    }
                } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true)) {
                    if ($attempt) {
                        $attempt->status = match ($transactionStatus) {
                            'expire' => PaymentAttemptStatus::Expired,
                            'cancel' => PaymentAttemptStatus::Cancelled,
                            default => PaymentAttemptStatus::Failed,
                        };
                        $attempt->raw_response_meta = $verifiedPayload;
                        $attempt->save();
                    }

                    if (! $attempt || $lockedOrder->paymentAttempts()->where('status', PaymentAttemptStatus::Succeeded)->doesntExist()) {
                        $this->updateStatusFromMidtransNotification(
                            orderCode: $lockedOrder->order_code,
                            transactionStatus: $transactionStatus,
                            fraudStatus: $fraudStatus,
                            grossAmount: $grossAmount
                        );
                    }
                }
            }, attempts: 3);
        });
    }

    public function buildSnapParams(
        Order $order,
        float $subtotalAmount,
        float $discountAmount,
        int $taxAmount,
        int $totalAmount,
        string $dominantPaymentMethod,
        ?int $customerId = null
    ): array {
        $itemDetails = [
            ['id' => 'ORDER-'.$order->id, 'price' => (int) $subtotalAmount, 'quantity' => 1, 'name' => 'POS Order'],
            ['id' => 'TAX-PPN', 'price' => (int) $taxAmount, 'quantity' => 1, 'name' => 'Tax'],
        ];
        if ((int) $discountAmount > 0) {
            $itemDetails[] = ['id' => 'DISCOUNT', 'price' => -(int) $discountAmount, 'quantity' => 1, 'name' => 'Discount'];
        }
        $snapRounding = (int) $totalAmount - ((int) $subtotalAmount - (int) $discountAmount + (int) $taxAmount);
        if ($snapRounding !== 0) {
            $itemDetails[] = ['id' => 'ROUNDING', 'price' => $snapRounding, 'quantity' => 1, 'name' => 'Rounding'];
        }

        $customerName = 'Walk-in Customer';
        if ($customerId) {
            $customer = \App\Modules\Customers\Models\Customer::find($customerId);
            if ($customer && ! empty($customer->name)) {
                $customerName = $customer->name;
            }
        }

        $snapParams = [
            'transaction_details' => [
                'order_id' => $order->order_code,
                'gross_amount' => (int) $totalAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => $customerName,
            ],
            'expiry' => [
                'unit' => 'minutes',
                'duration' => 30,
            ],
        ];

        $enabled = match ($dominantPaymentMethod) {
            'qris' => ['other_qris', 'gopay'],
            'ewallet' => ['gopay', 'shopeepay'],
            default => [],
        };

        if (! empty($enabled)) {
            $snapParams['enabled_payments'] = $enabled;
        }

        return $snapParams;
    }

    /**
     * Request a Snap token via PaymentGatewayInterface.
     *
     * @param  array<string, mixed>  $snapParams
     */
    public function requestMidtransSnapToken(array $snapParams, Order $order): string
    {
        return $this->paymentGateway->createSnapToken($snapParams, $order->order_code);
    }

    public function getMidtransTransactionStatus(string $orderId): object
    {
        return $this->paymentGateway->getTransactionStatus($orderId);
    }

    public function syncOrderWithMidtrans(Order $order): Order
    {
        if ($order->order_status !== OrderStatus::Pending) {
            return $order;
        }

        $attempt = PaymentAttempt::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('provider', 'midtrans')
            ->latest('id')
            ->first();

        $providerOrderId = $attempt !== null ? $attempt->provider_order_id : $order->order_code;

        $status = $this->getMidtransTransactionStatus((string) $providerOrderId);

        $statusArray = (array) $status;
        if (! empty($statusArray['transaction_status'])) {
            $this->handleMidtransWebhook(array_merge($statusArray, [
                'order_id' => $providerOrderId,
            ]));
        }

        return $order->fresh() ?? $order;
    }

    public function grantLoyaltyPoints(int $customerId, Order $order, float|int $dppAmount): void
    {
        $customer = \App\Modules\Customers\Models\Customer::where('id', $customerId)->lockForUpdate()->first();

        if (! $customer) {
            Log::warning('Loyalty skip: customer tidak ditemukan.', [
                'customer_id' => $customerId,
                'order_code' => $order->order_code,
            ]);

            return;
        }

        $loyaltyService = app(\App\Modules\Pricing\Services\LoyaltyService::class);
        $pointsEarned = $loyaltyService->calculateEarnablePoints($customer, (float) $dppAmount);

        if ($pointsEarned > 0) {
            $loyaltyService->earnPoints(
                customer: $customer,
                points: $pointsEarned,
                orderId: $order->id,
                reference: 'Perolehan poin otomatis dari order '.$order->order_code.'.'
            );
        }

        $upgradeCallback = function () use ($customer, $loyaltyService) {
            try {
                $loyaltyService->checkAndUpgradeTier($customer);
            } catch (\Throwable $e) {
                Log::warning("[LOYALTY] Gagal upgrade tier untuk customer #{$customer->id}: ".$e->getMessage());
            }
        };

        if (DB::transactionLevel() <= 1 && app()->environment('testing')) {
            $upgradeCallback();
        } else {
            DB::afterCommit($upgradeCallback);
        }
    }
}
