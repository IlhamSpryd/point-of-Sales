<?php

declare(strict_types=1);

namespace App\Livewire\Retail;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Retail\Services\RetailBarcodeService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class BarcodeManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $barcodeToValidate = '';

    public ?bool $isValidEan13 = null;

    public ?int $selectedVariantId = null;

    public string $generatedBarcode = '';

    public function validateBarcode(): void
    {
        $this->validate([
            'barcodeToValidate' => 'required|string',
        ]);

        $service = app(RetailBarcodeService::class);
        $this->isValidEan13 = $service->validateEan13($this->barcodeToValidate);
    }

    public function generateInternalBarcodeForVariant(int $variantId): void
    {
        $service = app(RetailBarcodeService::class);
        $variant = ProductVariant::findOrFail($variantId);

        $barcode = $service->generateInternalBarcode();
        $service->assignBarcodeToVariant($variantId, $barcode);

        $this->generatedBarcode = $barcode;
        session()->flash('message', "Barcode internal {$barcode} berhasil dibuat dan dipasangkan ke varian {$variant->variant_name} ({$variant->sku}).");
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        $variants = ProductVariant::with('product')
            ->where('tenant_id', $tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('sku', 'like', "%{$this->search}%")
                        ->orWhere('barcode', 'like', "%{$this->search}%")
                        ->orWhere('variant_name', 'like', "%{$this->search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->orderBy('id')
            ->paginate(15);

        return view('livewire.retail.barcode-manager', [
            'variants' => $variants,
        ]);
    }
}

