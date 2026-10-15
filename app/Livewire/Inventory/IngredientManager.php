<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Policies\IngredientPolicy;
use Illuminate\Support\Facades\Gate;
use App\Models\IngredientStockMovement;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function saveAdjustment()
    {
        Gate::authorize('adjustStock', Ingredient::findOrFail($this->adjustIngredientId));
        $this->validate([
            'adjustQuantity' => 'required|numeric',
            'adjustType' => 'required|string|in:purchase_receipt,waste,adjustment',
            'adjustReason' => 'nullable|string|max:500',
        ]);

        if ($this->adjustType === 'purchase_receipt' && (float) $this->adjustQuantity <= 0) {
            $this->addError('adjustQuantity', 'Kuantitas penerimaan barang (purchase receipt) harus bernilai lebih dari 0.');

            return;
        }

        if ($this->adjustQuantity == 0) {
            $this->addError('adjustQuantity', 'Kuantitas tidak boleh 0.');

            return;
        }

        // Jika waste atau pemotongan, pastikan negatif
        $qty = (float) $this->adjustQuantity;
        if ($this->adjustType === 'waste' && $qty > 0) {
            $qty = -$qty;
        }

        $storeId = app(TenantContext::class)->getStoreId();

        // [CF-07 / D3-013] Pencegahan saldo negatif
        $ingredient = Ingredient::findOrFail($this->adjustIngredientId);
        if ($storeId) {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeBalance = (float) (DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('ingredient_id', $ingredient->id)
                ->value('quantity') ?? $ingredient->current_stock);

            if ($storeBalance + $qty < 0) {
                $this->addError('adjustQuantity', 'Saldo stok cabang tidak mencukupi untuk pengurangan ini (stok saat ini: '.$storeBalance.').');

                return;
            }
        } elseif ($ingredient->current_stock + $qty < 0) {
            $this->addError('adjustQuantity', 'Stok bahan baku tidak mencukupi untuk pengurangan ini.');

            return;
        }

        DB::transaction(function () use ($qty, $storeId) {
            $ingredient = Ingredient::lockForUpdate()->findOrFail($this->adjustIngredientId);

            IngredientStockMovement::create([
                'ingredient_id' => $ingredient->id,
                'type' => $this->adjustType,
                'quantity' => $qty,
                'unit_cost' => $ingredient->cost_per_unit,
                'reason' => $this->adjustReason,
                'idempotency_key' => 'adj_'.Str::uuid()->toString(),
                'created_by' => Auth::id(),
            ]);

            // Pastikan baris balance cabang aktif ada sebelum Ingredient::saved() memperbarui delta
            if ($storeId) {
                DB::table('ingredient_stock_balances')->insertOrIgnore([
                    'tenant_id' => app(TenantContext::class)->requireTenantId(),
                    'store_id' => $storeId,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 0,
                    'baseline_quantity' => 0,
                    'baseline_movement_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // [CF-07 / D3-013] Hapus update manual duplikat DB::raw('quantity + qty').
            // Listener Ingredient::saved() akan menambahkan delta ($qty) ke ingredient_stock_balances secara otomatis.
            $ingredient->current_stock += $qty;
            $ingredient->save();
        });

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
