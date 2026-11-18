<?php

declare(strict_types=1);

namespace App\Livewire\Manufacturing;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Enterprise Discrete Manufacturing & Semi-Finished Kitchen Prep Order Manager (TASK-P2-01, ADR-102).
 */
class ManufacturingOrderManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'ALL';

    public bool $isCreateModalOpen = false;

    public string $orderType = 'product'; // 'product' or 'prep'

    public ?int $selectedProductId = null;

    public ?int $selectedIngredientId = null;

    public string $plannedQuantity = '1.0000';

    public ?string $plannedStartDate = null;

    public ?string $notes = null;

    public function createOrder(): void
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $storeId = (int) (app(TenantContext::class)->getStoreId() ?? 1);
        $service = app(ManufacturingOrderService::class);

        if ($this->orderType === 'prep') {
            $this->validate([
                'selectedIngredientId' => [
                    'required',
                    'integer',
                    Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId),
                ],
                'plannedQuantity' => 'required|numeric|min:0.0001',
            ]);

            $order = $service->createPrepOrder(
                tenantId: $tenantId,
                storeId: $storeId,
                ingredientId: (int) $this->selectedIngredientId,
                plannedQuantity: $this->plannedQuantity,
                plannedStartDate: $this->plannedStartDate,
                notes: $this->notes,
                userId: auth()->id()
            );

            $this->isCreateModalOpen = false;
            $this->reset(['selectedProductId', 'selectedIngredientId', 'plannedQuantity', 'notes', 'orderType']);
            session()->flash('message', "Perintah Kerja Kitchen Prep {$order->order_number} berhasil dibuat.");

            return;
        }

        $this->validate([
            'selectedProductId' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'plannedQuantity' => 'required|numeric|min:0.0001',
        ]);

        $order = $service->createOrder(
            tenantId: $tenantId,
            storeId: $storeId,
            productId: (int) $this->selectedProductId,
            plannedQuantity: $this->plannedQuantity,
            plannedStartDate: $this->plannedStartDate,
            notes: $this->notes,
            userId: auth()->id()
        );

        $this->isCreateModalOpen = false;
        $this->reset(['selectedProductId', 'selectedIngredientId', 'plannedQuantity', 'notes', 'orderType']);
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
            session()->flash('message', 'Pesanan produksi selesai. Bahan baku telah dipotong dan stok barang jadi/olahan telah ditambahkan.');
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal menyelesaikan produksi: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();

        $orders = ManufacturingOrder::with(['product', 'destinationIngredient'])
            ->where('tenant_id', $tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where('order_number', 'like', "%{$this->search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$this->search}%"))
                    ->orWhereHas('destinationIngredient', fn ($i) => $i->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->statusFilter !== 'ALL', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->orderByDesc('id')
            ->paginate(15);

        $products = Product::where('tenant_id', $tenantId)->orderBy('product_name')->get(['id', 'product_name']);
        $ingredients = Ingredient::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'unit']);

        return view('livewire.manufacturing.manufacturing-order-manager', [
            'orders' => $orders,
            'products' => $products,
            'ingredients' => $ingredients,
        ]);
    }
}
