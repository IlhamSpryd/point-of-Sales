<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Concerns\WithSorting;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class GoodsReceivedNoteManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;
    use WithSorting;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    // Modal State
    public bool $showCreateModal = false;

    public bool $showDetailModal = false;

    public bool $showReverseModal = false;

    // Selected GRN State
    #[Locked]
    public ?int $selectedGrnId = null;

    public ?GoodsReceivedNote $selectedGrn = null;

    // Form Header GRN
    public ?int $selectedPoId = null;

    public ?PurchaseOrder $selectedPo = null;

    public string $receivedDate = '';

    public string $deliveryOrderNumber = '';

    public string $carrierName = '';

    public string $vehicleNumber = '';

    public string $notes = '';

    // Form Lines GRN
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $grnLines = [];

    // Reverse Reason
    public string $reverseReason = '';

    public function mount(): void
    {
        $this->receivedDate = now()->toDateString();
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', GoodsReceivedNote::class);

        $this->resetValidation();
        $this->selectedPoId = null;
        $this->selectedPo = null;
        $this->receivedDate = now()->toDateString();
        $this->deliveryOrderNumber = '';
        $this->carrierName = '';
        $this->vehicleNumber = '';
        $this->notes = '';
        $this->grnLines = [];

        $this->showCreateModal = true;
    }

    public function updatedSelectedPoId(): void
    {
        $this->grnLines = [];

        if (! $this->selectedPoId) {
            $this->selectedPo = null;

            return;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $po = PurchaseOrder::with(['lines.purchasable', 'supplier', 'store'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedPoId);

        $this->selectedPo = $po;

        foreach ($po->lines as $line) {
            $ordered = (float) $line->ordered_qty;
            $received = (float) $line->received_qty;
            $remaining = max(0.0, $ordered - $received);

            if ($remaining <= 0) {
                continue;
            }

            $this->grnLines[] = [
                'purchase_order_line_id' => $line->id,
                'item_name' => $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #'.$line->purchasable_id,
                'item_type' => $line->purchasable_type,
                'purchase_uom' => $line->purchase_uom,
                'ordered_qty' => (string) $ordered,
                'already_received_qty' => (string) $received,
                'remaining_qty' => (string) $remaining,
                'received_quantity' => (string) $remaining,
                'accepted_quantity' => (string) $remaining,
                'rejected_quantity' => '0',
                'rejection_reason' => '',
                'storage_location' => '',
            ];
        }
    }

    public function saveDraftGrn(GoodsReceivedNoteService $service): void
    {
        $this->authorize('create', GoodsReceivedNote::class);

        $this->validate([
            'selectedPoId' => 'required|integer',
            'receivedDate' => 'required|date',
            'deliveryOrderNumber' => 'required|string|max:100',
            'grnLines' => 'required|array|min:1',
            'grnLines.*.accepted_quantity' => 'required|numeric|min:0',
        ], [
            'selectedPoId.required' => 'Please select the Purchase Order (PO) to receive.',
            'deliveryOrderNumber.required' => 'Delivery Order (Vendor Waybill) number is required.',
            'grnLines.min' => 'There are no receivable items on this PO.',
        ]);

        $actor = auth()->user();
        $po = PurchaseOrder::findOrFail($this->selectedPoId);

        $headerData = [
            'received_date' => $this->receivedDate,
            'delivery_order_number' => $this->deliveryOrderNumber,
            'carrier_name' => $this->carrierName ?: null,
            'vehicle_number' => $this->vehicleNumber ?: null,
            'notes' => $this->notes ?: null,
        ];

        try {
            $grn = $service->createGoodsReceivedNote($po, $headerData, $this->grnLines, $actor);

            $this->showCreateModal = false;
            session()->flash('message', "GRN #{$grn->grn_number} created as Draft.");
            $this->viewGrn((int) $grn->id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to create GRN: '.$e->getMessage());
        }
    }

    public function viewGrn(int $id): void
    {
        $grn = GoodsReceivedNote::with(['purchaseOrder.supplier', 'store', 'lines.purchasable'])->findOrFail($id);
        $this->authorize('view', $grn);

        $this->selectedGrnId = $grn->id;
        $this->selectedGrn = $grn;
        $this->showDetailModal = true;
    }

    public function postGrn(int $id, GoodsReceivedNoteService $service): void
    {
        $grn = GoodsReceivedNote::findOrFail($id);
        $this->authorize('update', $grn);

        try {
            $posted = $service->postGrn($grn, auth()->user());
            session()->flash('message', "Goods Receipt #{$posted->grn_number} successfully posted. Stock updated.");
            $this->viewGrn($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to post GRN: '.$e->getMessage());
        }
    }

    public function confirmReverseGrn(int $id): void
    {
        $grn = GoodsReceivedNote::findOrFail($id);
        $this->authorize('update', $grn);

        $this->selectedGrnId = $grn->id;
        $this->selectedGrn = $grn;
        $this->reverseReason = '';
        $this->showReverseModal = true;
    }

    public function executeReverseGrn(GoodsReceivedNoteService $service): void
    {
        if (! $this->selectedGrnId) {
            return;
        }

        $grn = GoodsReceivedNote::findOrFail($this->selectedGrnId);
        $this->authorize('update', $grn);

        $this->validate([
            'reverseReason' => 'required|string|min:5',
        ], [
            'reverseReason.required' => 'Reversal reason is required (minimum 5 characters).',
        ]);

        try {
            $reversed = $service->reverseGrn($grn, auth()->user(), $this->reverseReason);
            $this->showReverseModal = false;
            session()->flash('message', "Goods Receipt #{$reversed->grn_number} successfully reversed. Stock reverted.");
            $this->viewGrn($grn->id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Unable to reverse GRN: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $allowedSortFields = ['grn_number', 'received_date', 'status', 'id'];
        $sortCol = in_array($this->sortField, $allowedSortFields, true) ? $this->sortField : 'id';
        $sortDir = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $query = GoodsReceivedNote::query()
            ->with(['purchaseOrder.supplier', 'store'])
            ->where('tenant_id', $tenantId)
            ->orderBy($sortCol, $sortDir);

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('grn_number', 'like', "%{$this->search}%")
                    ->orWhere('delivery_order_number', 'like', "%{$this->search}%")
                    ->orWhereHas('purchaseOrder', function ($po) {
                        $po->where('po_number', 'like', "%{$this->search}%")
                            ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"));
                    });
            });
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        $grns = $query->paginate(15);

        // Ambil daftar PO yang siap diterima
        $availablePos = PurchaseOrder::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ISSUED', 'PARTIALLY_RECEIVED', 'issued', 'partially_received'])
            ->where(function ($q) {
                $q->whereNull('receiving_status')
                    ->orWhere('receiving_status', '!=', 'FULL');
            })
            ->latest('id')
            ->get();

        return view('livewire.purchasing.goods-received-note-manager', [
            'grns' => $grns,
            'availablePos' => $availablePos,
        ]);
    }
}
