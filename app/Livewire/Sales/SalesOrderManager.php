<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\SalesOrder;
use App\Modules\Integration\Logistics\DTOs\ShippingRateOptionDto;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integrations\Logistics\Services\Logistics3PlAggregatorService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SalesOrderManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    #[Url(history: true)]
    public string $fulfillmentFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFulfillmentFilter(): void
    {
        $this->resetPage();
    }

    public bool $showDetailModal = false;

    public bool $showFulfillModal = false;

    public bool $showCancelModal = false;

    public bool $showShippingRateModal = false;

    public string $shippingOriginPostalCode = '10110';

    public string $shippingOriginCity = 'Jakarta Pusat';

    public string $shippingDestPostalCode = '40115';

    public string $shippingDestCity = 'Bandung';

    public int $shippingWeightGrams = 1000;

    /**
     * @var list<ShippingRateOptionDto>
     */
    public array $calculatedShippingRates = [];

    public bool $isCalculatingRates = false;

    #[Locked]
    public ?int $selectedSOId = null;

    public ?SalesOrder $selectedSO = null;

    /**
     * @var array<int, string|float>
     */
    public array $fulfillmentQuantities = [];

    public string $cancelReason = '';

    public function viewOrder(int $id): void
    {
        $so = SalesOrder::with(['customer', 'store', 'lines.product', 'lines.variant', 'lines.uom', 'createdBy', 'confirmedBy', 'cancelledBy', 'quotation', 'orders'])
            ->findOrFail($id);

        $this->authorize('view', $so);

        $this->selectedSOId = $so->id;
        $this->selectedSO = $so;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedSO = null;
        $this->selectedSOId = null;
    }

    public function confirmOrder(SalesOrderService $soService, int $id): void
    {
        $so = SalesOrder::findOrFail($id);
        $this->authorize('confirm', $so);

        $actor = Auth::user();
        try {
            $confirmed = $soService->confirmOrder($so, $actor);
            session()->flash('message', "Sales Order {$confirmed->so_number} confirmed and stock reserved.");
            $this->viewOrder($id);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openFulfillModal(int $id): void
    {
        $so = SalesOrder::with('lines.product')->findOrFail($id);
        $this->authorize('fulfill', $so);

        $this->selectedSOId = $so->id;
        $this->selectedSO = $so;
        $this->fulfillmentQuantities = [];

        foreach ($so->lines as $line) {
            $rem = $line->getRemainingQty();
            $this->fulfillmentQuantities[$line->id] = (float) $rem;
        }

        $this->showFulfillModal = true;
    }

    public function closeFulfillModal(): void
    {
        $this->showFulfillModal = false;
        $this->fulfillmentQuantities = [];
    }

    public function executeFulfillment(SalesOrderService $soService): void
    {
        if (! $this->selectedSOId) {
            return;
        }

        $so = SalesOrder::findOrFail($this->selectedSOId);
        $this->authorize('fulfill', $so);

        $actor = Auth::user();

        $linesToFulfill = [];
        foreach ($this->fulfillmentQuantities as $lineId => $qty) {
            if ((float) $qty > 0) {
                $linesToFulfill[] = [
                    'line_id' => (int) $lineId,
                    'quantity' => (string) $qty,
                ];
            }
        }

        if (empty($linesToFulfill)) {
            session()->flash('error', 'Please enter a fulfillment quantity (> 0) for at least one item.');

            return;
        }

        try {
            $executionOrder = $soService->fulfillOrder($so, $linesToFulfill, $actor);
            $this->showFulfillModal = false;
            session()->flash('message', "Fulfillment processed successfully. Order #{$executionOrder->order_code} created and stock deducted.");

            if ($this->showDetailModal) {
                $this->viewOrder($this->selectedSOId);
            }
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openCancelModal(int $id): void
    {
        $so = SalesOrder::findOrFail($id);
        $this->authorize('cancel', $so);

        $this->selectedSOId = $so->id;
        $this->selectedSO = $so;
        $this->cancelReason = '';
        $this->showCancelModal = true;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->cancelReason = '';
    }

    public function executeCancellation(SalesOrderService $soService): void
    {
        if (! $this->selectedSOId) {
            return;
        }

        $so = SalesOrder::findOrFail($this->selectedSOId);
        $this->authorize('cancel', $so);

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:3'],
        ], [
            'cancelReason.required' => 'Cancellation reason is required.',
        ]);

        $actor = Auth::user();

        try {
            $cancelled = $soService->cancelOrder($so, $actor, $this->cancelReason);
            $this->showCancelModal = false;
            session()->flash('message', "Sales Order {$cancelled->so_number} transitioned to status {$cancelled->status->value}.");

            if ($this->showDetailModal) {
                $this->viewOrder($this->selectedSOId);
            }
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function repeatOrder(SalesOrderService $soService, int $id): void
    {
        $so = SalesOrder::findOrFail($id);
        $this->authorize('create', SalesOrder::class);

        $actor = Auth::user();

        try {
            $repeatSO = $soService->createRepeatOrder($so, $actor);
            session()->flash('message', "Repeat Order created successfully: {$repeatSO->so_number} (Prices and taxes recalculated).");
            $this->viewOrder($repeatSO->id);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openShippingRateModal(int $id): void
    {
        $so = SalesOrder::with(['customer', 'store'])->findOrFail($id);
        $this->authorize('view', $so);

        $this->selectedSOId = $so->id;
        $this->selectedSO = $so;

        $store = $so->store;
        $customer = $so->customer;

        $this->shippingOriginPostalCode = '10110';
        $this->shippingOriginCity = 'Jakarta Pusat';
        $this->shippingDestPostalCode = '40115';
        $this->shippingDestCity = 'Bandung';
        $this->shippingWeightGrams = 1000;
        $this->calculatedShippingRates = [];
        $this->showShippingRateModal = true;
    }

    public function closeShippingRateModal(): void
    {
        $this->showShippingRateModal = false;
        $this->calculatedShippingRates = [];
    }

    public function calculateShippingRates(Logistics3PlAggregatorService $aggregator): void
    {
        $this->validate([
            'shippingOriginPostalCode' => ['required', 'string'],
            'shippingOriginCity' => ['required', 'string'],
            'shippingDestPostalCode' => ['required', 'string'],
            'shippingDestCity' => ['required', 'string'],
            'shippingWeightGrams' => ['required', 'integer', 'min:100'],
        ]);

        $this->isCalculatingRates = true;

        try {
            $req = new ShippingRateRequestDto(
                originPostalCode: $this->shippingOriginPostalCode,
                originCity: $this->shippingOriginCity,
                destinationPostalCode: $this->shippingDestPostalCode,
                destinationCity: $this->shippingDestCity,
                weightGrams: $this->shippingWeightGrams
            );

            $this->calculatedShippingRates = $aggregator->getAllRateOptionsFlat($req);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal menghitung tarif logistik 3PL: '.$e->getMessage());
        } finally {
            $this->isCalculatingRates = false;
        }
    }

    /**
     * Terapkan tarif kurir 3PL terpilih ke Sales Order aktif (TASK-P2-04).
     */
    public function applyShippingRate(string $courierCode, string $serviceName, float $cost): void
    {
        if (! $this->selectedSOId) {
            return;
        }

        $so = SalesOrder::findOrFail($this->selectedSOId);
        $this->authorize('update', $so);

        $costStr = number_format($cost, 2, '.', '');
        $so->shipping_amount = $costStr;

        $notePrefix = "[3PL: {$courierCode} - {$serviceName} (Rp " . number_format($cost, 0, ',', '.') . ")]";
        $so->notes = trim($notePrefix . ' ' . (string) ($so->notes ?? ''));

        // Hitung ulang total_amount = subtotal - discount_amount + tax_amount + shipping_amount via BCMath
        $sub = (string) $so->subtotal;
        $disc = (string) ($so->discount_amount ?? '0.00');
        $tax = (string) ($so->tax_amount ?? '0.00');
        $baseTotal = bcsub($sub, $disc, 2);
        $withTax = bcadd($baseTotal, $tax, 2);
        $so->total_amount = bcadd($withTax, $costStr, 2);
        $so->save();

        $this->showShippingRateModal = false;
        $this->viewOrder($this->selectedSOId);
        $this->dispatch('toast', message: "Tarif kurir {$courierCode} ({$serviceName}) sebesar Rp " . number_format($cost, 0, ',', '.') . " berhasil diterapkan pada SO.", type: 'success');
    }

    public function render(): View
    {
        $this->authorize('viewAny', SalesOrder::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = SalesOrder::with(['customer', 'store', 'lines.product'])
            ->where('tenant_id', $tenantId);

        if ($this->search !== '') {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('so_number', 'like', "%{$s}%")
                    ->orWhere('customer_po_number', 'like', "%{$s}%")
                    ->orWhereHas('customer', function ($cq) use ($s) {
                        $cq->where('name', 'like', "%{$s}%")
                            ->orWhere('customer_code', 'like', "%{$s}%")
                            ->orWhere('company_name', 'like', "%{$s}%");
                    });
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->fulfillmentFilter !== '') {
            $query->where('fulfillment_status', $this->fulfillmentFilter);
        }

        $salesOrders = $query->orderByDesc('id')->paginate(15);

        return view('livewire.sales.sales-order-manager', [
            'salesOrders' => $salesOrders,
        ]);
    }
}
