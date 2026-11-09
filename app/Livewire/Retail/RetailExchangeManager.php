<?php

declare(strict_types=1);

namespace App\Livewire\Retail;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Retail\Services\RetailExchangeService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RetailExchangeManager extends Component
{
    use RequiresTenantContext;

    public string $orderCode = '';

    public ?Order $order = null;

    public ?int $selectedOrderItemId = null;

    public ?int $newVariantId = null;

    public int $exchangeQuantity = 1;

    public ?string $priceDiffSummary = null;

    public function searchOrder(): void
    {
        $this->validate([
            'orderCode' => 'required|string',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $this->order = Order::with(['orderItems.product.variants'])
            ->where('tenant_id', $tenantId)
            ->where('order_code', trim($this->orderCode))
            ->first();

        if (! $this->order) {
            session()->flash('error', "Pesanan dengan kode {$this->orderCode} tidak ditemukan.");
            $this->reset(['selectedOrderItemId', 'newVariantId']);
        }
    }

    public function processExchange(): void
    {
        $this->validate([
            'selectedOrderItemId' => 'required|integer',
            'newVariantId' => 'required|integer',
            'exchangeQuantity' => 'required|integer|min:1',
        ]);

        if (! $this->order) {
            return;
        }

        try {
            $service = app(RetailExchangeService::class);
            $service->processExchange(
                orderId: $this->order->id,
                orderItemId: $this->selectedOrderItemId,
                newVariantId: $this->newVariantId,
                quantity: $this->exchangeQuantity
            );

            session()->flash('message', 'Pertukaran barang retail (ukuran/warna) berhasil diproses dan mutasi stok telah diperbarui.');
            $this->reset(['selectedOrderItemId', 'newVariantId', 'exchangeQuantity']);
            $this->searchOrder();
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal memproses pertukaran: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $allVariants = [];

        if ($this->selectedOrderItemId && $this->order) {
            $item = $this->order->orderItems->firstWhere('id', $this->selectedOrderItemId);
            if ($item && $item->product) {
                $allVariants = ProductVariant::where('tenant_id', $tenantId)
                    ->where('product_id', $item->product_id)
                    ->get();
            }
        }

        return view('livewire.retail.retail-exchange-manager', [
            'availableVariants' => $allVariants,
        ]);
    }
}
