<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Enums\StockTransferStatusEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockTransferManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = 'ALL';

    #[Url(history: true)]
    public string $directionFilter = 'ALL'; // ALL, OUTBOUND, INBOUND

    // Modal states
    public bool $showFormModal = false;

    public bool $showReceiveModal = false;

    public bool $showDetailModal = false;

    // Form fields for creating transfer
    public ?int $to_store_id = null;

    public ?string $notes = null;

    /**
     * @var array<int, array{
     *     item_type: 'product'|'ingredient',
     *     product_id: ?int,
     *     ingredient_id: ?int,
     *     quantity_requested: float|string,
     *     quantity_shipped?: float|string|null,
     *     notes?: ?string
     * }>
     */
    public array $items = [];

    // Receive modal fields
    #[Locked]
    public ?int $activeTransferId = null;

    /**
     * @var array<int, float|string>
     */
    public array $receivedQuantities = [];

    /**
     * @var array<int, string>
     */
    public array $discrepancyResolutions = [];

    /**
     * @var array<int, string>
     */
    public array $discrepancyReasons = [];

    public ?string $receiveNotes = null;

    // Detail view
    #[Locked]
    public ?int $selectedTransferId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        if (! $user || ! $user->role || ! in_array($user->role->name, ['Owner', 'Manager', 'Inventory'], true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman Transfer Stok.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDirectionFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->addItemRow();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetForm();
    }

    public function addItemRow(): void
    {
        $this->items[] = [
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null,
            'quantity_requested' => '1.0000',
            'quantity_shipped' => '1.0000',
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

    public function saveTransfer(StockTransferService $service): void
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $fromStoreId = $context->getStoreId();

        if (! $tenantId || ! $fromStoreId) {
            $this->addError('general', 'Konteks tenant atau cabang aktif tidak valid.');

            return;
        }

        $user = auth()->user();
        if (! $user || ! Gate::allows('create', [StockTransfer::class, $fromStoreId, (int) $this->to_store_id])) {
            abort(403, 'Anda tidak memiliki wewenang untuk membuat permintaan transfer dari cabang ini.');
        }

        $this->validate([
            'to_store_id' => 'required|integer|different:fromStoreId',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:product,ingredient',
            'items.*.product_id' => 'required_if:items.*.item_type,product|nullable|integer',
            'items.*.ingredient_id' => 'required_if:items.*.item_type,ingredient|nullable|integer',
            'items.*.quantity_requested' => 'required|numeric|min:0.0001',
            'items.*.quantity_shipped' => 'nullable|numeric|min:0',
        ]);

        try {
            $transfer = $service->createDraft(
                tenantId: $tenantId,
                fromStoreId: $fromStoreId,
                toStoreId: (int) $this->to_store_id,
                data: [
                    'notes' => $this->notes,
                    'items' => $this->items,
                ],
                userId: (int) $user->id
            );

            session()->flash('message', "Draft transfer #{$transfer->transfer_number} berhasil dibuat.");
            $this->closeFormModal();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (InvalidArgumentException|DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function shipTransfer(int $transferId, StockTransferService $service): void
    {
        $transfer = $this->findScopedTransfer($transferId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('ship', $transfer)) {
            abort(403, 'Anda tidak memiliki wewenang untuk mengirimkan transfer ini.');
        }

        try {
            $service->shipTransfer($transfer, (int) $user->id);
            session()->flash('message', "Transfer #{$transfer->transfer_number} berhasil dikirim (Status: IN_TRANSIT).");
        } catch (ValidationException $e) {
            session()->flash('error', $e->validator->errors()->first());
        } catch (DomainException|InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openReceiveModal(int $transferId): void
    {
        $transfer = $this->findScopedTransfer($transferId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('receive', $transfer)) {
            abort(403, 'Anda tidak memiliki wewenang untuk menerima transfer di cabang ini.');
        }

        $this->activeTransferId = $transfer->id;
        $this->receivedQuantities = [];
        $this->discrepancyResolutions = [];
        $this->discrepancyReasons = [];
        $this->receiveNotes = null;

        foreach ($transfer->items as $item) {
            // Default received quantity to shipped quantity (full receipt)
            $this->receivedQuantities[$item->id] = (string) $item->quantity_shipped;
            $this->discrepancyResolutions[$item->id] = 'NONE';
            $this->discrepancyReasons[$item->id] = '';
        }

        $this->showReceiveModal = true;
    }

    public function closeReceiveModal(): void
    {
        $this->showReceiveModal = false;
        $this->activeTransferId = null;
        $this->receivedQuantities = [];
        $this->discrepancyResolutions = [];
        $this->discrepancyReasons = [];
        $this->receiveNotes = null;
    }

    public function submitReceive(StockTransferService $service): void
    {
        if (! $this->activeTransferId) {
            return;
        }

        $transfer = $this->findScopedTransfer($this->activeTransferId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('receive', $transfer)) {
            abort(403, 'Anda tidak memiliki wewenang untuk menerima transfer ini.');
        }

        $receiptItems = [];
        foreach ($transfer->items as $item) {
            $receivedQty = (string) ($this->receivedQuantities[$item->id] ?? '0');
            $discrepancy = bcsub((string) $item->quantity_shipped, $receivedQty, 4);

            $resolution = $this->discrepancyResolutions[$item->id] ?? 'NONE';
            $reasonCode = $this->discrepancyReasons[$item->id] ?? null;

            if (bccomp($discrepancy, '0', 4) > 0 && ($resolution === 'NONE' || empty($resolution))) {
                $this->addError("receivedQuantities.{$item->id}", "Item memiliki selisih kekurangan {$discrepancy}. Pilih resolusi selisih.");

                return;
            }

            $receiptItems[$item->id] = [
                'item_id' => $item->id,
                'quantity_received' => $receivedQty,
                'discrepancy_resolution' => $resolution,
                'discrepancy_reason_code' => $reasonCode,
            ];
        }

        try {
            $service->receiveTransfer($transfer, [
                'notes' => $this->receiveNotes,
                'items' => $receiptItems,
            ], (int) $user->id);

            session()->flash('message', "Transfer #{$transfer->transfer_number} berhasil diterima (Status: RECEIVED).");
            $this->closeReceiveModal();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (DomainException|InvalidArgumentException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function cancelTransfer(int $transferId, StockTransferService $service): void
    {
        $transfer = $this->findScopedTransfer($transferId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('cancel', $transfer)) {
            abort(403, 'Anda tidak memiliki wewenang untuk membatalkan transfer ini.');
        }

        try {
            $service->cancelTransfer($transfer, (int) $user->id, 'Dibatalkan oleh pengguna.');
            session()->flash('message', "Transfer #{$transfer->transfer_number} berhasil dibatalkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function viewDetail(int $transferId): void
    {
        $transfer = $this->findScopedTransfer($transferId);
        $this->selectedTransferId = $transfer->id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedTransferId = null;
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        $query = StockTransfer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['fromStore', 'toStore', 'creator', 'shipper', 'receiver'])
            ->withCount('items')
            ->orderByDesc('id');

        // Filter directional by active store
        if ($this->directionFilter === 'OUTBOUND') {
            $query->where('from_store_id', $storeId);
        } elseif ($this->directionFilter === 'INBOUND') {
            $query->where('to_store_id', $storeId);
        } else {
            $query->involvingStore($storeId);
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $search = '%'.$this->search.'%';
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'LIKE', $search)
                    ->orWhere('notes', 'LIKE', $search);
            });
        }

        $transfers = $query->paginate(10);

        // Preload destination stores
        $destinationStores = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $storeId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Preload catalog
        $availableProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('product_name')
            ->get(['id', 'product_name as name', 'product_code as code']);

        $availableIngredients = Ingredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'ingredient_code as code']);

        $selectedTransfer = $this->selectedTransferId
            ? StockTransfer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['items.product', 'items.ingredient', 'fromStore', 'toStore', 'creator', 'shipper', 'receiver', 'canceller'])
                ->find($this->selectedTransferId)
            : null;

        $activeTransfer = $this->activeTransferId
            ? StockTransfer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['items.product', 'items.ingredient', 'fromStore', 'toStore'])
                ->find($this->activeTransferId)
            : null;

        return view('livewire.inventory.stock-transfer-manager', [
            'transfers' => $transfers,
            'destinationStores' => $destinationStores,
            'availableProducts' => $availableProducts,
            'availableIngredients' => $availableIngredients,
            'selectedTransfer' => $selectedTransfer,
            'activeTransfer' => $activeTransfer,
            'statusOptions' => StockTransferStatusEnum::cases(),
            'currentStoreId' => $storeId,
        ]);
    }

    private function findScopedTransfer(int $id): StockTransfer
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        return StockTransfer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->involvingStore($storeId)
            ->with(['items.product', 'items.ingredient', 'fromStore', 'toStore'])
            ->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->to_store_id = null;
        $this->notes = null;
        $this->items = [];
        $this->resetErrorBag();
    }
}
