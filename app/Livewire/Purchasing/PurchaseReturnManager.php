<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseDebitNote;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PurchaseReturnManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    #[Url(history: true)]
    public ?int $supplierFilter = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierFilter(): void
    {
        $this->resetPage();
    }

    // Modals
    public bool $showCreateModal = false;

    public bool $showDetailModal = false;

    public bool $showApplyModal = false;

    // Selected Return
    #[Locked]
    public ?int $selectedReturnId = null;

    public ?PurchaseReturn $selectedReturn = null;

    // Form Header Retur
    public ?int $selectedGrnId = null;

    public ?GoodsReceivedNote $selectedGrn = null;

    public string $reason = 'DAMAGED';

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $returnLines = [];

    // Apply Debit Note Form
    #[Locked]
    public ?int $selectedDebitNoteId = null;

    public ?PurchaseDebitNote $selectedDebitNote = null;

    public ?int $applyInvoiceId = null;

    public string $applyAmount = '';

    public string $applyNotes = '';

    public function openCreateModal(): void
    {
        Gate::authorize('create', PurchaseReturn::class);

        $this->resetValidation();
        $this->selectedGrnId = null;
        $this->selectedGrn = null;
        $this->reason = 'DAMAGED';
        $this->notes = '';
        $this->returnLines = [];

        $this->showCreateModal = true;
    }

    public function updatedSelectedGrnId(): void
    {
        $this->returnLines = [];

        if (! $this->selectedGrnId) {
            $this->selectedGrn = null;

            return;
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $grn = GoodsReceivedNote::with(['lines.purchasable', 'purchaseOrder.supplier', 'store'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedGrnId);

        $this->selectedGrn = $grn;

        foreach ($grn->lines as $line) {
            $accepted = (float) $line->accepted_quantity;
            if ($accepted <= 0) {
                continue;
            }

            $this->returnLines[] = [
                'goods_received_note_line_id' => $line->id,
                'item_name' => $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #'.$line->purchasable_id,
                'item_type' => $line->purchasable_type,
                'purchase_uom' => $line->purchase_uom,
                'accepted_qty' => (string) $accepted,
                'return_quantity' => '0',
                'unit_cost' => (string) ($line->effective_unit_cost ?? $line->unit_cost_snapshot ?? 0),
                'reason' => 'DAMAGED',
            ];
        }
    }

    public function saveDraftReturn(PurchaseReturnService $service): void
    {
        Gate::authorize('create', PurchaseReturn::class);

        $this->validate([
            'selectedGrnId' => 'required|integer',
            'reason' => 'required|string',
            'returnLines' => 'required|array|min:1',
            'returnLines.*.return_quantity' => 'required|numeric|min:0',
        ], [
            'selectedGrnId.required' => 'Pilih Penerimaan Barang (GRN) asal retur.',
            'returnLines.min' => 'Tidak ada item yang dapat diretur pada GRN ini.',
        ]);

        $activeReturnLines = array_filter($this->returnLines, fn ($l) => (float) $l['return_quantity'] > 0);

        if (empty($activeReturnLines)) {
            session()->flash('error', 'Masukkan kuantitas retur minimal pada 1 baris barang.');

            return;
        }

        $actor = auth()->user();
        $grn = GoodsReceivedNote::with('purchaseOrder')->findOrFail($this->selectedGrnId);

        $headerData = [
            'supplier_id' => $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id,
            'store_id' => $grn->store_id,
            'goods_received_note_id' => $grn->id,
            'reason' => $this->reason,
            'notes' => $this->notes ?: null,
        ];

        try {
            $return = $service->createPurchaseReturn($headerData, array_values($activeReturnLines), $actor);

            $this->showCreateModal = false;
            session()->flash('message', "Retur Pembelian #{$return->return_number} berhasil dibuat sebagai Draf.");
            $this->viewReturn((int) $return->id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal membuat retur pembelian: '.$e->getMessage());
        }
    }

    public function viewReturn(int $id): void
    {
        $ret = PurchaseReturn::with(['supplier', 'store', 'goodsReceivedNote', 'lines.purchasable', 'debitNote.applications'])->findOrFail($id);
        Gate::authorize('view', $ret);

        $this->selectedReturnId = $ret->id;
        $this->selectedReturn = $ret;
        $this->showDetailModal = true;
    }

    public function postReturn(int $id, PurchaseReturnService $service): void
    {
        $ret = PurchaseReturn::findOrFail($id);
        Gate::authorize('update', $ret);

        try {
            $result = $service->postPurchaseReturn($ret->id, auth()->user());
            /** @var PurchaseReturn $updatedReturn */
            $updatedReturn = $result['purchase_return'];
            /** @var PurchaseDebitNote|null $dn */
            $dn = $result['debit_note'] ?? null;

            $dnMsg = $dn ? " Nota Debit #{$dn->debit_note_number} sebesar Rp ".number_format((float) $dn->total_amount, 2).' telah diterbitkan.' : '';
            session()->flash('message', "Retur Pembelian #{$updatedReturn->return_number} berhasil diposting. Stok telah dikurangkan.{$dnMsg}");
            $this->viewReturn($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal memposting retur: '.$e->getMessage());
        }
    }

    public function openApplyModal(int $debitNoteId): void
    {
        $dn = PurchaseDebitNote::with('supplier')->findOrFail($debitNoteId);
        $this->selectedDebitNoteId = $dn->id;
        $this->selectedDebitNote = $dn;
        $this->applyInvoiceId = null;
        $this->applyAmount = (string) $dn->getRemainingUnappliedAmount();
        $this->applyNotes = '';

        $this->showApplyModal = true;
    }

    public function applyDebitNote(PurchaseReturnService $service): void
    {
        if (! $this->selectedDebitNoteId) {
            return;
        }

        $this->validate([
            'applyInvoiceId' => 'required|integer',
            'applyAmount' => 'required|numeric|min:0.01',
        ], [
            'applyInvoiceId.required' => 'Pilih Faktur Pemasok yang akan dipotong oleh Nota Debit ini.',
            'applyAmount.min' => 'Nominal alokasi harus lebih besar dari 0.',
        ]);

        try {
            $service->applyDebitNoteToInvoice(
                $this->selectedDebitNoteId,
                $this->applyInvoiceId,
                $this->applyAmount,
                auth()->user(),
                $this->applyNotes ?: null
            );

            $this->showApplyModal = false;
            session()->flash('message', 'Nota Debit berhasil dipotongkan ke Faktur Pemasok.');
            if ($this->selectedReturnId) {
                $this->viewReturn($this->selectedReturnId);
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal memotongkan nota debit: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $query = PurchaseReturn::query()
            ->with(['supplier', 'store', 'goodsReceivedNote', 'debitNote'])
            ->where('tenant_id', $tenantId)
            ->latest('id');

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('return_number', 'like', "%{$this->search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"))
                    ->orWhereHas('goodsReceivedNote', fn ($g) => $g->where('grn_number', 'like', "%{$this->search}%"));
            });
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->supplierFilter) {
            $query->where('supplier_id', $this->supplierFilter);
        }

        $returns = $query->paginate(15);

        // Ambil GRN yang telah diposting untuk sumber retur
        $availableGrns = GoodsReceivedNote::query()
            ->with(['purchaseOrder.supplier'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['POSTED', 'posted'])
            ->whereNull('reversed_at')
            ->latest('id')
            ->get();

        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        // Ambil faktur yang dapat dipotong debit note (jika modal apply dibuka)
        $availableInvoices = [];
        if ($this->selectedDebitNote) {
            $availableInvoices = SupplierInvoice::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $this->selectedDebitNote->supplier_id)
                ->whereIn('status', ['APPROVED_FOR_PAYMENT', 'PARTIALLY_PAID', 'approved_for_payment', 'partially_paid'])
                ->get();
        }

        return view('livewire.purchasing.purchase-return-manager', [
            'returns' => $returns,
            'availableGrns' => $availableGrns,
            'suppliers' => $suppliers,
            'availableInvoices' => $availableInvoices,
        ]);
    }
}
