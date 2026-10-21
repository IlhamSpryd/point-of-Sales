<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Enums\StockAdjustmentReasonEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
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
class StockAdjustmentManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'ALL';

    public string $reasonFilter = 'ALL';

    // Modal state
    public bool $showFormModal = false;

    public bool $showDetailModal = false;

    public bool $isEditing = false;

    // Form fields
    #[Locked]
    public ?int $editingAdjustmentId = null;

    public ?string $adjustment_number = null;

    public string $reason_code = 'DAMAGED';

    public ?string $adjusted_at = null;

    public ?string $notes = null;

    /**
     * @var array<int, array{
     *     item_type: 'product'|'ingredient',
     *     product_id: ?int,
     *     ingredient_id: ?int,
     *     direction: 'IN'|'OUT',
     *     quantity: float|string,
     *     unit_cost_snapshot: float|string,
     *     reason_note: ?string
     * }>
     */
    public array $items = [];

    // Selected adjustment for detail view
    #[Locked]
    public ?int $selectedAdjustmentId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        if (! $user || ! $user->role || ! in_array($user->role->name, ['Owner', 'Manager', 'Inventory'], true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman Penyesuaian Stok.');
        }
    }

    public function mount(): void
    {
        $this->adjusted_at = now()->format('Y-m-d\TH:i');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingReasonFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->adjusted_at = now()->format('Y-m-d\TH:i');
        $this->addItemLine();
        $this->showFormModal = true;
    }

    public function updatedReasonCode(string $value): void
    {
        $reason = StockAdjustmentReasonEnum::tryFrom($value);
        $defaultDir = $reason ? $reason->defaultDirection() : 'OUT';

        foreach ($this->items as $index => $item) {
            $this->items[$index]['direction'] = $defaultDir;
        }
    }

    public function addItemLine(): void
    {
        $reason = StockAdjustmentReasonEnum::tryFrom($this->reason_code);
        $defaultDir = $reason ? $reason->defaultDirection() : 'OUT';

        $this->items[] = [
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null,
            'direction' => $defaultDir,
            'quantity' => '1.0000',
            'unit_cost_snapshot' => '0.0000',
            'reason_note' => null,
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

    public function saveDraft(StockAdjustmentService $service): void
    {
        $this->validate([
            'reason_code' => 'required|string',
            'adjusted_at' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:product,ingredient',
            'items.*.product_id' => 'required_if:items.*.item_type,product|nullable|integer',
            'items.*.ingredient_id' => 'required_if:items.*.item_type,ingredient|nullable|integer',
            'items.*.direction' => 'required|in:IN,OUT',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost_snapshot' => 'nullable|numeric|min:0',
            'items.*.reason_note' => 'nullable|string|max:255',
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

            if ($this->isEditing && $this->editingAdjustmentId) {
                $adjustment = StockAdjustment::findOrFail($this->editingAdjustmentId);
                Gate::authorize('update', $adjustment);

                $service->updateDraft($adjustment, [
                    'reason_code' => $this->reason_code,
                    'adjusted_at' => $this->adjusted_at,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ], $userId);

                session()->flash('message', "Draft penyesuaian stok {$adjustment->adjustment_number} berhasil diperbarui.");
            } else {
                Gate::authorize('create', [StockAdjustment::class, $storeId]);

                $adjustment = $service->createDraft($tenantId, $storeId, [
                    'adjustment_number' => $this->adjustment_number ?: null,
                    'reason_code' => $this->reason_code,
                    'adjusted_at' => $this->adjusted_at,
                    'notes' => $this->notes,
                    'items' => $this->items,
                ], $userId);

                session()->flash('message', "Draft penyesuaian stok {$adjustment->adjustment_number} berhasil dibuat.");
            }

            $this->showFormModal = false;
            $this->resetForm();
        } catch (DomainException|InvalidArgumentException|ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function post(int $adjustmentId, StockAdjustmentService $service): void
    {
        $adjustment = StockAdjustment::findOrFail($adjustmentId);
        Gate::authorize('post', $adjustment);

        try {
            $service->postAdjustment($adjustment, (int) auth()->id());
            session()->flash('message', "Penyesuaian stok {$adjustment->adjustment_number} berhasil diposting ke saldo fisik.");
        } catch (DomainException|InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancel(int $adjustmentId, StockAdjustmentService $service): void
    {
        $adjustment = StockAdjustment::findOrFail($adjustmentId);
        Gate::authorize('cancel', $adjustment);

        try {
            $service->cancelDraft($adjustment, (int) auth()->id());
            session()->flash('message', "Draft penyesuaian stok {$adjustment->adjustment_number} berhasil dibatalkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function viewDetails(int $adjustmentId): void
    {
        $this->selectedAdjustmentId = $adjustmentId;
        $this->showDetailModal = true;
    }

    public function resetForm(): void
    {
        $this->editingAdjustmentId = null;
        $this->adjustment_number = null;
        $this->reason_code = 'DAMAGED';
        $this->adjusted_at = now()->format('Y-m-d\TH:i');
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

        $query = StockAdjustment::query()
            ->with(['items.product', 'items.ingredient', 'creator', 'poster'])
            ->where('tenant_id', $tenantId);

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('adjustment_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->reasonFilter !== 'ALL') {
            $query->where('reason_code', $this->reasonFilter);
        }

        $adjustments = $query->latest('id')->paginate(15);

        $products = Product::where('tenant_id', $tenantId)->orderBy('product_name')->get(['id', 'product_name', 'product_code']);
        $ingredients = Ingredient::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'unit']);

        $selectedAdjustment = null;
        if ($this->selectedAdjustmentId) {
            $selectedAdjustment = StockAdjustment::with(['items.product', 'items.ingredient', 'creator', 'poster', 'store'])
                ->find($this->selectedAdjustmentId);
        }

        return view('livewire.inventory.stock-adjustment-manager', [
            'adjustments' => $adjustments,
            'products' => $products,
            'ingredients' => $ingredients,
            'selectedAdjustment' => $selectedAdjustment,
            'reasons' => StockAdjustmentReasonEnum::cases(),
        ]);
    }
}
