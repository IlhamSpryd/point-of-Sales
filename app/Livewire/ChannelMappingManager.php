<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ChannelProductMapping;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ChannelMappingManager extends Component
{
    use WithPagination;

    public string $provider = 'grabfood';

    public string $externalProductId = '';

    public $productId = null;

    public function saveMapping()
    {
        $this->validate([
            'provider' => ['required', 'string', Rule::in(['grabfood', 'gofood'])],
            'externalProductId' => 'required|string|max:255',
            'productId' => 'required|exists:products,id',
        ]);

        ChannelProductMapping::updateOrCreate(
            ['provider' => $this->provider, 'external_product_id' => $this->externalProductId],
            ['product_id' => $this->productId]
        );

        $this->reset(['externalProductId', 'productId']);
        session()->flash('message', 'Pemetaan berhasil disimpan.');
    }

    public function deleteMapping($id)
    {
        ChannelProductMapping::findOrFail($id)->delete();
        session()->flash('message', 'Pemetaan berhasil dihapus.');
    }

    public function render()
    {
        return view('livewire.channel-mapping-manager', [
            'mappings' => ChannelProductMapping::with('product:id,product_name')->orderBy('created_at', 'desc')->paginate(20),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name']),
        ])->layout('layouts.app');
    }
}
