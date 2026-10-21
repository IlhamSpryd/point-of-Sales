<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\SupplierReturn;
use App\Modules\Inventory\Services\SupplierReturnService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SupplierReturnManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'ALL';

    // Modal state
    public bool $showFormModal = false;

    public bool $showDetailModal = false;

    public bool $showReverseModal = false;

    public bool $isEditing = false;

    // Form fields
    #[Locked]
    public ?int $editingReturnId = null;

    public ?string $return_number = null;

    public ?int $goods_receipt_id = null;

    public ?string $supplier_name = null;

    public ?string $supplier_reference = null;

    public ?string $returned_at = null;

    public ?string $reason = null;

    public ?string $notes = null;

    /**
     * @var array<int, array{
     *     item_type: 'product'|'ingredient',
     *     product_id: ?int,
     *     ingredient_id: ?int,
     *     quantity: float|string,
     *     unit_cost: float|string,
     *     reason?: ?string,
     *     notes?: ?string
     * }>
     */
    public array $items = [];

    // Reversal form
    #[Locked]
    public ?int $reversalReturnId = null;

    public string $reversal_reason = '';

    // Selected return for detail view
    #[Locked]
    public ?int $selectedReturnId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();
    }

    public function mount(): void
    {
        $this->returned_at = now()->format('Y-m-d\TH:i');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetErrorBag();
        $this->isEditing = false;
        $this->editingReturnId = null;
        $this->return_number = null;
        $this->goods_receipt_id = null;
        $this->supplier_name = null;
        $this->supplier_reference = null;
        $this->returned_at = now()->format('Y-m-d\TH:i');
        $this->reason = null;
        $this->notes = null;
        $this->items = [];
        $this->addItemRow();

        $this->showFormModal = true;
    }

    public function addItemRow(): void
    {
        $this->items[] = [
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null,
            'quantity' => '1.0000',
            'unit_cost' => '0.0000',
            'reason' => null,
            'notes' => null,
        ];
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (empty($this->items)) {
            $this->addItemRow();
        }
    }

    public function saveDraft(SupplierReturnService $service): void
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        if (! $tenantId || ! $storeId) {
            $this->addError('general', 'Konteks tenant atau cabang aktif tidak valid.');

            return;
        }

        $user = auth()->user();
        if (! $user || ! Gate::allows('create', [SupplierReturn::class, $storeId])) {
            abort(403, 'Anda tidak memiliki wewenang untuk membuat retur supplier di cabang ini.');
        }

        $this->validate([
            'goods_receipt_id' => 'nullable|integer',
            'supplier_name' => 'nullable|string|max:128',
            'supplier_reference' => 'nullable|string|max:128',
            'returned_at' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:product,ingredient',
            'items.*.product_id' => 'required_if:items.*.item_type,product|nullable|integer',
            'items.*.ingredient_id' => 'required_if:items.*.item_type,ingredient|nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        try {
            if ($this->isEditing && $this->editingReturnId) {
                $return = $this->findScopedReturn($this->editingReturnId);
                $service->updateDraft($return, [
                    'supplier_name' => $this->supplier_name,
                    'supplier_reference' => $this->supplier_reference,
                    'reason' => $this->reason,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ], (int) $user->id);

                session()->flash('message', "Draft retur #{$return->return_number} berhasil diperbarui.");
            } else {
                $return = $service->createDraft(
                    tenantId: $tenantId,
                    storeId: $storeId,
                    data: [
                        'goods_receipt_id' => $this->goods_receipt_id,
                        'supplier_name' => $this->supplier_name,
                        'supplier_reference' => $this->supplier_reference,
                        'returned_at' => $this->returned_at,
                        'reason' => $this->reason,
                        'notes' => $this->notes,
                        'items' => $this->items,
                    ],
                    userId: (int) $user->id
                );

                session()->flash('message', "Draft retur #{$return->return_number} berhasil dibuat.");
            }

            $this->closeFormModal();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (InvalidArgumentException|DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function postReturn(int $returnId, SupplierReturnService $service): void
    {
        $return = $this->findScopedReturn($returnId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('post', $return)) {
            abort(403, 'Anda tidak memiliki wewenang untuk memposting retur ini.');
        }

        try {
            $service->postReturn($return, (int) $user->id);
            session()->flash('message', "Retur #{$return->return_number} berhasil diposting. Stok telah dipotong.");
        } catch (ValidationException $e) {
            session()->flash('error', $e->validator->errors()->first());
        } catch (DomainException|InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelReturn(int $returnId, SupplierReturnService $service): void
    {
        $return = $this->findScopedReturn($returnId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('cancel', $return)) {
            abort(403, 'Anda tidak memiliki wewenang untuk membatalkan retur ini.');
        }

        try {
            $service->cancelDraft($return, (int) $user->id, 'Dibatalkan dari antarmuka web.');
            session()->flash('message', "Retur #{$return->return_number} berhasil dibatalkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openReverseModal(int $returnId): void
    {
        $return = $this->findScopedReturn($returnId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('reverse', $return)) {
            abort(403, 'Anda tidak memiliki wewenang untuk melakukan pembalikan (reverse) retur.');
        }

        $this->reversalReturnId = $return->id;
        $this->reversal_reason = '';
        $this->showReverseModal = true;
    }

    public function closeReverseModal(): void
    {
        $this->showReverseModal = false;
        $this->reversalReturnId = null;
        $this->reversal_reason = '';
    }

    public function submitReverse(SupplierReturnService $service): void
    {
        if (! $this->reversalReturnId) {
            return;
        }

        $return = $this->findScopedReturn($this->reversalReturnId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('reverse', $return)) {
            abort(403, 'Anda tidak memiliki wewenang untuk membalikkan retur ini.');
        }

        $this->validate([
            'reversal_reason' => 'required|string|min:5|max:255',
        ]);

        try {
            $service->reverseReturn($return, (int) $user->id, $this->reversal_reason);
            session()->flash('message', "Pembalikan retur #{$return->return_number} berhasil diproses.");
            $this->closeReverseModal();
        } catch (DomainException|InvalidArgumentException $e) {
            $this->addError('reversal_reason', $e->getMessage());
        }
    }

    public function viewDetail(int $returnId): void
    {
        $return = $this->findScopedReturn($returnId);
        $this->selectedReturnId = $return->id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedReturnId = null;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->isEditing = false;
        $this->editingReturnId = null;
        $this->items = [];
    }

    protected function findScopedReturn(int $id): SupplierReturn
    {
        $context = app(TenantContext::class);

        /** @var SupplierReturn */
        return SupplierReturn::withoutGlobalScopes()
            ->where('tenant_id', $context->getTenantId())
            ->where('store_id', $context->getStoreId())
            ->where('id', $id)
            ->firstOrFail();
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        $query = SupplierReturn::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->with(['creator', 'poster', 'goodsReceipt'])
            ->latest('id');

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', $search)
                    ->orWhere('supplier_name', 'like', $search)
                    ->orWhere('supplier_reference', 'like', $search);
            });
        }

        $returns = $query->paginate(10);

        // Fetch selectable items for active store
        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'product_code']);

        $ingredients = Ingredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'ingredient_code', 'unit']);

        $recentReceipts = GoodsReceipt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'POSTED')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'receipt_number', 'supplier_reference']);

        $selectedReturn = $this->selectedReturnId
            ? SupplierReturn::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $this->selectedReturnId)
                ->with(['items.product', 'items.ingredient', 'creator', 'poster', 'reverser', 'goodsReceipt'])
                ->first()
            : null;

        return view('livewire.inventory.supplier-return-manager', [
            'returns' => $returns,
            'products' => $products,
            'ingredients' => $ingredients,
            'recentReceipts' => $recentReceipts,
            'selectedReturn' => $selectedReturn,
        ]);
    }
}
