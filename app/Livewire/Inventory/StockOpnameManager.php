<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Core\Context\TenantContext;
use App\Enums\StockOpnameStatusEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Inventory\Concerns\ManagesStockOpnameCountsAndDrift;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Organization\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockOpnameManager extends Component
{
    use ManagesStockOpnameCountsAndDrift;
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = 'ALL';

    // Modal states
    public bool $showFormModal = false;

    public bool $showDetailModal = false;

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

    // Detail view
    #[Locked]
    public ?int $selectedOpnameId = null;

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

    public function createSession(StockOpnameService $service): void
    {
        $this->authorize('create', StockOpname::class);

        $rules = [
            'all_items' => 'required|boolean',
            'notes' => 'nullable|string|max:500',
        ];

        if (! $this->all_items) {
            $rules['items'] = 'required|array|min:1';
            $rules['items.*.item_type'] = 'required|in:product,ingredient';
            $rules['items.*.product_id'] = 'required_if:items.*.item_type,product|nullable|integer';
            $rules['items.*.ingredient_id'] = 'required_if:items.*.item_type,ingredient|nullable|integer';
        }

        $this->validate($rules);

        $user = auth()->user();
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $itemsPayload = [];
        if (! $this->all_items) {
            foreach ($this->items as $row) {
                $itemsPayload[] = [
                    'item_type' => $row['item_type'],
                    'product_id' => $row['item_type'] === 'product' ? $row['product_id'] : null,
                    'ingredient_id' => $row['item_type'] === 'ingredient' ? $row['ingredient_id'] : null,
                    'notes' => $row['notes'] ?? null,
                ];
            }
        }

        try {
            $opname = $service->createDraft((int) $tenantId, (int) $storeId, [
                'all_items' => $this->all_items,
                'notes' => $this->notes,
                'items' => $itemsPayload,
            ], (int) $user->id);

            session()->flash('message', "Sesi opname #{$opname->opname_number} berhasil dibuat.");
            $this->closeFormModal();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function startCounting(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->authorize('startCounting', $opname);
        $user = auth()->user();

        try {
            $service->startCounting($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} sekarang berstatus COUNTING. Snapshot telah dipin.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function submitForApproval(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->authorize('submit', $opname);
        $user = auth()->user();

        try {
            $service->submitForApproval($opname, (int) $user->id);
            session()->flash('message', "Sesi opname #{$opname->opname_number} berhasil diajukan untuk persetujuan manajer.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelOpname(int $opnameId, StockOpnameService $service): void
    {
        $opname = $this->findScopedOpname($opnameId);
        $this->authorize('cancel', $opname);
        $user = auth()->user();

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
            'canViewExpectedStock' => $this->canViewExpectedStock(),
        ]);
    }

    public function canViewExpectedStock(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $roleName = $user->role ? $user->role->name : '';

        return in_array($roleName, ['Owner', 'Manager'], true)
            || $user->hasRoleInStore(['Owner', 'Manager']);
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
