<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class GoodsReceivedNoteManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

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
        Gate::authorize('create', GoodsReceivedNote::class);

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
        Gate::authorize('create', GoodsReceivedNote::class);

        $this->validate([
            'selectedPoId' => 'required|integer',
            'receivedDate' => 'required|date',
            'deliveryOrderNumber' => 'required|string|max:100',
            'grnLines' => 'required|array|min:1',
            'grnLines.*.accepted_quantity' => 'required|numeric|min:0',
        ], [
            'selectedPoId.required' => 'Pilih Pesanan Pembelian (PO) yang diterima.',
            'deliveryOrderNumber.required' => 'Nomor surat jalan (Surat Pengantar Vendor) wajib diisi.',
            'grnLines.min' => 'Tidak ada item yang dapat diterima pada PO ini.',
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
            session()->flash('message', "GRN #{$grn->grn_number} berhasil dibuat sebagai Draf.");
            $this->viewGrn((int) $grn->id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal membuat GRN: '.$e->getMessage());
        }
    }

    public function viewGrn(int $id): void
    {
        $grn = GoodsReceivedNote::with(['purchaseOrder.supplier', 'store', 'lines.purchasable'])->findOrFail($id);
        Gate::authorize('view', $grn);

        $this->selectedGrnId = $grn->id;
        $this->selectedGrn = $grn;
        $this->showDetailModal = true;
    }

    public function postGrn(int $id, GoodsReceivedNoteService $service): void
    {
        $grn = GoodsReceivedNote::findOrFail($id);
        Gate::authorize('update', $grn);

        try {
            $posted = $service->postGrn($grn, auth()->user());
            session()->flash('message', "Penerimaan Barang #{$posted->grn_number} berhasil diposting. Stok telah diperbarui.");
            $this->viewGrn($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal memposting GRN: '.$e->getMessage());
        }
    }

    public function confirmReverseGrn(int $id): void
    {
        $grn = GoodsReceivedNote::findOrFail($id);
        Gate::authorize('update', $grn);

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
        Gate::authorize('update', $grn);

        $this->validate([
            'reverseReason' => 'required|string|min:5',
        ], [
            'reverseReason.required' => 'Alasan pembatalan/reversal wajib diisi minimal 5 karakter.',
        ]);

        try {
            $reversed = $service->reverseGrn($grn, auth()->user(), $this->reverseReason);
            $this->showReverseModal = false;
            session()->flash('message', "Penerimaan Barang #{$reversed->grn_number} berhasil dibatalkan (Reversed). Stok telah dikurangi kembali.");
            $this->viewGrn($grn->id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal membalikkan GRN: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $query = GoodsReceivedNote::query()
            ->with(['purchaseOrder.supplier', 'store'])
            ->where('tenant_id', $tenantId)
            ->latest('id');

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
