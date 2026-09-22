<?php

namespace App\Livewire\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class IngredientManager extends Component
{
    use WithPagination;

    public $search = '';

    // Form properties
    public $ingredientId;

    public $ingredient_code;

    public $name;

    public $unit;

    public $cost_per_unit;

    public $reorder_level;

    public $is_active = true;

    // Adjust Stock properties
    public $adjustIngredientId;

    public $adjustQuantity;

    public $adjustType = 'purchase_receipt';

    public $adjustReason;

    public $showModal = false;

    public $isEditing = false;

    public $showAdjustModal = false;

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

    public function edit($id)
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

        if ($this->isEditing) {
            $ingredient = Ingredient::findOrFail($this->ingredientId);
            // validate unique ingredient_code ignoring current
            $this->validate([
                'ingredient_code' => 'nullable|string|max:255|unique:ingredients,ingredient_code,'.$ingredient->id,
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
                'ingredient_code' => 'nullable|string|max:255|unique:ingredients,ingredient_code',
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

    public function delete($id)
    {
        $ingredient = Ingredient::findOrFail($id);
        $ingredient->delete();
        session()->flash('success', 'Bahan baku berhasil dihapus.');
    }

    public function adjustStock($id)
    {
        $this->resetAdjustForm();
        $this->adjustIngredientId = $id;
        $this->showAdjustModal = true;
    }

    public function saveAdjustment()
    {
        $this->validate([
            'adjustQuantity' => 'required|numeric',
            'adjustType' => 'required|string|in:purchase_receipt,waste,adjustment',
            'adjustReason' => 'nullable|string|max:500',
        ]);

        if ($this->adjustQuantity == 0) {
            $this->addError('adjustQuantity', 'Kuantitas tidak boleh 0.');

            return;
        }

        // Jika waste atau pemotongan, pastikan negatif
        $qty = (float) $this->adjustQuantity;
        if ($this->adjustType === 'waste' && $qty > 0) {
            $qty = -$qty;
        }

        DB::transaction(function () use ($qty) {
            $ingredient = Ingredient::lockForUpdate()->findOrFail($this->adjustIngredientId);

            IngredientStockMovement::create([
                'ingredient_id' => $ingredient->id,
                'type' => $this->adjustType,
                'quantity' => $qty,
                'unit_cost' => $ingredient->cost_per_unit,
                'reason' => $this->adjustReason,
                'idempotency_key' => 'adj_'.Str::uuid()->toString(),
                'created_by' => auth()->id(),
            ]);

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
        $ingredients = Ingredient::query()
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('ingredient_code', 'like', '%'.$this->search.'%');
            })
            ->latest()
            ->paginate(10);

        return view('livewire.inventory.ingredient-manager', compact('ingredients'))->layout('layouts.app');
    }
}
