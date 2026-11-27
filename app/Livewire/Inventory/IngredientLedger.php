<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Platform\Authorization\AbilityEvaluator;
use App\Platform\Authorization\Scope;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
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
        $this->authorize('viewAny', IngredientStockMovement::class);
    }

    #[Url(history: true)]
    public string $ingredient_id = '';

    #[Url(history: true)]
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
        $authUser = auth()->user();
        $isTenantWideScope = $authUser instanceof \App\Modules\Organization\Models\User
            && app(AbilityEvaluator::class)->resolveUserScope($authUser) === Scope::TENANT
            && session('active_store_id') === 'all';
        $query = $isTenantWideScope
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
