<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Handlers;

use App\Core\Context\TenantContext;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Events\KdsOrderCreated;
use App\Modules\Sales\Application\Services\PosTableAndCustomerSyncService;
use App\Modules\Sales\Domain\Services\OrderLinePreparationService;
use App\Modules\Sales\Domain\Services\OrderPaymentTenderService;
use App\Modules\Sales\Domain\Services\OrderStockDeductionService;
use App\Modules\Sales\Domain\Services\OrderTotalsCalculatorService;
use App\Modules\Sales\Exceptions\ShiftClosedException;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Models\ParkedCart;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Models\Table;
use App\Platform\Documents\DocumentSequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles core synchronous POS transaction creation and checkout orchestration (ADR-107 / ADR-108).
 * Ensures DB transactions remain free from synchronous external network I/O.
 */
class CheckoutOrderHandler
{
    public function __construct(
        protected OrderTotalsCalculatorService $totalsCalculator,
        protected OnlinePaymentAndWebhookHandler $onlinePaymentHandler,
        protected PosTableAndCustomerSyncService $tableSyncService,
        protected VoidAndRestoreOrderHandler $voidHandler,
        protected OrderPaymentTenderService $paymentTenderService,
        protected OrderStockDeductionService $stockDeductionService,
        protected OrderLinePreparationService $linePreparationService
    ) {}

    public function createTransaction(array $data, int $userId): Order
    {
        $tenantContext = app(TenantContext::class);
        $activeContextTenantId = $tenantContext->getTenantId() ?? auth()->user()?->tenant_id;

        if ($activeContextTenantId !== null) {
            $tenantId = (int) $activeContextTenantId;
            if (isset($data['tenant_id']) && (int) $data['tenant_id'] !== $tenantId) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Cross-tenant payload manipulation detected: tenant context mismatch.',
                ]);
            }
        } else {
            $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : null;
        }

        if (! empty($data['idempotency_key']) && $tenantId !== null) {
            $existingOrder = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existingOrder) {
                return $existingOrder;
            }
        }

        try {
            return DB::transaction(function () use ($data, $userId, $tenantId, $tenantContext) {
                $contextStoreId = $tenantContext->getStoreId();
                if ($contextStoreId !== null) {
                    $contextStoreId = (int) $contextStoreId;
                    if (isset($data['store_id']) && (int) $data['store_id'] !== $contextStoreId) {
                        $allowedStoreIds = $tenantContext->getAllowedStoreIds();
                        $targetStoreId = (int) $data['store_id'];
                        if (! empty($allowedStoreIds) && ! in_array($targetStoreId, $allowedStoreIds, true) && ! $tenantContext->isOwnerScope()) {
                            throw ValidationException::withMessages([
                                'store_id' => 'Unauthorized store access: you do not have permission for this store.',
                            ]);
                        }
                        if ($tenantId !== null) {
                            $validStore = DB::table('stores')
                                ->where('tenant_id', $tenantId)
                                ->where('id', $targetStoreId)
                                ->exists();
                            if (! $validStore) {
                                throw ValidationException::withMessages([
                                    'store_id' => 'The selected store does not belong to the active tenant.',
                                ]);
                            }
                        }
                        $activeStoreId = $targetStoreId;
                    } else {
                        $activeStoreId = $contextStoreId;
                    }
                } else {
                    $activeStoreId = isset($data['store_id']) ? (int) $data['store_id'] : null;
                    if ($activeStoreId !== null && $tenantId !== null) {
                        $validStore = DB::table('stores')
                            ->where('tenant_id', $tenantId)
                            ->where('id', $activeStoreId)
                            ->exists();
                        if (! $validStore) {
                            throw ValidationException::withMessages([
                                'store_id' => 'The selected store does not belong to the active tenant.',
                            ]);
                        }
                    }
                }
                $tablesEnabled = app(\App\Modules\Subscription\Services\EntitlementService::class)->isTablesEnabled((int) $tenantId, $activeStoreId ? (int) $activeStoreId : null);
                $orderType = OrderType::coerce($data['order_type'] ?? null) ?? OrderType::DineIn;

                $tableId = null;
                $tableNumber = null;
                $lockedTable = null;

                $hasActiveTables = false;
                if ($tablesEnabled && $activeStoreId !== null) {
                    $hasActiveTables = Table::forBranch((int) $activeStoreId)
                        ->where('is_active', true)
                        ->exists();
                }

                if (! $tablesEnabled || $orderType !== OrderType::DineIn) {
                    $tableId = null;
                    $tableNumber = null;
                } else {
                    $requestedTableId = $data['table_id'] ?? null;

                    if (! empty($requestedTableId)) {
                        $lockedTable = Table::forBranch((int) $activeStoreId)
                            ->where('id', $requestedTableId)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedTable) {
                            throw ValidationException::withMessages([
                                'table_id' => 'Table not found or does not belong to this branch.',
                            ]);
                        }

                        if (! $lockedTable->is_active || $lockedTable->operational_status === 'disabled') {
                            throw ValidationException::withMessages([
                                'table_id' => 'Table is currently inactive and cannot be used.',
                            ]);
                        }

                        $tableId = $lockedTable->id;
                        $tableNumber = $lockedTable->table_name;
                    } elseif ($hasActiveTables) {
                        throw ValidationException::withMessages([
                            'table_id' => 'Table is required for Dine-In orders.',
                        ]);
                    } else {
                        $tableId = null;
                        $tableNumber = null;
                    }
                }

                $activeShift = null;
                $isOfflineRecon = (bool) ($data['offline_post_close_reconciliation'] ?? false);
                if (! empty($data['shift_id'])) {
                    $activeShift = Shift::allBranchesInTenant()->where('id', $data['shift_id'])->lockForUpdate()->first();
                    if (! $isOfflineRecon && (! $activeShift || $activeShift->status !== 'open')) {
                        throw new ShiftClosedException('Your register shift was just closed. This transaction has been cancelled, please open a new shift before proceeding.');
                    }
                    if (! $isOfflineRecon && $activeShift->user_id !== $userId) {
                        throw ValidationException::withMessages(['shift_id' => 'Shift does not belong to current cashier.']);
                    }
                }

                $resolvedDeviceId = $data['created_by_device_id'] ?? ($activeShift ? $activeShift->device_id : null);

                $parkedCart = null;
                if (! empty($data['parked_cart_id'])) {
                    $parkedCart = ParkedCart::where('id', $data['parked_cart_id'])
                        ->where('status', ParkedCart::STATUS_PARKED)
                        ->lockForUpdate()
                        ->first();

                    if (! $parkedCart) {
                        throw ValidationException::withMessages([
                            'parked_cart_id' => 'The parked cart is no longer active or has already been converted.',
                        ]);
                    }
                }

                $parsedPaymentLegs = null;
                if (! empty($data['payments']) && is_array($data['payments'])) {
                    $parsedPaymentLegs = $this->paymentTenderService->parsePaymentLegs($data['payments']);
                }

                $mergedItems = [];
                foreach ($data['items'] as $rawItem) {
                    if (! isset($rawItem['quantity']) || (int) $rawItem['quantity'] <= 0) {
                        throw ValidationException::withMessages([
                            'items' => 'Item quantity must be greater than zero.',
                        ]);
                    }

                    $optKey = '';
                    if (! empty($rawItem['options'])) {
                        $optIds = collect($rawItem['options'])->pluck('modifier_id')->filter()->sort()->values()->all();
                        $optKey = implode('-', $optIds);
                    }
                    $customPriceKey = isset($rawItem['custom_price']) ? (string) $rawItem['custom_price'] : 'none';
                    $variantKey = isset($rawItem['product_variant_id']) ? (string) $rawItem['product_variant_id'] : 'none';
                    $uomKey = isset($rawItem['uom_id']) ? (string) $rawItem['uom_id'] : 'none';
                    $groupKey = "{$rawItem['product_id']}|{$variantKey}|{$uomKey}|{$optKey}|{$customPriceKey}";

                    if (! isset($mergedItems[$groupKey])) {
                        $mergedItems[$groupKey] = $rawItem;
                    } else {
                        $mergedItems[$groupKey]['quantity'] += $rawItem['quantity'];
                    }
                }

                $prep = $this->linePreparationService->prepareOrderLines($mergedItems, $data, $activeStoreId, $userId);
                $lines = $prep['lines'];
                $subtotalAmount = $prep['subtotalAmount'];
                $approvalRequiredItemDiscountSum = (float) $prep['approvalRequiredItemDiscountSum'];
                $bomBreakdownByLineIndex = $prep['bomBreakdownByLineIndex'];
                $products = $prep['products'];
                $lockedIngredients = $prep['lockedIngredients'];

                $discountAmount = 0;
                $discountId = $data['discount_id'] ?? null;
                $discount = null;
                $totalItemDiscounts = array_sum(array_column($lines, 'item_discount_amount'));

                if ($discountId) {
                    $discount = \App\Modules\Pricing\Models\Discount::where('id', $discountId)->lockForUpdate()->first();
                    $now = now();
                    $isValidDiscount = $discount && $discount->is_active && $subtotalAmount >= $discount->min_purchase_amount;

                    if ($isValidDiscount && $discount->valid_from && $now->lt($discount->valid_from)) {
                        $isValidDiscount = false;
                    }
                    if ($isValidDiscount && $discount->valid_until && $now->gt($discount->valid_until)) {
                        $isValidDiscount = false;
                    }

                    if ($isValidDiscount && $totalItemDiscounts > 0 && ! $discount->is_stackable) {
                        throw ValidationException::withMessages([
                            'discount_id' => 'This discount voucher cannot be combined with product discounts.',
                        ]);
                    }

                    if ($isValidDiscount && $discount->usage_limit !== null) {
                        $appliedCount = \App\Modules\Pricing\Models\DiscountRedemption::where('discount_id', $discount->id)
                            ->where('status', \App\Modules\Pricing\Models\DiscountRedemption::STATUS_APPLIED)
                            ->count();

                        if ($appliedCount >= $discount->usage_limit) {
                            throw ValidationException::withMessages([
                                'discount_id' => 'Discount voucher quota has been reached.',
                            ]);
                        }
                    }

                    if ($isValidDiscount) {
                        $baseForOrderDiscount = $discount->is_stackable ? max(0, $subtotalAmount - $totalItemDiscounts) : $subtotalAmount;
                        if ($discount->type === 'percentage') {
                            $calc = (int) round($baseForOrderDiscount * ($discount->value / 100));
                            $discountAmount = $discount->max_discount_amount ? min($calc, $discount->max_discount_amount) : $calc;
                        } else {
                            $discountAmount = min((int) $baseForOrderDiscount, $discount->value);
                        }
                    } else {
                        $discountId = null;
                    }
                }

                $manualDiscount = (int) ($data['manual_discount_amount'] ?? 0);
                app(\App\Modules\Pricing\Services\DiscountApprovalService::class)->validateOrderDiscountsAndApprovals(
                    (float) $subtotalAmount, $manualDiscount, $approvalRequiredItemDiscountSum,
                    ! empty($data['approval_id']) ? (int) $data['approval_id'] : null, (int) $tenantId, $activeStoreId, $userId
                );
                if ($manualDiscount > 0) {
                    $discountAmount = ($discount !== null && ! $discount->is_stackable)
                        ? min((int) $subtotalAmount, max($discountAmount, $manualDiscount))
                        : min((int) $subtotalAmount, $discountAmount + $manualDiscount);
                }

                $lineNets = array_column($lines, 'effective_subtotal');
                $allocatedOrderDiscounts = app(\App\Modules\Pricing\Services\DiscountAllocationService::class)->allocate(
                    (float) $discountAmount,
                    $lineNets
                );

                $calculatedTotals = $this->totalsCalculator->calculateOrderTotals(
                    $subtotalAmount,
                    $discountAmount,
                    $data['service_charge_amount'] ?? 0
                );
                $taxAmount = $calculatedTotals['tax_amount'];
                $totalAmount = $calculatedTotals['total_amount'];
                $dppAmount = (float) $calculatedTotals['dpp_amount'];
                $serviceChargeAmount = (float) $calculatedTotals['service_charge_amount'];
                $roundingAdjustment = (float) $calculatedTotals['rounding_adjustment'];

                $resolvedPayments = $this->paymentTenderService->resolveCheckoutPayments(
                    parsedPaymentLegs: $parsedPaymentLegs,
                    data: $data,
                    totalAmount: (int) $totalAmount,
                    tenantId: $tenantId,
                    activeStoreId: $activeStoreId
                );

                $dominantPaymentMethod = $resolvedPayments['dominant_payment_method'];
                $cashReceivedForReceipt = $resolvedPayments['cash_received'];
                $orderChangeForReceipt = $resolvedPayments['order_change'];
                $finalPaymentLegs = $resolvedPayments['final_payment_legs'];
                $isDeferredCash = $resolvedPayments['is_deferred_cash'];
                $initialStatus = $resolvedPayments['initial_status'];
                $data['source_reference'] = $resolvedPayments['source_reference'];

                $orderCode = app(DocumentSequenceService::class)->nextOrderCode(
                    (int) $tenantId,
                    $activeStoreId !== null ? (int) $activeStoreId : null,
                    date('Ym')
                );

                $order = Order::forceCreate([
                    'user_id' => $userId,
                    'shift_id' => $data['shift_id'] ?? null,
                    'order_code' => $orderCode,
                    'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                    'order_date' => now()->toDateString(),
                    'subtotal_amount' => $subtotalAmount,
                    'discount_id' => $discountId,
                    'discount_amount' => $discountAmount,
                    'dpp_amount' => $dppAmount,
                    'service_charge_amount' => $serviceChargeAmount,
                    'tax_amount' => $taxAmount,
                    'rounding_adjustment' => $roundingAdjustment,
                    'applied_promotions_summary' => ! empty($data['applied_promotions_summary']) ? json_encode($data['applied_promotions_summary']) : null,
                    'order_amount' => $totalAmount,
                    'cash_received' => $cashReceivedForReceipt,
                    'order_change' => $orderChangeForReceipt,
                    'order_status' => $initialStatus,
                    'order_lifecycle_status' => $initialStatus === OrderStatus::Paid ? OrderLifecycleStatus::Confirmed->value : OrderLifecycleStatus::Submitted->value,
                    'payment_status' => $initialStatus === OrderStatus::Paid ? OrderPaymentStatus::Paid->value : OrderPaymentStatus::AwaitingPayment->value,
                    'fulfillment_status' => $initialStatus === OrderStatus::Paid ? OrderFulfillmentStatus::InPreparation->value : OrderFulfillmentStatus::Unfulfilled->value,
                    'operational_unit_id' => $data['operational_unit_id'] ?? null,
                    'payment_method' => $dominantPaymentMethod,
                    'table_id' => $tableId,
                    'table_session_id' => $data['table_session_id'] ?? null,
                    'table_number' => $tableNumber,
                    'customer_id' => $data['customer_id'] ?? null,
                    'order_type' => $orderType->value,
                    'origin_channel' => $data['origin_channel'] ?? 'pos_cashier',
                    'order_channel' => $data['order_channel'] ?? ($data['origin_channel'] ?? 'pos_cashier'),
                    'external_order_id' => $data['external_order_id'] ?? null,
                    'external_short_code' => $data['external_short_code'] ?? null,
                    'driver_name' => $data['driver_name'] ?? null,
                    'driver_phone' => $data['driver_phone'] ?? null,
                    'platform_commission_rate' => $data['platform_commission_rate'] ?? 0.00,
                    'platform_commission_amount' => $data['platform_commission_amount'] ?? 0.00,
                    'merchant_promo_subsidy' => $data['merchant_promo_subsidy'] ?? 0.00,
                    'net_payout_amount' => $data['net_payout_amount'] ?? 0.00,
                    'payment_timing' => $data['payment_timing'] ?? ($isDeferredCash ? 'pay_later' : ($initialStatus === OrderStatus::Paid ? 'paid' : 'pay_first')),
                    'sent_to_kitchen_at' => $data['sent_to_kitchen_at'] ?? ($initialStatus === OrderStatus::Paid ? now() : ($isDeferredCash ? now() : null)),
                    'source_reference' => $data['source_reference'] ?? null,
                    'created_by_device_id' => $resolvedDeviceId,
                    'is_offline_sync' => (bool) ($data['is_offline_sync'] ?? false),
                    'offline_post_close_reconciliation' => (bool) ($data['offline_post_close_reconciliation'] ?? false),
                    'offline_synced_at' => ! empty($data['is_offline_sync']) ? ($data['offline_synced_at'] ?? now()) : null,
                    'offline_client_uuid' => $data['offline_client_uuid'] ?? null,
                ]);

                if ($lockedTable !== null) {
                    $lockedTable->update(['operational_status' => 'occupied']);
                }

                if (! empty($data['approval_id'])) {
                    app(\App\Modules\Pricing\Services\DiscountApprovalService::class)->consumeApproval((int) $data['approval_id'], (int) $order->id);
                }

                if (! empty($data['cart_uuid'])) {
                    app(\App\Modules\Pricing\Services\VoucherService::class)->commitVoucher((string) $data['cart_uuid'], (int) $order->id);
                }

                $createdItems = [];
                foreach ($lines as $idx => $line) {
                    $itemDiscountAmount = (float) $line['item_discount_amount'];
                    $allocatedOrderDiscount = (float) ($allocatedOrderDiscounts[$idx] ?? 0.00);
                    $netLineTotal = max(0, (float) $line['order_subtotal'] - $itemDiscountAmount - $allocatedOrderDiscount);

                    $prodModel = $products->firstWhere('id', (int) $line['product_id']);
                    $hasBomOrRecipe = $prodModel ? $prodModel->ingredients->isNotEmpty() : false;
                    $fulfillmentChannel = $orderType === OrderType::DineIn ? 'on_premise_dine_in' : 'takeaway_counter';
                    $kitchenPrep = ($hasBomOrRecipe || $orderType === OrderType::DineIn) ? 'queued' : 'not_applicable';
                    $shippingDispatch = (! $hasBomOrRecipe && ($prodModel && $prodModel->track_inventory)) ? 'picking_pending' : 'not_applicable';

                    $orderItem = OrderItem::create(array_merge(Arr::except($line, ['needs_stock_deduction', 'effective_subtotal']), [
                        'order_id' => $order->id,
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'item_discount_id' => $line['item_discount_id'] ?? null,
                        'item_discount_amount' => $itemDiscountAmount,
                        'allocated_order_discount_amount' => $allocatedOrderDiscount,
                        'net_line_total' => $netLineTotal,
                        'fulfillment_channel' => $fulfillmentChannel,
                        'kitchen_prep_status' => $kitchenPrep,
                        'shipping_dispatch_status' => $shippingDispatch,
                    ]));
                    $createdItems[] = $orderItem;
                }

                $this->stockDeductionService->deductStockForOrder(
                    $order,
                    $lines,
                    $createdItems,
                    $products,
                    $bomBreakdownByLineIndex,
                    $lockedIngredients,
                    $userId
                );

                if (! empty($finalPaymentLegs)) {
                    $this->paymentTenderService->processPaymentLegTenders($order, $finalPaymentLegs, $userId);

                    foreach ($finalPaymentLegs as $i => $leg) {
                        Payment::create([
                            'tenant_id' => $order->tenant_id,
                            'store_id' => $order->store_id,
                            'device_id' => $resolvedDeviceId,
                            'order_id' => $order->id,
                            'shift_id' => $order->shift_id,
                            'payment_method' => $leg['method'],
                            'amount' => $leg['amount'],
                            'amount_tendered' => $leg['amount_tendered'] ?? $leg['amount'],
                            'change_amount' => $leg['change_amount'] ?? 0,
                            'reference_number' => $leg['reference_number'] ?? null,
                            'status' => PaymentStatusEnum::Captured,
                            'captured_at' => now(),
                            'processed_by' => $userId,
                            'idempotency_key' => $leg['idempotency_key'] ?? ('payment:'.$order->order_code.':'.$i),
                            'is_offline_sync' => (bool) ($data['is_offline_sync'] ?? false),
                            'offline_synced_at' => ! empty($data['is_offline_sync']) ? ($data['offline_synced_at'] ?? now()) : null,
                        ]);
                    }
                } elseif ($initialStatus === OrderStatus::Paid) {
                    $paymentMethodEnum = PaymentMethod::from($dominantPaymentMethod);
                    $syntheticLeg = [
                        'method' => $paymentMethodEnum,
                        'amount' => $totalAmount,
                        'card_number' => $data['card_number'] ?? $data['reference_number'] ?? null,
                        'reference_number' => $data['reference_number'] ?? $data['card_number'] ?? null,
                        'pin' => $data['pin'] ?? null,
                    ];
                    $this->paymentTenderService->processPaymentLegTenders($order, [$syntheticLeg], $userId);

                    Payment::create([
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'device_id' => $resolvedDeviceId,
                        'order_id' => $order->id,
                        'shift_id' => $order->shift_id,
                        'payment_method' => $paymentMethodEnum,
                        'amount' => $totalAmount,
                        'amount_tendered' => $cashReceivedForReceipt ?? $totalAmount,
                        'change_amount' => $orderChangeForReceipt,
                        'status' => PaymentStatusEnum::Captured,
                        'captured_at' => now(),
                        'processed_by' => $userId,
                        'idempotency_key' => 'payment:'.$order->order_code.':0',
                        'is_offline_sync' => (bool) ($data['is_offline_sync'] ?? false),
                        'offline_synced_at' => ! empty($data['is_offline_sync']) ? ($data['offline_synced_at'] ?? now()) : null,
                    ]);
                }

                if (! empty($data['customer_id']) && $initialStatus === OrderStatus::Paid) {
                    $dppAmount = max(0, (float) $subtotalAmount - (float) ($order->discount_amount ?? 0));
                    $this->onlinePaymentHandler->grantLoyaltyPoints((int) $data['customer_id'], $order, $dppAmount);
                }

                if ($discountId && $discountAmount > 0) {
                    \App\Modules\Pricing\Models\DiscountRedemption::create([
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'discount_id' => $discount->id,
                        'order_id' => $order->id,
                        'customer_id' => $order->customer_id,
                        'redeemed_by_user_id' => $userId,
                        'discount_code_snapshot' => $discount->code,
                        'amount_discounted' => $discountAmount,
                        'status' => \App\Modules\Pricing\Models\DiscountRedemption::STATUS_APPLIED,
                        'redeemed_at' => now(),
                    ]);
                }

                if ($parkedCart) {
                    $parkedCart->update([
                        'status' => ParkedCart::STATUS_CONVERTED,
                        'converted_order_id' => $order->id,
                    ]);
                }

                if (empty($finalPaymentLegs) && $dominantPaymentMethod !== 'cash') {
                    if (! empty($data['use_midtrans_gateway']) && $initialStatus === OrderStatus::Pending) {
                        PaymentAttempt::create([
                            'tenant_id' => $order->tenant_id,
                            'store_id' => $order->store_id,
                            'order_id' => $order->id,
                            'device_id' => $resolvedDeviceId,
                            'shift_id' => $order->shift_id,
                            'idempotency_key' => 'midtrans-init:'.$order->order_code,
                            'provider' => 'midtrans',
                            'payment_method' => (string) $dominantPaymentMethod,
                            'provider_order_id' => $order->order_code,
                            'amount' => (int) $totalAmount,
                            'currency' => 'IDR',
                            'status' => PaymentAttemptStatus::PendingProvider,
                            'initiated_by_user_id' => $userId,
                        ]);
                    }

                    $snapParams = $this->onlinePaymentHandler->buildSnapParams(
                        $order, $subtotalAmount, (float) $discountAmount, $taxAmount, $totalAmount,
                        (string) $dominantPaymentMethod, ! empty($data['customer_id']) ? (int) $data['customer_id'] : null, $serviceChargeAmount
                    );

                    $capturedOrder = $order;
                    $capturedParams = $snapParams;
                    $snapCallback = function () use ($capturedOrder, $capturedParams) {
                        try {
                            $token = $this->onlinePaymentHandler->requestMidtransSnapToken($capturedParams, $capturedOrder);
                            $capturedOrder->forceFill(['snap_token' => $token])->save();
                        } catch (\Throwable $e) {
                            Log::error('[SNAP] gagal membuat token', ['order' => $capturedOrder->order_code, 'error' => $e->getMessage()]);
                            try {
                                $this->onlinePaymentHandler->updateStatusFromMidtransNotification($capturedOrder->order_code, 'cancel', null, (int) $capturedOrder->order_amount);
                            } catch (\Throwable $inner) {
                                Log::critical('[SNAP] gagal membatalkan order setelah Snap gagal', ['order' => $capturedOrder->order_code, 'error' => $inner->getMessage()]);
                            }
                        }
                    };

                    if (DB::transactionLevel() <= 2 && app()->environment('testing')) {
                        $snapCallback();
                    } else {
                        DB::afterCommit($snapCallback);
                    }
                }

                if ($order->order_status === OrderStatus::Paid) {
                    $outbox = app(\App\Modules\Finance\Services\FinancialOutboxDispatcherService::class);
                    in_array($order->order_channel, ['grabfood', 'gofood'], true)
                        ? $outbox->dispatchOfdOrderCompleted($order, (float) $order->platform_commission_amount, (float) $order->merchant_promo_subsidy)
                        : $outbox->dispatchPosOrderPaid($order);
                }

                KdsOrderCreated::dispatch($order);

                return $order;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($data['idempotency_key']) && $tenantId !== null) {
                $existingOrder = Order::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existingOrder) {
                    return $existingOrder;
                }
            }
            throw $e;
        }
    }

    public function payPendingOrder(Order $order, array $rawPaymentLegs, ?int $userId = null, ?int $shiftId = null, ?int $deviceId = null): Order
    {
        return $this->paymentTenderService->payPendingOrder($order, $rawPaymentLegs, $userId, $shiftId, $deviceId);
    }

    public function parsePaymentLegs(array $rawLegs): array
    {
        return $this->paymentTenderService->parsePaymentLegs($rawLegs);
    }

    public function processPaymentLegTenders(Order $order, array $legs, ?int $userId): void
    {
        $this->paymentTenderService->processPaymentLegTenders($order, $legs, $userId);
    }

    public function grantLoyaltyPoints(int $customerId, Order $order, float|int $dppAmount): void
    {
        $this->onlinePaymentHandler->grantLoyaltyPoints($customerId, $order, $dppAmount);
    }
}
