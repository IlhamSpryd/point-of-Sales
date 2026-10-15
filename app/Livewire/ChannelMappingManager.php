<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\ChannelProductMapping;
use App\Models\Product;
use App\Services\Context\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ChannelMappingManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $provider = 'grabfood';

    public string $externalProductId = '';

    public $productId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager'], true)) {
            abort(403, 'Anda tidak memiliki hak akses pemetaan kanal integrasi.');
        }
    }

    public function saveMapping()
    {
        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager'], true)) {
            abort(403, 'Anda tidak memiliki hak akses pemetaan kanal integrasi.');
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        $this->validate([
            'provider' => ['required', 'string', Rule::in(['grabfood', 'gofood'])],
            'externalProductId' => 'required|string|max:255',
            'productId' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
        ]);

        ChannelProductMapping::updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => $this->provider, 'external_product_id' => $this->externalProductId],
            ['product_id' => $this->productId]
        );

        $this->reset(['externalProductId', 'productId']);
        session()->flash('message', 'Pemetaan berhasil disimpan.');
    }

    public function deleteMapping($id)
    {
        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager'], true)) {
            abort(403, 'Anda tidak memiliki hak akses pemetaan kanal integrasi.');
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $mapping = ChannelProductMapping::where('tenant_id', $tenantId)->find($id);
        if (! $mapping) {
            abort(404, 'Pemetaan kanal tidak ditemukan.');
        }

        $mapping->delete();
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
