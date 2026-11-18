<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\ParkedCart;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Models\Table;
use App\Modules\Sales\Services\CartService;
use App\Modules\Subscription\Services\EntitlementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Manages POS cashier table occupancy, shift resolution, parked carts lifecycle,
 * and pending self-orders retrieval (ADR-108).
 */
trait ManagesPosShiftAndTables
{
    public ?int $tableId = null;

    #[Locked]
    public ?int $deviceId = null;

    #[Locked]
    public ?int $shiftId = null;

    #[Locked]
    public ?int $activeStoreId = null;

    #[Locked]
    public ?string $pendingOrderCode = null;

    #[Locked]
    public ?int $pendingOrderId = null;

    public bool $isParkModalOpen = false;

    public bool $isParkedListOpen = false;

    public string $holdReference = '';

    public string $holdLabel = '';

    public string $holdNotes = '';

    public array $resumeWarnings = [];

    public function loadPendingOrder(string $orderCode): void
    {
        $order = Order::with('orderItems.product')
            ->where('order_code', $orderCode)
            ->where('order_status', 'pending')
            ->where('payment_method', 'cash')
            ->first();

        if (! $order) {
            $this->dispatch('toast', message: 'Order not found or has already been paid.', type: 'error');

            return;
        }

        $this->cart = [];
        foreach ($order->orderItems as $item) {
            $this->cart[] = [
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'modifier_ids' => collect($item->options)->pluck('modifier_id')->filter()->values()->toArray(),
                'notes' => $item->notes,
            ];
        }

        $this->customerId = null;
        $this->customerName = null;
        $this->selectedDiscountId = null;

        $this->pendingOrderCode = $order->order_code;
        $this->pendingOrderId = $order->id;
        $this->orderType = $order->order_type?->value;
        $this->tableId = $order->table_id;

        $this->dispatch('toast', message: 'Order '.$orderCode.' retrieved successfully.', type: 'success');
    }

    public function cancelPendingOrderMode(): void
    {
        $this->pendingOrderCode = null;
        $this->pendingOrderId = null;
        $this->cart = [];
        $this->dispatch('pos-cart-state-changed', dirty: false);
        $this->orderType = null;
        $this->tableId = null;
        $this->customerId = null;
        $this->customerName = null;
        $this->selectedDiscountId = null;
        $this->idempotencyKey = (string) Str::uuid();
    }

    #[Computed]
    public function isTablesEnabled(): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();
        if (! $tenantId || ! $storeId) {
            return false;
        }

        return app(EntitlementService::class)->isTablesEnabled($tenantId, $storeId);
    }

    #[Computed]
    public function activeTables()
    {
        if (! $this->isTablesEnabled()) {
            return collect();
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $query = Table::query()
            ->where('is_active', true)
            ->where('operational_status', '!=', 'disabled');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return $query->orderBy('table_name')->get();
    }

    public function parkCurrentCart(): void
    {
        if (empty($this->cart)) {
            $this->addError('cart', 'Cart is empty and cannot be parked.');

            return;
        }

        $user = Auth::user();
        $activeShift = Shift::where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->where('status', 'open')->first();

        if (! $activeShift) {
            $this->addError('cart', 'POS terminal does not have an open register shift.');

            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        $cartState = [
            'items' => $this->cart,
            'order_type' => $this->orderType ?? 'dine_in',
            'table_id' => $this->tableId,
            'customer_id' => $this->customerId,
            'discount_id' => $this->selectedDiscountId,
            'notes' => $this->holdNotes ?: null,
        ];

        try {
            $storeId = $this->resolveActiveStoreId($activeShift->store_id ?? $this->activeStoreId, $tenantId);
            $deviceId = $this->resolveActiveDeviceId($this->deviceId, $activeShift->device_id, $storeId, $tenantId);
            $label = ! empty($this->holdLabel) ? $this->holdLabel : (! empty($this->holdReference) ? $this->holdReference : null);
            $parked = app(CartService::class)->parkCart(
                $tenantId,
                $storeId,
                $deviceId,
                $activeShift->id,
                (int) $user->id,
                $cartState,
                $label
            );

            $this->cart = [];
            $this->holdReference = '';
            $this->holdLabel = '';
            $this->holdNotes = '';
            $this->isParkModalOpen = false;
            $this->dispatch('pos-cart-state-changed', dirty: false);
            $this->dispatch('toast', message: "Order parked successfully (Ref: {$parked->hold_reference})", type: 'success');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
        }
    }

    public function resumeCart(int $parkedCartId): void
    {
        $user = Auth::user();
        $activeShift = Shift::where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->where('status', 'open')->first();

        if (! $activeShift) {
            $this->addError('cart', 'Register shift must be active to resume parked orders. Silakan buka shift kasir terlebih dahulu.');
            $this->dispatch('toast', message: 'Register shift must be active to resume parked orders.', type: 'error');

            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        if ($parkedCartId <= 0) {
            $latestParked = ParkedCart::where('status', ParkedCart::STATUS_PARKED)->latest()->first();
            if ($latestParked) {
                $parkedCartId = (int) $latestParked->id;
            }
        }

        try {
            $storeId = $this->resolveActiveStoreId($activeShift->store_id ?? $this->activeStoreId, $tenantId);
            $deviceId = $this->resolveActiveDeviceId($this->deviceId, $activeShift->device_id, $storeId, $tenantId);
            $result = app(CartService::class)->resumeParkedCart(
                $parkedCartId,
                $tenantId,
                $storeId,
                $deviceId,
                $activeShift->id,
                (int) $user->id
            );

            $payload = $result['cart'];
            $items = $payload['items'] ?? $payload;

            $this->cart = array_values($items);
            if (isset($payload['order_type'])) {
                $this->orderType = $payload['order_type'];
            }
            if (isset($payload['table_id'])) {
                $this->tableId = $payload['table_id'];
            }
            if (isset($payload['customer_id'])) {
                $this->customerId = $payload['customer_id'];
            }
            if (isset($payload['discount_id'])) {
                $this->selectedDiscountId = $payload['discount_id'];
            }

            $this->resumeWarnings = $result['warnings'];
            $this->isParkedListOpen = false;
            $this->dispatch('pos-cart-state-changed', dirty: true);
            $this->dispatch('toast', message: 'Parked order resumed successfully.', type: 'success');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function discardHeldCart(int $parkedCartId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        try {
            $storeId = $this->resolveActiveStoreId($this->activeStoreId, $tenantId);
            app(CartService::class)->discardParkedCart($parkedCartId, $tenantId, $storeId, (int) Auth::id());
            $this->dispatch('toast', message: 'Parked order discarded.', type: 'info');
        } catch (\Throwable $e) {
            $this->addError('cart', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    #[Computed]
    public function parkedCarts()
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();
        if (! $tenantId || ! $storeId) {
            return collect();
        }

        return app(CartService::class)->getParkedCarts($tenantId, $storeId);
    }

    #[Computed]
    public function activeShift(): ?Shift
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return Shift::with('device')->where(function ($q) use ($user) {
            $q->where('active_cashier_id', $user->id)
                ->orWhere(function ($sub) use ($user) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                });
        })->whereIn('status', ['open', 'handover_pending', 'closing'])->first();
    }
}
