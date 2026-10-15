<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class IngredientLedger extends Component
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

    public string $ingredient_id = '';

    public string $type = '';

    public function updatingIngredientId()
    {
        $this->resetPage();
    }

    public function updatingType()
    {
        $this->resetPage();
    }

    public function render()
    {
        $isOwnerConsolidated = auth()->user()?->role?->name === 'Owner' && session('active_store_id') === 'all';
        $query = $isOwnerConsolidated
            ? IngredientStockMovement::allStores()
            : IngredientStockMovement::query();

        $movements = $query->with('ingredient')
            ->when($this->ingredient_id, function ($query) {
                $query->where('ingredient_id', $this->ingredient_id);
            })
            ->when($this->type, function ($query) {
                $query->where('type', $this->type);
            })
            ->latest('created_at')
            ->paginate(15);

        $ingredients = Ingredient::orderBy('name')->get();

        return view('livewire.inventory.ingredient-ledger', [
            'movements' => $movements,
            'ingredients' => $ingredients,
        ]);
    }
}
