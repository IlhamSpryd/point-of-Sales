<?php

namespace App\Livewire\Inventory;

use App\Enums\StockAdjustmentReasonEnum;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\Store;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class IngredientManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        if (! $user || ! $user->role || ! in_array($user->role->name, ['Owner', 'Manager', 'Inventory'])) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
        }
    }

    public string $search = '';

    // Form properties
    #[Locked]
    public ?int $ingredientId = null;

    public ?string $ingredient_code = null;

    public string $name = '';

    public string $unit = '';

    public ?float $cost_per_unit = null;

    public ?float $reorder_level = null;

    public bool $is_active = true;

    // Adjust Stock properties
    #[Locked]
    public ?int $adjustIngredientId = null;

    public ?float $adjustQuantity = null;

    public string $adjustType = 'purchase_receipt';

    public ?string $adjustReason = null;

    public bool $showModal = false;

    public bool $isEditing = false;

    public bool $showAdjustModal = false;

    protected $rules = [
        'ingredient_code' => 'nullable|string|max:255',
        'name' => 'required|string|max:255',
        'unit' => 'required|string|max:20',
        'cost_per_unit' => 'required|numeric|min:0',
        'reorder_level' => 'required|numeric|min:0',
        'is_active' => 'boolean',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function create()
    {
        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit(int $id)
    {
        $this->resetForm();
        $this->isEditing = true;

        $ingredient = Ingredient::findOrFail($id);
        $this->ingredientId = $ingredient->id;
        $this->ingredient_code = $ingredient->ingredient_code;
        $this->name = $ingredient->name;
        $this->unit = $ingredient->unit;
        $this->cost_per_unit = $ingredient->cost_per_unit;
        $this->reorder_level = $ingredient->reorder_level;
        $this->is_active = $ingredient->is_active;

        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();
        // [D2-BE-004] Otorisasi policy CRUD bahan baku.
        Gate::authorize($this->isEditing ? 'update' : 'create', Ingredient::class);

        if ($this->isEditing) {
            $ingredient = Ingredient::findOrFail($this->ingredientId);
            // validate unique ingredient_code ignoring current (per tenant)
            $this->validate([
                'ingredient_code' => ['nullable', 'string', 'max:255', Rule::unique('ingredients', 'ingredient_code')->where('tenant_id', app(TenantContext::class)->requireTenantId())->ignore($ingredient->id)],
            ]);
            $ingredient->update([
                'ingredient_code' => $this->ingredient_code,
                'name' => $this->name,
                'unit' => $this->unit,
                'cost_per_unit' => $this->cost_per_unit,
                'reorder_level' => $this->reorder_level,
                'is_active' => $this->is_active,
            ]);
            session()->flash('success', 'Bahan baku berhasil diperbarui.');
        } else {
            $this->validate([
                'ingredient_code' => ['nullable', 'string', 'max:255', Rule::unique('ingredients', 'ingredient_code')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
            ]);
            Ingredient::create([
                'ingredient_code' => $this->ingredient_code,
                'name' => $this->name,
                'unit' => $this->unit,
                'cost_per_unit' => $this->cost_per_unit,
                'reorder_level' => $this->reorder_level,
                'is_active' => $this->is_active,
                'current_stock' => 0, // initially 0
            ]);
            session()->flash('success', 'Bahan baku berhasil ditambahkan.');
        }

        $this->showModal = false;
    }

    public function delete(int $id)
    {
        $ingredient = Ingredient::findOrFail($id);
        Gate::authorize('delete', $ingredient);
        $ingredient->delete();
        session()->flash('success', 'Bahan baku berhasil dihapus.');
    }

    public function adjustStock(int $id)
    {
        $this->resetAdjustForm();
        $this->adjustIngredientId = $id;
        $this->showAdjustModal = true;
    }

    public function saveAdjustment(StockAdjustmentService $adjustmentService)
    {
        $ingredient = Ingredient::findOrFail($this->adjustIngredientId);
        Gate::authorize('adjustStock', $ingredient);

        $this->validate([
            'adjustQuantity' => 'required|numeric',
            'adjustType' => 'required|string|in:purchase_receipt,waste,adjustment',
            'adjustReason' => 'nullable|string|max:500',
        ]);

        $rawQty = (float) $this->adjustQuantity;
        if ($rawQty == 0.0) {
            $this->addError('adjustQuantity', 'Kuantitas tidak boleh 0.');

            return;
        }

        if ($this->adjustType === 'purchase_receipt' && $rawQty <= 0) {
            $this->addError('adjustQuantity', 'Kuantitas penerimaan barang (purchase receipt) harus bernilai lebih dari 0.');

            return;
        }

        // Tentukan arah mutasi & reason code resmi Modul 4
        if ($this->adjustType === 'waste') {
            $direction = 'OUT';
            $reasonCode = StockAdjustmentReasonEnum::ProductionWaste;
            $qty = abs($rawQty);
        } elseif ($this->adjustType === 'purchase_receipt') {
            $direction = 'IN';
            $reasonCode = StockAdjustmentReasonEnum::OpeningStock;
            $qty = abs($rawQty);
        } else {
            // General adjustment
            if ($rawQty < 0) {
                $direction = 'OUT';
                $reasonCode = StockAdjustmentReasonEnum::Shrinkage;
                $qty = abs($rawQty);
            } else {
                $direction = 'IN';
                $reasonCode = StockAdjustmentReasonEnum::Found;
                $qty = abs($rawQty);
            }
        }

        $context = app(TenantContext::class);
        $tenantId = $context->requireTenantId();
        $storeId = $context->getStoreId() ?? (int) Store::where('tenant_id', $tenantId)->orderBy('id')->value('id');

        if (! $storeId) {
            $this->addError('adjustQuantity', 'Cabang aktif tidak ditemukan.');

            return;
        }

        try {
            $adjustmentService->createAndPostDirect(
                tenantId: $tenantId,
                storeId: $storeId,
                data: [
                    'reason_code' => $reasonCode,
                    'notes' => $this->adjustReason ?: "Penyesuaian stok bahan baku {$ingredient->name}",
                    'items' => [
                        [
                            'item_type' => 'ingredient',
                            'ingredient_id' => $ingredient->id,
                            'direction' => $direction,
                            'quantity' => (string) $qty,
                            'unit_cost_snapshot' => (string) ($ingredient->cost_per_unit ?? '0.0000'),
                            'reason_note' => $this->adjustReason,
                        ],
                    ],
                ],
                userId: (int) (Auth::id() ?? 1)
            );
        } catch (\DomainException $e) {
            $this->addError('adjustQuantity', $e->getMessage());

            return;
        }

        session()->flash('success', 'Stok berhasil disesuaikan.');
        $this->showAdjustModal = false;
    }

    private function resetForm()
    {
        $this->reset(['ingredientId', 'ingredient_code', 'name', 'unit', 'cost_per_unit', 'reorder_level', 'is_active']);
        $this->resetValidation();
    }

    private function resetAdjustForm()
    {
        $this->reset(['adjustIngredientId', 'adjustQuantity', 'adjustType', 'adjustReason']);
        $this->resetValidation();
        $this->adjustType = 'purchase_receipt';
    }

    public function render()
    {
        $storeId = app(TenantContext::class)->getStoreId();

        $ingredients = Ingredient::query()
            ->select('ingredients.*')
            ->when($storeId, function ($query) use ($storeId) {
                $query->addSelect([
                    'store_stock' => DB::table('ingredient_stock_balances')
                        ->select('quantity')
                        ->whereColumn('ingredient_stock_balances.ingredient_id', 'ingredients.id')
                        ->where('ingredient_stock_balances.store_id', $storeId)
                        ->limit(1),
                ]);
            })
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('ingredient_code', 'like', '%'.$this->search.'%');
                });
            })
            ->latest('ingredients.created_at')
            ->paginate(10);

        return view('livewire.inventory.ingredient-manager', compact('ingredients'));
    }
}
