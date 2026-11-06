<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Concerns\WithSorting;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PurchaseOrderManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;
    use WithSorting;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    #[Url(history: true)]
    public ?int $supplierFilter = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierFilter(): void
    {
        $this->resetPage();
    }

    // Modal State
    public bool $showCreateModal = false;

    public bool $showDetailModal = false;

    // Detail PO State
    #[Locked]
    public ?int $selectedPoId = null;

    public ?PurchaseOrder $selectedPo = null;

    // Form Header
    public ?int $storeId = null;

    public ?int $supplierId = null;

    public string $orderDate = '';

    public string $expectedDeliveryDate = '';

    public string $notes = '';

    // Form Items
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $items = [];

    // Cancellation Reason
    public string $cancelReason = '';

    public bool $showCancelModal = false;

    public function mount(): void
    {
        $this->orderDate = now()->toDateString();
        $this->expectedDeliveryDate = now()->addDays(7)->toDateString();
        $this->storeId = app(TenantContext::class)->getStoreId();
    }

    public function updatedSupplierId(): void
    {
        $this->items = [];
        $this->addItem();
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', PurchaseOrder::class);

        $this->resetValidation();
        $this->orderDate = now()->toDateString();
        $this->expectedDeliveryDate = now()->addDays(7)->toDateString();
        $this->notes = '';
        $this->storeId = app(TenantContext::class)->getStoreId() ?: (Store::first()?->id);
        $this->supplierId = null;
        $this->items = [];
        $this->addItem();

        $this->showCreateModal = true;
    }

    public function addItem(): void
    {
        $this->items[] = [
            'purchasable_type' => 'product',
            'purchasable_id' => '',
            'purchase_uom' => 'PCS',
            'uom_conversion_factor' => '1.0000',
            'ordered_qty' => '1',
            'unit_price' => '0',
            'discount_pct' => '0',
            'tax_rate' => '0',
            'notes' => '',
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (empty($this->items)) {
            $this->addItem();
        }
    }

    public function onItemTypeChanged(int $index): void
    {
        $this->items[$index]['purchasable_id'] = '';
        $this->items[$index]['purchase_uom'] = ($this->items[$index]['purchasable_type'] === 'ingredient') ? 'KG' : 'PCS';
    }

    public function onItemSelected(int $index): void
    {
        $itemId = (int) ($this->items[$index]['purchasable_id'] ?? 0);
        $type = $this->items[$index]['purchasable_type'] ?? 'product';

        if ($itemId <= 0 || ! $this->supplierId) {
            return;
        }

        // Cari tahu apakah ada di katalog pemasok terpilih
        $catalogItem = SupplierProduct::query()
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->where('supplier_id', $this->supplierId)
            ->where('purchasable_type', $type)
            ->where('purchasable_id', $itemId)
            ->where('is_active', true)
            ->first();

        if ($catalogItem) {
            $this->items[$index]['purchase_uom'] = $catalogItem->purchase_uom;
            $this->items[$index]['uom_conversion_factor'] = (string) $catalogItem->uom_conversion_factor;
            $this->items[$index]['unit_price'] = (string) $catalogItem->standard_price;
        } else {
            if ($type === 'product') {
                $p = Product::find($itemId);
                if ($p) {
                    $this->items[$index]['unit_price'] = (string) ($p->cost_price ?? 0);
                }
            } else {
                $ing = Ingredient::find($itemId);
                if ($ing) {
                    $this->items[$index]['unit_price'] = (string) ($ing->cost_per_unit ?? 0);
                    $this->items[$index]['purchase_uom'] = strtoupper($ing->unit ?? 'KG');
                }
            }
        }
    }

    public function savePurchaseOrder(PurchaseOrderService $service): void
    {
        $this->authorize('create', PurchaseOrder::class);

        $this->validate([
            'storeId' => 'required|integer',
            'supplierId' => 'required|integer',
            'orderDate' => 'required|date',
            'expectedDeliveryDate' => 'nullable|date|after_or_equal:orderDate',
            'items' => 'required|array|min:1',
            'items.*.purchasable_id' => 'required|integer',
            'items.*.ordered_qty' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0',
        ], [
            'storeId.required' => 'Destination branch/store is required.',
            'supplierId.required' => 'Supplier is required.',
            'items.min' => 'At least 1 item is required.',
            'items.*.purchasable_id.required' => 'Product/ingredient item is required.',
            'items.*.ordered_qty.min' => 'Ordered quantity must be greater than 0.',
        ]);

        $actor = auth()->user();

        $headerData = [
            'store_id' => $this->storeId,
            'supplier_id' => $this->supplierId,
            'order_date' => $this->orderDate,
            'expected_delivery_date' => $this->expectedDeliveryDate ?: null,
            'notes' => $this->notes,
        ];

        try {
            $po = $service->createDirectPurchaseOrder($headerData, $this->items, $actor);

            $this->showCreateModal = false;
            session()->flash('message', "Purchase Order #{$po->po_number} created as Draft.");
            $this->viewPo((int) $po->id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to create PO: '.$e->getMessage());
        }
    }

    public function viewPo(int $id): void
    {
        $po = PurchaseOrder::with(['supplier', 'store', 'lines.purchasable'])->findOrFail($id);
        $this->authorize('view', $po);

        $this->selectedPoId = $po->id;
        $this->selectedPo = $po;
        $this->showDetailModal = true;
    }

    public function submitPo(int $id, PurchaseOrderService $service): void
    {
        $po = PurchaseOrder::findOrFail($id);
        $this->authorize('update', $po);

        try {
            $updated = $service->submitPurchaseOrder($po, auth()->user());
            session()->flash('message', "PO #{$updated->po_number} successfully submitted.");
            $this->viewPo($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to submit PO: '.$e->getMessage());
        }
    }

    public function releasePo(int $id, PurchaseOrderService $service): void
    {
        $po = PurchaseOrder::findOrFail($id);
        $this->authorize('update', $po);

        try {
            $updated = $service->releasePurchaseOrder($po, auth()->user());
            session()->flash('message', "PO #{$updated->po_number} successfully issued.");
            $this->viewPo($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to issue PO: '.$e->getMessage());
        }
    }

    public function confirmCancelPo(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        $this->authorize('update', $po);

        $this->selectedPoId = $po->id;
        $this->selectedPo = $po;
        $this->cancelReason = '';
        $this->showCancelModal = true;
    }

    public function cancelPo(PurchaseOrderService $service): void
    {
        if (! $this->selectedPoId) {
            return;
        }

        $po = PurchaseOrder::findOrFail($this->selectedPoId);
        $this->authorize('update', $po);

        try {
            $service->cancelPurchaseOrder($po, auth()->user(), $this->cancelReason ?: 'Cancelled by user.');
            $this->showCancelModal = false;
            session()->flash('message', "PO #{$po->po_number} successfully cancelled.");
            $this->viewPo($po->id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to cancel PO: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $allowedSortFields = ['po_number', 'order_date', 'total_amount', 'status', 'id'];
        $sortCol = in_array($this->sortField, $allowedSortFields, true) ? $this->sortField : 'id';
        $sortDir = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $query = PurchaseOrder::query()
            ->with(['supplier', 'store'])
            ->where('tenant_id', $tenantId)
            ->orderBy($sortCol, $sortDir);

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('po_number', 'like', "%{$this->search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"));
            });
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->supplierFilter) {
            $query->where('supplier_id', $this->supplierFilter);
        }

        $purchaseOrders = $query->paginate(15);

        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $stores = Store::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $products = Product::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('product_name')
            ->get();

        $ingredients = Ingredient::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return view('livewire.purchasing.purchase-order-manager', [
            'purchaseOrders' => $purchaseOrders,
            'suppliers' => $suppliers,
            'stores' => $stores,
            'products' => $products,
            'ingredients' => $ingredients,
        ]);
    }
}
