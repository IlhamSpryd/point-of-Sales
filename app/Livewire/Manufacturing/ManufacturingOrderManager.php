<?php

declare(strict_types=1);

namespace App\Livewire\Manufacturing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Product;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ManufacturingOrderManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'ALL';

    public bool $isCreateModalOpen = false;

    public ?int $selectedProductId = null;

    public string $plannedQuantity = '1.0000';

    public ?string $plannedStartDate = null;

    public ?string $notes = null;

    public function createOrder(): void
    {
        $this->validate([
            'selectedProductId' => 'required|integer|exists:products,id',
            'plannedQuantity' => 'required|numeric|min:0.0001',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $storeId = (int) (app(TenantContext::class)->getStoreId() ?? 1);

        $service = app(ManufacturingOrderService::class);
        $order = $service->createOrder(
            tenantId: $tenantId,
            storeId: $storeId,
            productId: $this->selectedProductId,
            plannedQuantity: $this->plannedQuantity,
            plannedStartDate: $this->plannedStartDate,
            notes: $this->notes,
            userId: auth()->id()
        );

        $this->isCreateModalOpen = false;
        $this->reset(['selectedProductId', 'plannedQuantity', 'notes']);
        session()->flash('message', "Manufacturing Order {$order->order_number} berhasil dibuat.");
    }

    public function startOrder(int $orderId): void
    {
        app(ManufacturingOrderService::class)->startProduction($orderId);
        session()->flash('message', 'Status produksi diubah menjadi IN_PROGRESS.');
    }

    public function completeOrder(int $orderId): void
    {
        try {
            app(ManufacturingOrderService::class)->completeProduction($orderId);
            session()->flash('message', 'Pesanan produksi selesai. Bahan baku telah dipotong dan stok barang jadi telah ditambahkan.');
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal menyelesaikan produksi: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        $orders = ManufacturingOrder::with('product')
            ->where('tenant_id', $tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where('order_number', 'like', "%{$this->search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$this->search}%"));
            })
            ->when($this->statusFilter !== 'ALL', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->orderByDesc('id')
            ->paginate(15);

        $products = Product::where('tenant_id', $tenantId)->orderBy('product_name')->get(['id', 'product_name']);

        return view('livewire.manufacturing.manufacturing-order-manager', [
            'orders' => $orders,
            'products' => $products,
        ]);
    }
}
