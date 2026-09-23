<?php

namespace App\Livewire\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class IngredientLedger extends Component
{
    use WithPagination;

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
        $movements = IngredientStockMovement::with('ingredient')
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
