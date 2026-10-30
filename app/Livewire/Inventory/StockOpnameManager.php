<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Enums\StockOpnameStatusEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockOpname;
use App\Modules\Inventory\Exceptions\StockOpnameDriftException;
use App\Modules\Inventory\Services\StockOpnameService;
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
class StockOpnameManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = 'ALL';

    // Modal states
    public bool $showFormModal = false;

    public bool $showCountModal = false;

    public bool $showDetailModal = false;

    public bool $showDriftModal = false;

    // Create session form
    public bool $all_items = true;

    public ?string $notes = null;

    /**
     * @var array<int, array{
     *     item_type: 'product'|'ingredient',
     *     product_id: ?int,
     *     ingredient_id: ?int,
     *     notes: ?string
     * }>
     */
    public array $items = [];

    // Counting state
    #[Locked]
    public ?int $activeOpnameId = null;

    /**
     * @var array<int, float|string>
     */
    public array $countedQuantities = [];

    /**
     * @var array<int, string>
     */
    public array $countNotes = [];

    // Detail view
    #[Locked]
    public ?int $selectedOpnameId = null;

    // Drift modal data
    /**
     * @var array<int, array{
     *     item_id: int,
     *     item_type: string,
     *     product_id?: ?int,
     *     ingredient_id?: ?int,
     *     name: string,
     *     snapshot_on_hand: string,
     *     current_on_hand: string,
     *     snapshot_last_movement_id: ?int,
     *     current_last_movement_id: ?int,
     *     drift_delta: string
     * }>
     */
    public array $driftedItems = [];

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        $isAllowed = $user
            && $user->role
            && (
                (bool) ($user->role->is_admin ?? false)
                || in_array($user->role->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
            );

        if (! $isAllowed) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman Stock Opname.');
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

    public function openCreateModal(): void
    {
        $this->resetForm();
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
            'notes' => null,
        ];
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function saveOpname(StockOpnameService $service): void
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        if (! $tenantId || ! $storeId) {
            $this->addError('general', 'Konteks tenant atau cabang aktif tidak valid.');

            return;
        }

        $user = auth()->user();
        if (! $user || ! Gate::allows('create', [StockOpname::class, $storeId])) {
            abort(403, 'Anda tidak memiliki wewenang untuk membuat sesi opname di cabang ini.');
        }

        $this->validate([
            'notes' => 'nullable|string|max:500',
            'all_items' => 'boolean',
        ]);

        if (! $this->all_items && empty($this->items)) {
            $this->addError('items', 'Wajib menambahkan minimal 1 item jika tidak memilih opsi Hitung Semua Item.');

            return;
        }

        try {
            $opname = $service->createDraft(
                tenantId: $tenantId,
                storeId: $storeId,
                data: [
                    'all_items' => $this->all_items,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ],
                userId: (int) $user->id
            );

            session()->flash('message', "Sesi Stock Opname #{$opname->opname_number} berhasil dibuat sebagai DRAFT.");
            $this->closeFormModal();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (InvalidArgumentException|DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function startCounting(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('startCounting', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk memulai proses hitung sesi opname ini.');
        }

        try {
            $service->startCounting($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} sekarang berstatus COUNTING. Snapshot telah dipin.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openCountModal(int $opnameId): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('recordCount', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk mengisi hitungan opname.');
        }

        $this->activeOpnameId = $opname->id;
        $this->countedQuantities = [];
        $this->countNotes = [];

        foreach ($opname->items as $item) {
            $this->countedQuantities[$item->id] = $item->counted_quantity !== null ? (string) $item->counted_quantity : '';
            $this->countNotes[$item->id] = $item->notes ?? '';
        }

        $this->showCountModal = true;
    }

    public function closeCountModal(): void
    {
        $this->showCountModal = false;
        $this->activeOpnameId = null;
        $this->countedQuantities = [];
        $this->countNotes = [];
    }

    public function saveCounts(StockOpnameService $service): void
    {
        if (! $this->activeOpnameId) {
            return;
        }

        $opname = $this->findScopedOpname($this->activeOpnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('recordCount', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk menyimpan hasil hitung.');
        }

        $counts = [];
        foreach ($this->countedQuantities as $itemId => $qty) {
            if ($qty !== '') {
                $counts[(int) $itemId] = [
                    'item_id' => (int) $itemId,
                    'counted_quantity' => (string) $qty,
                    'notes' => $this->countNotes[$itemId] ?? null,
                ];
            }
        }

        try {
            $service->recordCounts($opname, $counts, (int) $user->id);
            session()->flash('message', 'Hasil hitung fisik berhasil disimpan.');
            $this->closeCountModal();
        } catch (InvalidArgumentException|DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function submitForApproval(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('submit', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk mengajukan sesi opname.');
        }

        try {
            $service->submitForApproval($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} berhasil diajukan untuk persetujuan manajer.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function approveOpname(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('approve', $opname)) {
            abort(403, 'Hanya Manajer atau Pemilik cabang yang dapat menyetujui rekonsiliasi opname.');
        }

        try {
            $service->approveOpname($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} berhasil disetujui & rekonsiliasi stok telah dicatat ke ledger.");
            $this->showDriftModal = false;
        } catch (StockOpnameDriftException $e) {
            $this->activeOpnameId = $opname->id;
            $this->driftedItems = $e->getDriftedItems();
            $this->showDriftModal = true;
            session()->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function resolveDriftItem(int $itemId, string $mode, StockOpnameService $service): void
    {
        if (! $this->activeOpnameId) {
            return;
        }

        $opname = $this->findScopedOpname($this->activeOpnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('approve', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk meresolusi drift.');
        }

        try {
            $service->resolveDrift($opname, $itemId, $mode, (int) $user->id);

            // Filter out resolved item from local list
            $this->driftedItems = array_values(array_filter(
                $this->driftedItems,
                fn ($d) => (int) $d['item_id'] !== $itemId
            ));

            if (empty($this->driftedItems)) {
                $this->showDriftModal = false;
                session()->flash('message', 'Seluruh drift telah diresolusi.');
            }
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelOpname(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $user = auth()->user();

        if (! $user || ! Gate::allows('cancel', $opname)) {
            abort(403, 'Anda tidak memiliki wewenang untuk membatalkan sesi opname.');
        }

        try {
            $service->cancelSession($opname, (int) $user->id, 'Dibatalkan oleh pengguna.');
            session()->flash('message', "Sesi opname #{$opname->opname_number} telah dibatalkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function viewDetail(int $opnameId): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->selectedOpnameId = $opname->id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedOpnameId = null;
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        $query = StockOpname::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->with(['creator', 'submitter', 'approver'])
            ->withCount('items')
            ->orderByDesc('id');

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $search = '%'.$this->search.'%';
            $query->where(function ($q) use ($search) {
                $q->where('opname_number', 'LIKE', $search)
                    ->orWhere('notes', 'LIKE', $search);
            });
        }

        $opnames = $query->paginate(10);

        // Preload catalog for form
        $availableProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('product_name')
            ->get(['id', 'product_name as name', 'product_code as code']);

        $availableIngredients = Ingredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'ingredient_code as code']);

        $selectedOpname = $this->selectedOpnameId
            ? StockOpname::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->with(['items.product', 'items.ingredient', 'creator', 'submitter', 'approver'])
                ->find($this->selectedOpnameId)
            : null;

        $activeOpname = $this->activeOpnameId
            ? StockOpname::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->with(['items.product', 'items.ingredient'])
                ->find($this->activeOpnameId)
            : null;

        return view('livewire.inventory.stock-opname-manager', [
            'opnames' => $opnames,
            'availableProducts' => $availableProducts,
            'availableIngredients' => $availableIngredients,
            'selectedOpname' => $selectedOpname,
            'activeOpname' => $activeOpname,
            'statusOptions' => StockOpnameStatusEnum::cases(),
        ]);
    }

    private function findScopedOpname(int $id): StockOpname
    {
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        $storeId = $context->getStoreId();

        return StockOpname::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->with(['items.product', 'items.ingredient'])
            ->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->all_items = true;
        $this->notes = null;
        $this->items = [];
        $this->resetErrorBag();
    }
}
