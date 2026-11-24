<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Handlers;

use App\Core\Context\TenantContext;
use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\LoyaltyLedgerTypeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\StockMovementType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\BundleComponentExpanderService;
use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Pricing\Models\Discount;
use App\Modules\Pricing\Models\DiscountRedemption;
use App\Modules\Pricing\Models\PromotionReservation;
use App\Modules\Pricing\Services\VoucherService;
use App\Modules\Sales\Application\Services\PosTableAndCustomerSyncService;
use App\Modules\Sales\Domain\StateMachines\OrderStateMachine;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Services\TransactionService;
use DomainException;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles voiding of orders, stock restorations, ingredient reversals, and loyalty rollbacks (ADR-108 / ADR-109).
 */
class VoidAndRestoreOrderHandler
{
    public function __construct(
        protected PosTableAndCustomerSyncService $tableSyncService,
        protected PaymentGatewayInterface $paymentGateway
    ) {}

    public function voidOrder(Order $order, string $reason, int $userId): void
    {
        $tenantContext = app(TenantContext::class);
        $currentTenantId = $tenantContext->requireTenantId();

        if ((int) $order->tenant_id !== (int) $currentTenantId) {
            throw new DomainException('Unauthorized cross-tenant void attempt.');
        }

        $tenantContext->runAs((int) $order->tenant_id, $order->store_id, function () use ($order, $reason, $userId) {
            $timeoutAttempts = PaymentAttempt::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->whereIn('status', [PaymentAttemptStatus::UnknownTimeout, PaymentAttemptStatus::PendingProvider])
                ->get();

            foreach ($timeoutAttempts as $attempt) {
                if ($attempt->provider === 'midtrans') {
                    try {
                        $checkId = $attempt->provider_order_id ?? $order->order_code;
                        $gatewayStatus = app(TransactionService::class)->getMidtransTransactionStatus($checkId);
                        $txStatus = (string) ($gatewayStatus->transaction_status ?? '');
                        if (in_array($txStatus, ['capture', 'settlement'], true)) {
                            app(TransactionService::class)->settlePaymentAttempt($attempt, [
                                'transaction_id' => $gatewayStatus->transaction_id ?? null,
                                'transaction_status' => $txStatus,
                                'gross_amount' => $gatewayStatus->gross_amount ?? null,
                            ]);
                            throw new DomainException('Cannot void order: payment transaction has already settled with payment gateway.');
                        }
                    } catch (DomainException $de) {
                        throw $de;
                    } catch (\Throwable $e) {
                        Log::warning("Gagal rekonsiliasi status gateway sebelum void: {$e->getMessage()}");
                    }
                }
            }

            OrderStateMachine::assertTransition($order->order_status, OrderStatus::Void, 'Order void request');

            if ($order->order_status !== OrderStatus::Paid) {
                throw new Exception('Only Paid orders can be voided.');
            }

            $capturedPayments = Payment::where('order_id', $order->id)->where('status', PaymentStatusEnum::Captured)->get();
            $hasNonCash = false;

            if ($capturedPayments->isNotEmpty()) {
                foreach ($capturedPayments as $payment) {
                    if ($payment->payment_method !== PaymentMethod::Cash) {
                        $hasNonCash = true;
                        break;
                    }
                }
            } else {
                if ($order->payment_method !== PaymentMethod::Cash) {
                    $hasNonCash = true;
                }
            }

            if ($hasNonCash) {
                throw new DomainException('Voiding non-cash orders is not supported. Use the standard refund procedure.');
            }

            DB::transaction(function () use ($order, $reason, $userId) {
                $lockedOrder = Order::withoutGlobalScopes()->where('id', $order->id)->lockForUpdate()->first();

                if (! $lockedOrder || $lockedOrder->order_status !== OrderStatus::Paid) {
                    throw new Exception('This order is no longer in Paid status (it may have been voided by another process).');
                }

                $lockedOrder->forceFill([
                    'order_status' => OrderStatus::Void,
                    'voided_by' => $userId,
                    'void_reason' => $reason,
                    'voided_at' => now(),
                ])->save();

                $this->restoreStockForOrder($lockedOrder);
                $this->tableSyncService->syncTableStatusOnOrderFinalized($lockedOrder);

                $existingPayments = Payment::where('order_id', $lockedOrder->id)
                    ->where('status', PaymentStatusEnum::Captured)
                    ->get();

                foreach ($existingPayments as $i => $payment) {
                    Payment::forceCreate([
                        'tenant_id' => $lockedOrder->tenant_id,
                        'store_id' => $lockedOrder->store_id,
                        'device_id' => $payment->device_id,
                        'shift_id' => $payment->shift_id,
                        'order_id' => $lockedOrder->id,
                        'payment_method' => $payment->payment_method,
                        'amount' => $payment->amount,
                        'reference_number' => 'VOID-'.($payment->reference_number ?? $payment->idempotency_key),
                        'status' => PaymentStatusEnum::Voided,
                        'captured_at' => null,
                        'processed_by' => $userId,
                        'idempotency_key' => 'void:'.$lockedOrder->order_code.':'.$i,
                    ]);
                }

                if ($lockedOrder->customer_id) {
                    $this->reverseLoyaltyPointsForOrder($lockedOrder, $userId);
                }

                if ($lockedOrder->discount_id) {
                    Discount::where('id', $lockedOrder->discount_id)->lockForUpdate()->first();

                    DiscountRedemption::where('order_id', $lockedOrder->id)
                        ->where('status', DiscountRedemption::STATUS_APPLIED)
                        ->update([
                            'status' => DiscountRedemption::STATUS_VOIDED_REVERSED,
                            'reversed_at' => now(),
                        ]);
                }

                app(VoucherService::class)->restoreVoucherForOrder($lockedOrder->id);

                PromotionReservation::where('order_id', $lockedOrder->id)
                    ->where('status', PromotionReservation::STATUS_COMMITTED)
                    ->update(['status' => PromotionReservation::STATUS_RELEASED]);

                $cashPayments = $existingPayments->filter(
                    fn ($p) => $p->payment_method === PaymentMethod::Cash
                );
                $totalCashPaid = (int) $cashPayments->sum('amount');
                if ($totalCashPaid > 0) {
                    $openShift = \App\Modules\Sales\Models\Shift::withoutGlobalScopes()
                        ->where('tenant_id', $lockedOrder->tenant_id)
                        ->where('store_id', $lockedOrder->store_id)
                        ->where('status', 'open')
                        ->latest('id')
                        ->first();

                    if ($openShift) {
                        \App\Modules\Sales\Models\CashDrawerMovement::forceCreate([
                            'tenant_id' => $lockedOrder->tenant_id,
                            'store_id' => $lockedOrder->store_id,
                            'device_id' => $openShift->device_id,
                            'shift_id' => $openShift->id,
                            'type' => \App\Enums\CashDrawerMovementTypeEnum::CashOut,
                            'category' => \App\Enums\CashDrawerMovementCategoryEnum::Refund,
                            'amount' => $totalCashPaid,
                            'reason' => "Void order #{$lockedOrder->order_code}: pengeluaran kas kembali ke pelanggan",
                            'receipt_reference' => $lockedOrder->order_code,
                            'created_by' => $userId,
                            'approved_by' => $userId,
                            'idempotency_key' => "void_cdm:{$lockedOrder->order_code}:{$lockedOrder->id}",
                        ]);
                    }
                }

                app(\App\Modules\Finance\Services\FinancialOutboxDispatcherService::class)->dispatchPosOrderVoided($lockedOrder);

                \App\Modules\Organization\Models\ActivityLog::create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'user_id' => $userId,
                    'action' => 'VOID_ORDER',
                    'description' => "Void order {$lockedOrder->order_code}. Reason: {$reason}",
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => [
                        'order_id' => $lockedOrder->id,
                        'total_amount' => $lockedOrder->order_amount,
                    ],
                ]);
            });
        });
    }

    public function reverseLoyaltyPointsForOrder(Order $order, int $userId): void
    {
        $earnedEntry = \App\Modules\Pricing\Models\LoyaltyLedger::where('order_id', $order->id)
            ->where('type', LoyaltyLedgerTypeEnum::Earn)
            ->first();

        if (! $earnedEntry || $earnedEntry->points <= 0) {
            return;
        }

        $alreadyReversed = \App\Modules\Pricing\Models\LoyaltyLedger::where('order_id', $order->id)
            ->where('type', LoyaltyLedgerTypeEnum::Reversal)
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $account = \App\Modules\Pricing\Models\CustomerLoyaltyAccount::lockAndGetAccount((int) $order->customer_id);
        $currentPoints = $account !== null ? $account->current_points : 0;
        $newBalance = max(0, $currentPoints - $earnedEntry->points);

        \App\Modules\Pricing\Models\LoyaltyLedger::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'type' => LoyaltyLedgerTypeEnum::Reversal,
            'points' => -$earnedEntry->points,
            'balance_after' => $newBalance,
            'reference' => "Pembalikan poin: order {$order->order_code} di-void.",
            'created_by' => $userId,
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
        ]);

        $account?->refresh();
    }

    public function restoreStockForOrder(Order $order): void
    {
        $items = $order->orderItems()->get();

        if ($items->isEmpty()) {
            return;
        }

        $orderStoreId = $order->store_id;
        $userId = $order->voided_by ?? $order->user_id ?? (auth()->check() ? auth()->id() : null);

        $productIds = $items->pluck('product_id')->unique()->sort()->values();

        $products = Product::withTrashed()
            ->with('ingredients')
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if (! $product) {
                Log::critical('Stock restore GAGAL: produk tidak ditemukan.', [
                    'order_code' => $order->order_code,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                ]);

                continue;
            }

            if ($product->isBundle()) {
                $bundleExpander = app(BundleComponentExpanderService::class);
                $components = $bundleExpander->expand($product, (int) $item->qty);
                $inventoryLedger = app(InventoryLedgerService::class);

                foreach ($components as $comp) {
                    /** @var Product $compProd */
                    $compProd = $comp['component_product'];
                    $compQty = (float) $comp['quantity'];

                    if ($compProd->ingredients()->exists()) {
                        $compProd->loadMissing('ingredients');
                        foreach ($compProd->ingredients as $ingredient) {
                            $needed = bcmul((string) $ingredient->pivot->quantity_required, (string) $compQty, 4);
                            $inventoryLedger->recordMovement(
                                tenantId: (int) $order->tenant_id,
                                storeId: (int) $orderStoreId,
                                itemType: 'ingredient',
                                itemId: (int) $ingredient->id,
                                quantityDelta: (float) $needed,
                                type: StockMovementType::RestoreCompensation,
                                unitCost: $ingredient->cost_per_unit ? (float) $ingredient->cost_per_unit : null,
                                reason: "Automatic stock compensation for bundle component {$compProd->product_name}",
                                idempotencyKey: "stock_restore:{$order->order_code}:{$item->id}:bundle_ing:{$ingredient->id}",
                                orderId: (int) $order->id,
                                orderItemId: (int) $item->id,
                                userId: $userId
                            );
                        }
                    } elseif ($compProd->track_inventory && ! $compProd->isService() && ! $compProd->isNonInventory()) {
                        $inventoryLedger->recordMovement(
                            tenantId: (int) $order->tenant_id,
                            storeId: (int) $orderStoreId,
                            itemType: 'product',
                            itemId: (int) $compProd->id,
                            quantityDelta: $compQty,
                            type: StockMovementType::RestoreCompensation,
                            unitCost: $compProd->standard_cost ? (float) $compProd->standard_cost : null,
                            reason: "Automatic stock compensation for bundle component {$compProd->product_name}",
                            idempotencyKey: "stock_restore:{$order->order_code}:{$item->id}:bundle_comp:{$compProd->id}",
                            orderId: (int) $order->id,
                            orderItemId: (int) $item->id,
                            userId: $userId
                        );
                    }
                }

                continue;
            }

            $hasBom = $product->ingredients->isNotEmpty();
            if ($hasBom || ! $product->track_inventory || $product->isService() || $product->isNonInventory()) {
                continue;
            }

            $idempotencyKey = 'stock_restore:'.$order->order_code.':'.$item->id;

            app(InventoryLedgerService::class)->recordMovement(
                tenantId: (int) $order->tenant_id,
                storeId: (int) $orderStoreId,
                itemType: 'product',
                itemId: (int) $product->id,
                quantityDelta: (float) $item->qty,
                type: StockMovementType::RestoreCompensation,
                unitCost: $product->standard_cost ? (float) $product->standard_cost : null,
                reason: "Automatic stock compensation: order {$order->order_code} transitioned to {$order->order_status->value}.",
                idempotencyKey: $idempotencyKey,
                orderId: (int) $order->id,
                orderItemId: (int) $item->id,
                userId: $userId
            );
        }

        $deductions = IngredientStockMovement::where('order_id', $order->id)
            ->where('type', IngredientStockMovementTypeEnum::SaleDeduction)
            ->get();

        if ($deductions->isNotEmpty()) {
            $ingredientIds = $deductions->pluck('ingredient_id')->unique()->sort()->values();

            $lockedIngredients = Ingredient::whereIn('id', $ingredientIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($deductions as $deduction) {
                $ingredient = $lockedIngredients->get($deduction->ingredient_id);
                if (! $ingredient) {
                    continue;
                }

                $idempotencyKey = 'ingredient_restore:'.$order->order_code.':'.$deduction->id;

                app(InventoryLedgerService::class)->recordMovement(
                    tenantId: (int) $order->tenant_id,
                    storeId: (int) $orderStoreId,
                    itemType: 'ingredient',
                    itemId: (int) $ingredient->id,
                    quantityDelta: abs((float) $deduction->quantity),
                    type: IngredientStockMovementTypeEnum::RestoreCompensation,
                    unitCost: $ingredient->cost_per_unit ? (float) $ingredient->cost_per_unit : null,
                    reason: "Automatic ingredient compensation: order {$order->order_code} transitioned to {$order->order_status->value}.",
                    idempotencyKey: $idempotencyKey,
                    orderId: (int) $order->id,
                    orderItemId: (int) $deduction->order_item_id,
                    userId: $userId
                );
            }
        }
    }

    /**
     * @param  array<int, int|string>  $ingredientIds
     * @return Collection<(int|string), \stdClass>
     */
    public function lockIngredientBalances(array $ingredientIds, ?int $storeId): Collection
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        if (empty($ingredientIds) || $storeId === null) {
            return collect();
        }

        $ids = array_map('intval', $ingredientIds);
        sort($ids);

        return DB::table('ingredient_stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('ingredient_id', $ids)
            ->orderBy('ingredient_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('ingredient_id');
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return Collection<(int|string), \stdClass>
     */
    public function lockProductBalances(array $productIds, ?int $storeId): Collection
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        if (empty($productIds) || $storeId === null) {
            return collect();
        }

        $ids = array_map('intval', $productIds);
        sort($ids);

        return DB::table('product_stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('product_id', $ids)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
    }

    public function applyProductBalanceDelta(Product $product, ?object $balance, string $delta): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = $balance->store_id ?? app(TenantContext::class)->getStoreId();

        if ($storeId === null) {
            throw new \RuntimeException('Active store not available for product stock balance update.');
        }

        $type = bccomp(bc_num($delta), '0', 4) >= 0 ? StockMovementType::RestoreCompensation : StockMovementType::SaleDeduction;

        app(InventoryLedgerService::class)->recordMovement(
            tenantId: $tenantId,
            storeId: (int) $storeId,
            itemType: 'product',
            itemId: (int) $product->id,
            quantityDelta: (float) $delta,
            type: $type,
            unitCost: $product->standard_cost ? (float) $product->standard_cost : null,
            reason: 'Product stock balance delta update via VoidAndRestoreOrderHandler.'
        );
    }

    public function applyIngredientBalanceDelta(Ingredient $ingredient, ?object $balance, string $delta): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = $balance->store_id ?? app(TenantContext::class)->getStoreId();

        if ($storeId === null) {
            throw new \RuntimeException('Active store not available for ingredient stock balance update.');
        }

        $type = bccomp(bc_num($delta), '0', 4) >= 0 ? IngredientStockMovementTypeEnum::RestoreCompensation : IngredientStockMovementTypeEnum::SaleDeduction;

        app(InventoryLedgerService::class)->recordMovement(
            tenantId: $tenantId,
            storeId: (int) $storeId,
            itemType: 'ingredient',
            itemId: (int) $ingredient->id,
            quantityDelta: (float) $delta,
            type: $type,
            unitCost: $ingredient->cost_per_unit ? (float) $ingredient->cost_per_unit : null,
            reason: 'Ingredient stock balance delta update via VoidAndRestoreOrderHandler.'
        );
    }
}
