<?php

namespace App\Livewire\Inventory;

use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ModifierManager extends Component
{
    public $groups = [];

    public $ingredients = [];

    // Form State untuk Group
    public $showGroupForm = false;

    public $editingGroupId = null;

    public $groupForm = [
        'name' => '',
        'selection_type' => 'single',
        'is_required' => false,
    ];

    // Form State untuk Modifier
    public $showModifierForm = false;

    public $editingModifierId = null;

    public $selectedGroupId = null;

    public $modifierForm = [
        'name' => '',
        'extra_price' => 0,
        'is_default' => false,
        'ingredients' => [],
    ];

    public function mount()
    {
        $this->loadData();
        $this->ingredients = Ingredient::where('is_active', true)->orderBy('name')->get();
    }

    public function loadData()
    {
        $this->groups = ModifierGroup::with(['modifiers.ingredients'])->orderBy('id')->get();
    }

    public function render()
    {
        return view('livewire.inventory.modifier-manager')
            ->layout('layouts.app');
    }

    // --- GROUP ACTIONS ---

    public function createGroup()
    {
        $this->resetGroupForm();
        $this->showGroupForm = true;
    }

    public function editGroup($id)
    {
        $group = ModifierGroup::findOrFail($id);
        $this->editingGroupId = $group->id;
        $this->groupForm = [
            'name' => $group->name,
            'selection_type' => $group->selection_type,
            'is_required' => (bool) $group->is_required,
        ];
        $this->showGroupForm = true;
    }

    public function saveGroup()
    {
        $this->validate([
            'groupForm.name' => 'required|string|max:255',
            'groupForm.selection_type' => ['required', Rule::in(['single', 'multiple'])],
            'groupForm.is_required' => 'boolean',
        ]);

        if ($this->editingGroupId) {
            ModifierGroup::find($this->editingGroupId)->update($this->groupForm);
        } else {
            ModifierGroup::create($this->groupForm);
        }

        $this->resetGroupForm();
        $this->loadData();
    }

    public function deleteGroup($id)
    {
        ModifierGroup::findOrFail($id)->delete();
        $this->loadData();
    }

    public function resetGroupForm()
    {
        $this->showGroupForm = false;
        $this->editingGroupId = null;
        $this->groupForm = [
            'name' => '',
            'selection_type' => 'single',
            'is_required' => false,
        ];
    }

    // --- MODIFIER ACTIONS ---

    public function createModifier($groupId)
    {
        $this->resetModifierForm();
        $this->selectedGroupId = $groupId;
        $this->showModifierForm = true;
    }

    public function editModifier($id)
    {
        $modifier = Modifier::with('ingredients')->findOrFail($id);
        $this->editingModifierId = $modifier->id;
        $this->selectedGroupId = $modifier->modifier_group_id;

        $bom = [];
        foreach ($modifier->ingredients as $ing) {
            $bom[] = [
                'id' => $ing->id,
                'quantity' => (float) $ing->pivot->quantity_required,
            ];
        }

        $this->modifierForm = [
            'name' => $modifier->name,
            'extra_price' => $modifier->extra_price,
            'is_default' => (bool) $modifier->is_default,
            'ingredients' => $bom,
        ];
        $this->showModifierForm = true;
    }

    public function saveModifier()
    {
        $this->validate([
            'modifierForm.name' => 'required|string|max:255',
            'modifierForm.extra_price' => 'numeric|min:0',
            'modifierForm.is_default' => 'boolean',
            'modifierForm.ingredients' => 'array',
            'modifierForm.ingredients.*.id' => 'required|exists:ingredients,id',
            'modifierForm.ingredients.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $data = [
            'modifier_group_id' => $this->selectedGroupId,
            'name' => $this->modifierForm['name'],
            'extra_price' => $this->modifierForm['extra_price'],
            'is_default' => $this->modifierForm['is_default'],
        ];

        if ($this->editingModifierId) {
            $modifier = Modifier::find($this->editingModifierId);
            $modifier->update($data);
        } else {
            $modifier = Modifier::create($data);
        }

        // Sync BOM
        $syncData = [];
        if (! empty($this->modifierForm['ingredients'])) {
            foreach ($this->modifierForm['ingredients'] as $ing) {
                if (isset($ing['id']) && isset($ing['quantity'])) {
                    $syncData[$ing['id']] = ['quantity_required' => $ing['quantity']];
                }
            }
        }
        $modifier->ingredients()->sync($syncData);

        $this->resetModifierForm();
        $this->loadData();
    }

    public function deleteModifier($id)
    {
        Modifier::findOrFail($id)->delete();
        $this->loadData();
    }

    public function resetModifierForm()
    {
        $this->showModifierForm = false;
        $this->editingModifierId = null;
        $this->selectedGroupId = null;
        $this->modifierForm = [
            'name' => '',
            'extra_price' => 0,
            'is_default' => false,
            'ingredients' => [],
        ];
    }
}
