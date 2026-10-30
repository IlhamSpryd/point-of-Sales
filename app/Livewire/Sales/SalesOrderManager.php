<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
        $so = SalesOrder::with(['customer', 'store', 'lines.product', 'lines.variant', 'createdBy', 'confirmedBy', 'cancelledBy', 'quotation', 'orders'])
            ->findOrFail($id);

        Gate::authorize('view', $so);

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
        Gate::authorize('confirm', $so);

        $actor = Auth::user();
        try {
            $confirmed = $soService->confirmOrder($so, $actor);
            session()->flash('message', "Sales Order {$confirmed->so_number} BERHASIL dikonfirmasi (CONFIRMED) dan stok direservasi.");
            $this->viewOrder($id);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openFulfillModal(int $id): void
    {
        $so = SalesOrder::with('lines.product')->findOrFail($id);
        Gate::authorize('fulfill', $so);

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
        Gate::authorize('fulfill', $so);

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
            session()->flash('error', 'Masukkan kuantitas pemenuhan (> 0) minimal untuk satu item.');

            return;
        }

        try {
            $executionOrder = $soService->fulfillOrder($so, $linesToFulfill, $actor);
            $this->showFulfillModal = false;
            session()->flash('message', "Fulfillment berhasil diproses! Dokumen Order #{$executionOrder->order_code} telah dibuat dan stok dipotong.");

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
        Gate::authorize('cancel', $so);

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
        Gate::authorize('cancel', $so);

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:3'],
        ], [
            'cancelReason.required' => 'Alasan pembatalan pesanan wajib diisi.',
        ]);

        $actor = Auth::user();

        try {
            $cancelled = $soService->cancelOrder($so, $actor, $this->cancelReason);
            $this->showCancelModal = false;
            session()->flash('message', "Sales Order {$cancelled->so_number} telah dialihkan ke status {$cancelled->status->value}.");

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
        Gate::authorize('create', SalesOrder::class);

        $actor = Auth::user();

        try {
            $repeatSO = $soService->createRepeatOrder($so, $actor);
            session()->flash('message', "Repeat Order BERHASIL dibuat: {$repeatSO->so_number} (Harga & Pajak dihitung ulang per hari ini).");
            $this->viewOrder($repeatSO->id);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        Gate::authorize('viewAny', SalesOrder::class);

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
