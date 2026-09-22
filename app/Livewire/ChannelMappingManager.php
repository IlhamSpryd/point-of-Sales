<?php

namespace App\Livewire;

use App\Models\ChannelProductMapping;
use App\Models\Product;
use Livewire\Component;

class ChannelMappingManager extends Component
{
    public string $provider = 'grabfood';

    public string $externalProductId = '';

    public $productId = null;

    public function saveMapping()
    {
        $this->validate([
            'provider' => 'required|string',
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
            'mappings' => ChannelProductMapping::with('product')->orderBy('created_at', 'desc')->get(),
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.app');
    }
}
