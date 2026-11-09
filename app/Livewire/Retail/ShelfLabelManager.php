<?php

declare(strict_types=1);

namespace App\Livewire\Retail;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Product;
use App\Modules\Retail\Models\ShelfLabel;
use App\Modules\Retail\Services\ShelfLabelBatchService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ShelfLabelManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'ALL'; // ALL, PENDING, PRINTED

    public bool $isBatchModalOpen = false;

    public ?int $selectedProductId = null;

    public string $labelType = 'STANDARD';

    public int $quantity = 1;

    public function createLabel(): void
    {
        $this->validate([
            'selectedProductId' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $storeId = (int) (app(TenantContext::class)->getStoreId() ?? 1);
        $product = Product::findOrFail($this->selectedProductId);
        $price = (string) ($product->product_price ?? $product->price ?? $product->selling_price ?? 0);

        $batchNumber = 'BAT-'.now()->format('YmdHis');

        for ($i = 0; $i < $this->quantity; $i++) {
            ShelfLabel::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $product->id,
                'product_variant_id' => null,
                'old_price' => $price,
                'new_price' => $price,
                'batch_number' => $batchNumber,
                'status' => ShelfLabel::STATUS_PENDING,
            ]);
        }

        $this->isBatchModalOpen = false;
        $this->reset(['selectedProductId', 'quantity']);
        $productTitle = $product->product_name ?? $product->name ?? 'Produk';
        session()->flash('message', "Berhasil membuat {$this->quantity} label rak untuk {$productTitle} (Batch: {$batchNumber})");
    }

    public function markBatchPrinted(string $batchNumber): void
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        ShelfLabel::where('tenant_id', $tenantId)
            ->where('batch_number', $batchNumber)
            ->update([
                'status' => ShelfLabel::STATUS_PRINTED,
                'printed_at' => now(),
                'printed_by_user_id' => auth()->id(),
            ]);

        session()->flash('message', "Batch {$batchNumber} berhasil ditandai sudah dicetak.");
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        $query = ShelfLabel::with(['product', 'variant'])
            ->where('tenant_id', $tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('batch_number', 'like', "%{$this->search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->statusFilter !== 'ALL', function ($q) {
                $statusMap = [
                    'PENDING' => ShelfLabel::STATUS_PENDING,
                    'PRINTED' => ShelfLabel::STATUS_PRINTED,
                ];
                $target = $statusMap[strtoupper($this->statusFilter)] ?? $this->statusFilter;
                $q->where('status', $target);
            })
            ->orderByDesc('id');

        $labels = $query->paginate(15);
        $products = Product::where('tenant_id', $tenantId)->orderBy('id')->get();

        return view('livewire.retail.shelf-label-manager', [
            'labels' => $labels,
            'products' => $products,
        ]);
    }
}
