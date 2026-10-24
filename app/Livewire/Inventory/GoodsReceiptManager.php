<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\Product;
use App\Modules\Inventory\Services\GoodsReceiptService;
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
class GoodsReceiptManager extends Component
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
    public ?int $editingReceiptId = null;

    public ?string $receipt_number = null;

    public ?string $supplier_reference = null;

    public ?string $purchase_order_reference = null;

    public ?string $received_at = null;

    public ?string $notes = null;

    /**
     * @var array<int, array{
     *     item_type: 'product'|'ingredient',
     *     product_id: ?int,
     *     ingredient_id: ?int,
     *     quantity_received: float|string,
     *     unit_cost: float|string,
     *     batch_number: ?string,
     *     expiry_date: ?string,
     *     notes: ?string
     * }>
     */
    public array $items = [];

    // Reversal form
    #[Locked]
    public ?int $reversalReceiptId = null;

    public string $reversal_reason = '';

    // Selected receipt for detail view
    #[Locked]
    public ?int $selectedReceiptId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        if (! $user || ! $user->role || ! in_array($user->role->name, ['Owner', 'Manager', 'Inventory'], true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman Penerimaan Barang.');
        }
    }

    public function mount(): void
    {
        $this->received_at = now()->format('Y-m-d\TH:i');
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
        $this->resetForm();
        $this->received_at = now()->format('Y-m-d\TH:i');
        $this->addItemLine();
        $this->showFormModal = true;
    }

    public function addItemLine(): void
    {
        $this->items[] = [
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null,
            'quantity_received' => '1.0000',
            'unit_cost' => '0.0000',
            'batch_number' => null,
            'expiry_date' => null,
            'notes' => null,
        ];
    }

    public function removeItemLine(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if (empty($this->items)) {
            $this->addItemLine();
        }
    }

    public function saveDraft(GoodsReceiptService $service): void
    {
        $this->validate([
            'supplier_reference' => 'nullable|string|max:128',
            'purchase_order_reference' => 'nullable|string|max:128',
            'received_at' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:product,ingredient',
            'items.*.product_id' => 'required_if:items.*.item_type,product|nullable|integer',
            'items.*.ingredient_id' => 'required_if:items.*.item_type,ingredient|nullable|integer',
            'items.*.quantity_received' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:64',
            'items.*.expiry_date' => 'nullable|date',
        ]);

        $context = app(TenantContext::class);
        $tenantId = $context->requireTenantId();
        $storeId = $context->getStoreId();

        if (! $storeId) {
            session()->flash('error', 'Cabang aktif belum dipilih.');

            return;
        }

        try {
            $user = auth()->user();
            $userId = (int) $user->id;

            if ($this->isEditing && $this->editingReceiptId) {
                $receipt = GoodsReceipt::findOrFail($this->editingReceiptId);
                Gate::authorize('update', $receipt);

                $service->updateDraft($receipt, [
                    'supplier_reference' => $this->supplier_reference,
                    'purchase_order_reference' => $this->purchase_order_reference,
                    'received_at' => $this->received_at,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ], $userId);

                session()->flash('message', "Draft {$receipt->receipt_number} berhasil diperbarui.");
            } else {
                Gate::authorize('create', [GoodsReceipt::class, $storeId]);

                $receipt = $service->createDraft($tenantId, $storeId, [
                    'receipt_number' => $this->receipt_number ?: null,
                    'supplier_reference' => $this->supplier_reference,
                    'purchase_order_reference' => $this->purchase_order_reference,
                    'received_at' => $this->received_at,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ], $userId);

                session()->flash('message', "Draft penerimaan barang {$receipt->receipt_number} berhasil dibuat.");
            }

            $this->showFormModal = false;
            $this->resetForm();
        } catch (DomainException|InvalidArgumentException|ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function post(int $receiptId, GoodsReceiptService $service): void
    {
        $receipt = GoodsReceipt::findOrFail($receiptId);
        Gate::authorize('post', $receipt);

        try {
            $service->postReceipt($receipt, (int) auth()->id());
            session()->flash('message', "Penerimaan barang {$receipt->receipt_number} berhasil diposting ke stok gudang.");
        } catch (DomainException|InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancel(int $receiptId, GoodsReceiptService $service): void
    {
        $receipt = GoodsReceipt::findOrFail($receiptId);
        Gate::authorize('cancel', $receipt);

        try {
            $service->cancelDraft($receipt, (int) auth()->id());
            session()->flash('message', "Draft penerimaan barang {$receipt->receipt_number} berhasil dibatalkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openReverseModal(int $receiptId): void
    {
        $receipt = GoodsReceipt::findOrFail($receiptId);
        Gate::authorize('reverse', $receipt);

        $this->reversalReceiptId = $receiptId;
        $this->reversal_reason = '';
        $this->showReverseModal = true;
    }

    public function submitReversal(GoodsReceiptService $service): void
    {
        $this->validate([
            'reversal_reason' => 'required|string|min:5|max:255',
        ]);

        if (! $this->reversalReceiptId) {
            return;
        }

        $receipt = GoodsReceipt::findOrFail($this->reversalReceiptId);
        Gate::authorize('reverse', $receipt);

        try {
            $service->reverseReceipt($receipt, (int) auth()->id(), $this->reversal_reason);
            session()->flash('message', "Penerimaan barang {$receipt->receipt_number} berhasil direverse.");
            $this->showReverseModal = false;
            $this->reversalReceiptId = null;
            $this->reversal_reason = '';
        } catch (DomainException|InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function viewDetails(int $receiptId): void
    {
        $this->selectedReceiptId = $receiptId;
        $this->showDetailModal = true;
    }

    public function resetForm(): void
    {
        $this->editingReceiptId = null;
        $this->receipt_number = null;
        $this->supplier_reference = null;
        $this->purchase_order_reference = null;
        $this->received_at = now()->format('Y-m-d\TH:i');
        $this->notes = null;
        $this->items = [];
        $this->isEditing = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $tenantId = $context->requireTenantId();
        $storeId = $context->getStoreId();

        $query = GoodsReceipt::query()
            ->with(['items.product', 'items.ingredient', 'creator', 'poster', 'reverser'])
            ->where('tenant_id', $tenantId);

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('supplier_reference', 'like', "%{$search}%")
                    ->orWhere('purchase_order_reference', 'like', "%{$search}%");
            });
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        $receipts = $query->latest('id')->paginate(15);

        $products = Product::where('tenant_id', $tenantId)->orderBy('product_name')->get(['id', 'product_name', 'product_code']);
        $ingredients = Ingredient::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'unit']);

        $selectedReceipt = null;
        if ($this->selectedReceiptId) {
            $selectedReceipt = GoodsReceipt::with(['items.product', 'items.ingredient', 'creator', 'poster', 'reverser', 'store'])
                ->find($this->selectedReceiptId);
        }

        return view('livewire.inventory.goods-receipt-manager', [
            'receipts' => $receipts,
            'products' => $products,
            'ingredients' => $ingredients,
            'selectedReceipt' => $selectedReceipt,
        ]);
    }
}
