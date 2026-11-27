<?php

declare(strict_types=1);

namespace App\Livewire\Kasir;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Kasir\Concerns\ManagesPosCartAndVariants;
use App\Livewire\Kasir\Concerns\ManagesPosPaymentAndSplitBill;
use App\Livewire\Kasir\Concerns\ManagesPosPromotionsAndCustomer;
use App\Livewire\Kasir\Concerns\ManagesPosShiftAndTables;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\MenuCacheService;
use App\Modules\Sales\Models\Shift;
use App\Support\Traits\RequiresActiveStoreContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * POS Cashier Order Creation Component (DEC-001, ADR-108).
 * Decomposed into focused Livewire domain concerns (< 450 lines).
 */
#[Layout('layouts.app', ['noPadding' => true])]
class CreateOrder extends Component
{
    use ManagesPosCartAndVariants;
    use ManagesPosPaymentAndSplitBill;
    use ManagesPosPromotionsAndCustomer;
    use ManagesPosShiftAndTables;
    use RequiresActiveStoreContext;
    use RequiresTenantContext;

    public ?string $orderType = null;

    public ?string $externalShortCode = null;

    public ?string $driverName = null;

    public ?string $driverPhone = null;

    public float $merchantPromoSubsidy = 0.00;

    public string $search = '';

    #[Locked]
    public string $idempotencyKey;

    protected function allowsSuspendedTenantAccess(): bool
    {
        return request()->is('*orders/*/pay*', '*kasir/shift/close*', '*tables/*/release*')
            || $this->pendingOrderId !== null;
    }

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $authUser = Auth::user();
        if (! $authUser) {
            abort(403, 'You do not have permission to access the POS terminal.');
        }

        $role = $authUser->role;
        $roleName = $role?->name;
        $isCanonicalCashier = (bool) ($role?->is_admin) || in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Admin'], true);
        $isAuthorizedWaiter = $roleName === 'Waiter' && ((bool) $role->hasPermission('orders.create') || (bool) $role->hasPermission('can_create_order'));

        if (! $isCanonicalCashier && ! $isAuthorizedWaiter) {
            abort(403, 'You do not have permission to access the POS terminal.');
        }

        if ($authUser->pin_hash === null) {
            session()->flash('warning', 'Cashiers must configure a PIN before opening a register shift or processing transactions.');
        }
    }

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
        $this->activeStoreId = app(TenantContext::class)->getStoreId();

        $defaultChannel = 'dine_in';
        if ($this->activeStoreId) {
            $storeChannel = \Illuminate\Support\Facades\DB::table('stores')
                ->where('id', $this->activeStoreId)
                ->value('default_channel');
            if (! empty($storeChannel) && in_array($storeChannel, ['dine_in', 'takeaway', 'counter_retail', 'delivery', 'grabfood', 'gofood'], true)) {
                $defaultChannel = $storeChannel;
            }
        }
        $this->orderType = $defaultChannel;

        $user = Auth::user();
        if ($user) {
            $activeShift = Shift::where(function ($q) use ($user) {
                $q->where('active_cashier_id', $user->id)
                    ->orWhere(function ($sub) use ($user) {
                        $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                    });
            })->where('status', 'open')->first();

            if ($activeShift) {
                $this->shiftId = $activeShift->id;
                $this->deviceId = $activeShift->device_id;
            }
        }
    }

    public function updatedOrderType(): void
    {
        if ($this->orderType !== 'dine_in' || ! $this->isTablesEnabled()) {
            $this->tableId = null;
        }
    }

    public function setOrderType(string $type): void
    {
        $allowed = ['dine_in', 'takeaway', 'counter_retail', 'delivery', 'grabfood', 'gofood'];
        $this->orderType = in_array($type, $allowed, true) ? $type : 'dine_in';
        if (! in_array($this->orderType, ['grabfood', 'gofood'], true)) {
            $this->externalShortCode = null;
            $this->driverName = null;
            $this->driverPhone = null;
            $this->merchantPromoSubsidy = 0.00;
        }
        $this->updatedOrderType();
    }

    public function isOfd(): bool
    {
        return in_array($this->orderType, ['grabfood', 'gofood'], true);
    }

    public function supportsFnb(): bool
    {
        $storeId = $this->activeStoreId ?? app(TenantContext::class)->getStoreId();
        if ($storeId) {
            $store = \App\Modules\Organization\Models\Store::find($storeId);
            if ($store) {
                return $store->supportsFnb();
            }
        }
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId) {
            $tenant = \App\Modules\Organization\Models\Tenant::find($tenantId);
            if ($tenant) {
                return $tenant->supportsFnb();
            }
        }

        return true;
    }

    #[Computed]
    public function ofdConfig(): ?\App\Modules\Integration\Models\OfdChannelConfig
    {
        if (! $this->isOfd()) {
            return null;
        }

        $user = \Illuminate\Support\Facades\Auth::user();
        $userStoreId = $user ? (int) $user->store_id : 0;
        $storeId = (int) (app(\App\Core\Context\TenantContext::class)->getStoreId() ?? $userStoreId);

        return \App\Modules\Integration\Models\OfdChannelConfig::query()
            ->where('provider', $this->orderType)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->where('is_active', true)
            ->first();
    }

    #[Computed]
    public function ofdCommissionRate(): float
    {
        $config = $this->ofdConfig();

        return $config ? (float) $config->commission_rate_pct : 20.00;
    }

    #[Computed]
    public function estimatedOfdCommission(): float
    {
        return round($this->subtotal() * ($this->ofdCommissionRate() / 100), 2);
    }

    #[Computed]
    public function estimatedOfdNetPayout(): float
    {
        $tax = (float) $this->taxAmount();
        $subtotal = (float) $this->subtotal();
        $promo = (float) $this->merchantPromoSubsidy;
        $commission = (float) $this->estimatedOfdCommission();

        return max(0, round($subtotal + $tax - $promo - $commission, 2));
    }

    public function updatedSearch(): void
    {
        if (str_starts_with(strtoupper($this->search), 'POS-')) {
            $this->loadPendingOrder(strtoupper($this->search));
            $this->search = '';
        }
    }

    #[Computed]
    public function categories()
    {
        return app(MenuCacheService::class)->getCatalog();
    }

    public function render()
    {
        $selectingProduct = null;
        $variantStocks = [];
        if ($this->selectingProductId) {
            $selectingProduct = Product::with([
                'modifierGroups.modifiers',
                'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues'),
                'unitConversions.fromUom',
                'unitConversions.toUom',
                'baseUom',
                'ingredients',
                'category',
            ])->find($this->selectingProductId);

            if ($selectingProduct && $selectingProduct->variants->isNotEmpty()) {
                $variantStocks = $this->getVariantStocks($selectingProduct);
            }
        }

        $selectingVariantProduct = null;
        if ($this->selectingVariantProductId) {
            $selectingVariantProduct = $selectingProduct ?? Product::with([
                'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues'),
            ])->find($this->selectingVariantProductId);
        }

        return view('livewire.kasir.create-order', compact('selectingProduct', 'selectingVariantProduct', 'variantStocks'));
    }
}
