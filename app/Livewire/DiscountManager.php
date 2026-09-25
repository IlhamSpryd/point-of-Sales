<?php

namespace App\Livewire;

use App\Models\Discount;
use Livewire\Component;
use Livewire\WithPagination;

class DiscountManager extends Component
{
    use WithPagination;

    public $search = '';

    public $isModalOpen = false;

    public $discountId;

    public $code = '';

    public $name = '';

    public $type = 'percentage';

    public $value = 0;

    public $max_discount_amount = null;

    public $min_purchase_amount = 0;

    public $is_active = true;

    public $valid_from = null;

    public $valid_until = null;

    protected $rules = [
        'name' => 'required|string|max:255',
        'code' => 'nullable|string|max:50|unique:discounts,code',
        'type' => 'required|in:percentage,fixed',
        'value' => 'required|integer|min:0',
        'max_discount_amount' => 'nullable|integer|min:0',
        'min_purchase_amount' => 'required|integer|min:0',
        'is_active' => 'boolean',
        'valid_from' => 'nullable|date',
        'valid_until' => 'nullable|date|after_or_equal:valid_from',
    ];

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function create()
    {
        $this->resetInputFields();
        $this->openModal();
    }

    public function openModal(): void
    {
        $this->isModalOpen = true;
        $this->dispatch('open-modal', 'discount-form');
    }

    public function closeModal(): void
    {
        $this->isModalOpen = false;
        $this->dispatch('close-modal', 'discount-form');
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->discountId = null;
        $this->code = '';
        $this->name = '';
        $this->type = 'percentage';
        $this->value = 0;
        $this->max_discount_amount = null;
        $this->min_purchase_amount = 0;
        $this->is_active = true;
        $this->valid_from = null;
        $this->valid_until = null;
        $this->resetValidation();
    }

    public function store()
    {
        $rules = $this->rules;
        if ($this->discountId) {
            $rules['code'] = 'nullable|string|max:50|unique:discounts,code,'.$this->discountId;
        }

        $this->validate($rules);

        // Jika persentase, batas maksimal value adalah 100
        if ($this->type === 'percentage' && $this->value > 100) {
            $this->addError('value', 'Persentase diskon tidak boleh lebih dari 100%.');

            return;
        }

        Discount::updateOrCreate(
            ['id' => $this->discountId],
            [
                'code' => $this->code ?: null,
                'name' => $this->name,
                'type' => $this->type,
                'value' => $this->value,
                'max_discount_amount' => $this->type === 'percentage' ? $this->max_discount_amount : null,
                'min_purchase_amount' => $this->min_purchase_amount,
                'is_active' => $this->is_active,
                'valid_from' => $this->valid_from ?: null,
                'valid_until' => $this->valid_until ?: null,
            ]
        );

        session()->flash('message', $this->discountId ? 'Diskon berhasil diperbarui.' : 'Diskon berhasil dibuat.');
        $this->closeModal();
        $this->dispatch('close-modal', 'discount-form');
    }

    public function edit($id)
    {
        $discount = Discount::findOrFail($id);
        $this->discountId = $id;
        $this->code = $discount->code;
        $this->name = $discount->name;
        $this->type = $discount->type;
        $this->value = $discount->value;
        $this->max_discount_amount = $discount->max_discount_amount;
        $this->min_purchase_amount = $discount->min_purchase_amount;
        $this->is_active = $discount->is_active;
        $this->valid_from = $discount->valid_from ? $discount->valid_from->format('Y-m-d\TH:i') : null;
        $this->valid_until = $discount->valid_until ? $discount->valid_until->format('Y-m-d\TH:i') : null;

        $this->openModal();
    }

    public function delete($id)
    {
        Discount::findOrFail($id)->delete();
        session()->flash('message', 'Diskon berhasil dihapus.');
    }

    public function render()
    {
        $discounts = Discount::where('name', 'like', '%'.$this->search.'%')
            ->orWhere('code', 'like', '%'.$this->search.'%')
            ->orderBy('id', 'desc')
            ->paginate(10);

        return view('livewire.discount-manager', compact('discounts'));
    }
}
