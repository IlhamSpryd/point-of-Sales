<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\Setting;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationBreakdownDto;
use App\Modules\Pricing\Models\Discount;
use App\Modules\Pricing\Services\UnifiedPricingCalculationPipelineService;
use App\Modules\Sales\Application\Services\PosTableAndCustomerSyncService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Table;
use App\Modules\Sales\Services\TransactionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Manages POS cashier checkout submission, multi-tender payments, and Midtrans polling (ADR-108).
 */
trait ManagesPosPaymentAndSplitBill
{
    use ManagesPosSplitBill;

    public float $cashReceived = 0;

    public string $paymentMethod = 'cash';

    /** @var array<int, array{method: string, amount: int, card_number?: string|null, reference_number?: string|null, pin?: string|null}> */
    public array $paymentLegs = [];

    public $paymentTendered = 0;

    public string $giftCardNumberInput = '';

    #[Locked]
    public string $giftCardPinInput = '';

    public float $storeCreditBalance = 0.0;

    public function addGiftCardPaymentLeg(string $cardNumber, int $amount, ?string $pin = null): void
    {
        $this->paymentLegs[] = [
            'method' => 'gift_card', 'amount' => $amount, 'card_number' => $cardNumber,
            'reference_number' => $cardNumber, 'pin' => $pin,
        ];
    }

    public function addStoreCreditPaymentLeg(int $amount): void
    {
        $this->paymentLegs[] = ['method' => 'store_credit', 'amount' => $amount];
    }

    public function getCalculationBreakdown(): CheckoutCalculationBreakdownDto
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $storeId = (int) (app(TenantContext::class)->getStoreId() ?? $this->activeStoreId ?? 1);

        $input = app(\App\Modules\Sales\Application\Services\PosCartCoordinatorService::class)->buildCalculationInput(
            tenantId: $tenantId,
            storeId: $storeId,
            cart: $this->cart,
            customerId: $this->customerId,
            voucherCode: $this->voucherCode ?? null,
            discountId: $this->selectedDiscountId,
            appliedManualDiscount: (float) $this->appliedManualDiscount,
            hasCashPayment: $this->paymentMethod === 'cash' || collect($this->paymentLegs)->contains('method', 'cash')
        );

        return app(UnifiedPricingCalculationPipelineService::class)->calculate($input);
    }

    public function addPaymentLeg(): void
    {
        $this->paymentLegs[] = ['method' => $this->paymentMethod, 'amount' => (int) $this->paymentTendered];
    }

    #[Computed]
    public function canCheckout(): bool
    {
        $shift = $this->activeShift();

        return $shift !== null && $shift->status === 'open' && count($this->cart) > 0;
    }

    #[Computed]
    public function orderTotals(): array
    {
        return app(TransactionService::class)->calculateOrderTotals($this->subtotal, $this->discountAmount);
    }

    #[Computed]
    public function taxAmount(): int
    {
        if ($this->pendingOrderId) {
            $order = Order::find($this->pendingOrderId);
            if ($order) {
                return (int) $order->tax_amount;
            }
        }

        return $this->orderTotals()['tax_amount'];
    }

    #[Computed]
    public function totalAmount(): int
    {
        if ($this->pendingOrderId) {
            $order = Order::find($this->pendingOrderId);
            if ($order) {
                return (int) $order->order_amount;
            }
        }

        return $this->orderTotals()['total_amount'];
    }

    #[Computed]
    public function taxRatePercent(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $rate = $tenantId !== null ? (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11)) : (float) config('pos.tax_rate', 0.11);

        return rtrim(rtrim(number_format($rate * 100, 1), '0'), '.');
    }

    #[Computed]
    public function changeAmount(): int
    {
        return max(0, (int) $this->cashReceived - $this->totalAmount);
    }

    public function fastCashPay(int $amountTendered = 0): void
    {
        if (! $this->canCheckout() || count($this->cart) === 0) {
            return;
        }
        if (! $this->orderType) {
            $this->orderType = 'takeaway';
        }

        $totalDue = $this->totalAmount();
        $tendered = $amountTendered > 0 ? max($amountTendered, $totalDue) : $totalDue;
        $change = max(0, $tendered - $totalDue);

        $legs = [[
            'method' => 'cash',
            'amount' => $totalDue,
            'amount_tendered' => $tendered,
            'change_amount' => $change,
        ]];

        $this->submitOrder($legs, (string) Str::uuid());
    }

    #[Computed]
    public function storeBankAccounts(): Collection
    {
        return app(PosTableAndCustomerSyncService::class)->getStoreBankAccounts(
            app(TenantContext::class)->getTenantId(),
            app(TenantContext::class)->getStoreId() ?? $this->activeStoreId
        );
    }

    #[Computed]
    public function storeEdcTerminals(): Collection
    {
        return app(PosTableAndCustomerSyncService::class)->getStoreEdcTerminals(
            app(TenantContext::class)->getTenantId(),
            app(TenantContext::class)->getStoreId() ?? $this->activeStoreId
        );
    }

    /**
     * @param  array<int, array{method: string, amount: int, amount_tendered?: int|null, change_amount?: int|null, card_number?: string|null, reference_number?: string|null, pin?: string|null, use_midtrans_gateway?: bool|null}>  $legs
     */
    public function submitOrder(array $legs = [], ?string $idempotencyKey = null, bool $useMidtransGateway = false)
    {
        $this->authorize('create', Order::class);

        $authUser = Auth::user();
        if ($authUser?->role?->name === 'Waiter') {
            abort(403, 'Waiters operate in Order-Only mode and cannot process payments or complete checkout.');
        }

        $idempotencyKey = $idempotencyKey ?? $this->idempotencyKey;

        $rules = [
            'orderType' => ['required', 'in:dine_in,takeaway'],
            'cart' => ['required', 'array', 'min:1'],
        ];

        if ($this->pendingOrderId) {
            $rules['cashReceived'] = ['required', 'numeric', 'min:'.$this->totalAmount];
        }

        $currentStoreId = app(TenantContext::class)->getStoreId();
        if ($this->activeStoreId !== null && $currentStoreId !== null && (int) $this->activeStoreId !== (int) $currentStoreId) {
            $this->addError('cart', 'Active branch has changed in your session. Please refresh the page before proceeding.');

            return;
        }

        if (! $this->isTablesEnabled() || $this->orderType !== 'dine_in') {
            $this->tableId = null;
        }

        if ($this->customerId) {
            Customer::findOrFail($this->customerId);
        }
        if ($this->selectedDiscountId) {
            Discount::findOrFail($this->selectedDiscountId);
        }
        if ($this->tableId) {
            Table::where('is_active', true)->findOrFail($this->tableId);
        }

        if (! $this->pendingOrderId && $this->orderType === 'dine_in' && $this->isTablesEnabled()) {
            $rules['tableId'] = ['required'];
        }

        $this->validate($rules);

        if ($idempotencyKey && Order::where('idempotency_key', $idempotencyKey)->exists()) {
            return redirect()->route('transaction.receipt', Order::where('idempotency_key', $idempotencyKey)->value('order_code'));
        }

        $lock = null;
        if ($idempotencyKey) {
            $lock = Cache::lock('order_idempotency_'.$idempotencyKey, 10);
            if (! $lock->get()) {
                $this->addError('cart', 'A similar transaction is currently processing, please try again.');

                return;
            }
        }

        try {
            DB::beginTransaction();

            $transactionService = app(TransactionService::class);
            $shiftId = $transactionService->resolveOpenShiftOrFail((int) Auth::id());

            $normalizedLegs = app(\App\Modules\Sales\Application\Services\PosCartCoordinatorService::class)->normalizePaymentLegs(
                $legs,
                $this->pendingOrderId ? (int) $this->cashReceived : null,
                (int) $this->totalAmount(),
                $this->paymentMethod
            );
            $isMidtransGatewayOrder = $useMidtransGateway || collect($legs)->contains(fn ($l) => ! empty($l['use_midtrans_gateway']));

            if (empty($normalizedLegs)) {
                throw ValidationException::withMessages(['cart' => 'At least one payment method is required before processing the transaction.']);
            }

            $sumLegs = array_sum(array_column($normalizedLegs, 'amount'));
            if ($sumLegs !== (int) $this->totalAmount()) {
                throw ValidationException::withMessages([
                    'cart' => 'Total payments (Rp'.number_format($sumLegs, 0, ',', '.').') do not match the total due (Rp'.number_format($this->totalAmount(), 0, ',', '.').').',
                ]);
            }

            if ($this->pendingOrderId) {
                $tenantId = app(TenantContext::class)->requireTenantId();
                $storeId = app(TenantContext::class)->getStoreId();
                $orderQuery = Order::where('tenant_id', $tenantId);
                if ($storeId) {
                    $orderQuery->where('store_id', $storeId);
                }
                $order = $orderQuery->findOrFail($this->pendingOrderId);
                $transactionService->payPendingOrder($order, $normalizedLegs, (int) Auth::id(), $shiftId, $this->deviceId);
                $isMidtransGatewayOrder = false;
            } else {
                $productIds = collect($this->cart)->pluck('product_id')->unique()->values();
                $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

                $items = [];
                foreach ($this->cart as $raw) {
                    $product = $products->get($raw['product_id']) ?? Product::findOrFail($raw['product_id']);
                    $line = $transactionService->resolveOrderItemLine(
                        $product,
                        $raw['modifier_ids'] ?? [],
                        max(1, (int) ($raw['qty'] ?? 1)),
                        $raw['notes'] ?? null,
                        $raw['product_variant_id'] ?? null,
                        $this->customerId,
                        'pos_cashier',
                        isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                        isset($raw['uom_id']) ? (int) $raw['uom_id'] : null
                    );

                    $items[] = [
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $raw['product_variant_id'] ?? null,
                        'uom_id' => isset($raw['uom_id']) ? (int) $raw['uom_id'] : null,
                        'custom_price' => isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                        'quantity' => $line['qty'],
                        'extra_price' => $line['order_price'] - $product->product_price,
                        'options' => $line['options'],
                        'notes' => $line['notes'] ?? null,
                        'item_discount_amount' => (int) ($raw['item_discount_amount'] ?? 0),
                    ];
                }

                $canUseMidtransSingleLeg = $isMidtransGatewayOrder
                    && count($normalizedLegs) === 1
                    && in_array($normalizedLegs[0]['method'], ['qris', 'ewallet'], true);

                $isOfd = in_array($this->orderType, ['grabfood', 'gofood'], true);
                $originChannel = $isOfd ? $this->orderType : 'pos_cashier';

                $txPayload = [
                    'items' => $items,
                    'customer_id' => $this->customerId,
                    'table_id' => $this->tableId,
                    'order_type' => $isOfd ? 'takeaway' : $this->orderType,
                    'order_channel' => $isOfd ? $this->orderType : 'pos_cashier',
                    'origin_channel' => $originChannel,
                    'external_short_code' => $isOfd ? ($this->externalShortCode ?? null) : null,
                    'driver_name' => $isOfd ? ($this->driverName ?? null) : null,
                    'device_id' => $this->deviceId,
                    'shift_id' => $shiftId,
                    'discount_id' => $this->selectedDiscountId,
                    'manual_discount_amount' => (int) $this->appliedManualDiscount,
                    'approval_id' => $this->manualDiscountApprovalId,
                    'cart_uuid' => $this->voucherCode ? $this->idempotencyKey : null,
                    'idempotency_key' => $idempotencyKey,
                ];

                if ($canUseMidtransSingleLeg) {
                    $txPayload['payment_method'] = $normalizedLegs[0]['method'];
                    $txPayload['use_midtrans_gateway'] = true;
                } else {
                    $txPayload['payments'] = $normalizedLegs;
                    $isMidtransGatewayOrder = false;
                }

                $order = $transactionService->createTransaction($txPayload, (int) Auth::id());
            }

            DB::commit();

            if ($isMidtransGatewayOrder) {
                $order->refresh();
                $this->dispatch(
                    'midtrans-payment-pending',
                    orderCode: $order->order_code,
                    snapToken: $order->snap_token,
                    totalAmount: (int) $order->order_amount,
                    syncUrl: route('api.order.sync-status', ['order_number' => $order->order_code])
                );
            } else {
                $paidAmount = (int) $order->payments()->sum('amount_tendered');
                $changeAmount = (int) $order->payments()->sum('change_amount');
                $this->dispatch(
                    'transaction-success',
                    orderCode: $order->order_code,
                    isCash: collect($normalizedLegs)->contains('method', 'cash'),
                    paidAmount: $paidAmount > 0 ? $paidAmount : (int) $order->order_amount,
                    changeAmount: $changeAmount
                );
            }

            $this->cart = [];
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->orderType = null;
            $this->tableId = null;
            $this->customerId = null;
            $this->customerName = null;
            $this->selectedDiscountId = null;
            $this->appliedManualDiscount = 0;
            $this->appliedManualDiscountReason = '';
            $this->manualDiscountApprovalId = null;
            $this->voucherCode = null;
            $this->voucherInput = '';
            $this->voucherError = null;
            $this->resumeWarnings = [];
            $this->pendingOrderId = null;
            $this->pendingOrderCode = null;
            $this->cashReceived = 0;
            $this->idempotencyKey = (string) Str::uuid();

            return;

        } catch (\Exception $e) {
            DB::rollBack();
            if ($e instanceof ValidationException) {
                $this->addError('cart', collect($e->errors())->flatten()->first());
            } elseif ($e instanceof \DomainException) {
                $this->addError('cart', $e->getMessage());
            } else {
                Log::error('CreateOrder submitOrder error: '.$e->getMessage(), ['exception' => $e]);
                $this->addError('cart', 'A system error occurred while processing the transaction. Please try again.');
            }

            return;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    public function verifyMidtransPayment(string $orderCode): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $query = Order::where('tenant_id', $tenantId)->where('order_code', $orderCode);
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $order = $query->first();
        if (! $order) {
            $this->dispatch('toast', message: 'Order not found.', type: 'error');

            return;
        }

        if ($order->order_status === OrderStatus::Pending) {
            try {
                $order = app(TransactionService::class)->syncOrderWithMidtrans($order);
            } catch (\Throwable $e) {
                Log::warning('verifyMidtransPayment sync failed: '.$e->getMessage(), ['order_code' => $orderCode]);
            }
        }

        $order->refresh();

        if ($order->order_status === OrderStatus::Paid) {
            $this->cart = [];
            $this->customerId = null;
            $this->customerName = null;
            $this->selectedDiscountId = null;
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->dispatch(
                'transaction-success',
                orderCode: $order->order_code,
                isCash: false,
                paidAmount: (int) $order->order_amount,
                changeAmount: 0
            );
            $this->dispatch('toast', message: "Payment confirmed for {$order->order_code}.", type: 'success');

            return;
        }

        if ($order->order_status->isFinal()) {
            $this->dispatch('midtrans-payment-failed', orderCode: $order->order_code, status: $order->order_status->value);
            $this->dispatch('toast', message: "Payment status is {$order->order_status->label()} for {$order->order_code}.", type: 'error');

            return;
        }

        $this->dispatch('midtrans-payment-still-pending', orderCode: $order->order_code);
    }
}
