<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SupplierInvoiceManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public ?int $supplierFilter = null;

    // Modals
    public bool $showCreateModal = false;

    public bool $showDetailModal = false;

    public bool $showMatchModal = false;

    public bool $showVarianceModal = false;

    // Selected Invoice
    #[Locked]
    public ?int $selectedInvoiceId = null;

    public ?SupplierInvoice $selectedInvoice = null;

    // Create Form
    public ?int $selectedPoId = null;

    public ?PurchaseOrder $selectedPo = null;

    public string $supplierInvoiceNumber = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $invoiceLines = [];

    // 3-Way Match Workbench State
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $matchPlanLines = [];

    public string $varianceNotes = '';

    public function mount(): void
    {
        $this->invoiceDate = now()->toDateString();
        $this->dueDate = now()->addDays(14)->toDateString();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', SupplierInvoice::class);

        $this->resetValidation();
        $this->selectedPoId = null;
        $this->selectedPo = null;
        $this->supplierInvoiceNumber = '';
        $this->invoiceDate = now()->toDateString();
        $this->dueDate = now()->addDays(14)->toDateString();
        $this->notes = '';
        $this->invoiceLines = [];

        $this->showCreateModal = true;
    }

    public function updatedSelectedPoId(): void
    {
        $this->invoiceLines = [];

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
            $remaining = (float) $line->getRemainingInvoiceableQty();
            if ($remaining <= 0) {
                continue;
            }

            $this->invoiceLines[] = [
                'purchase_order_line_id' => $line->id,
                'purchasable_type' => $line->purchasable_type,
                'purchasable_id' => $line->purchasable_id,
                'item_name' => $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #'.$line->purchasable_id,
                'purchase_uom' => $line->purchase_uom,
                'remaining_qty' => (string) $remaining,
                'invoiced_qty' => (string) $remaining,
                'unit_price' => (string) $line->unit_price,
            ];
        }
    }

    public function saveInvoice(SupplierInvoiceMatchingService $service): void
    {
        Gate::authorize('create', SupplierInvoice::class);

        $this->validate([
            'selectedPoId' => 'required|integer',
            'supplierInvoiceNumber' => 'required|string|max:100',
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'invoiceLines' => 'required|array|min:1',
            'invoiceLines.*.invoiced_qty' => 'required|numeric|min:0.0001',
            'invoiceLines.*.unit_price' => 'required|numeric|min:0',
        ], [
            'selectedPoId.required' => 'Pilih Pesanan Pembelian (PO) yang ditagihkan.',
            'supplierInvoiceNumber.required' => 'Nomor faktur dari pemasok wajib diisi.',
            'invoiceLines.min' => 'Tidak ada item yang dapat ditagihkan pada PO ini.',
        ]);

        $actor = auth()->user();
        $po = PurchaseOrder::findOrFail($this->selectedPoId);

        $headerData = [
            'store_id' => $po->store_id,
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'supplier_invoice_number' => $this->supplierInvoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate,
            'notes' => $this->notes ?: null,
        ];

        try {
            $invoice = $service->createSupplierInvoice($headerData, $this->invoiceLines, $actor);

            $this->showCreateModal = false;
            session()->flash('message', "Faktur Pemasok #{$invoice->supplier_invoice_number} berhasil dicatat sebagai Draf.");
            $this->openMatchWorkbench((int) $invoice->id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal mencatat faktur: '.$e->getMessage());
        }
    }

    public function viewInvoice(int $id): void
    {
        $inv = SupplierInvoice::with(['supplier', 'store', 'lines.purchasable', 'grnMatches.goodsReceivedNoteLine'])->findOrFail($id);
        Gate::authorize('view', $inv);

        $this->selectedInvoiceId = $inv->id;
        $this->selectedInvoice = $inv;
        $this->showDetailModal = true;
    }

    public function openMatchWorkbench(int $id): void
    {
        $inv = SupplierInvoice::with(['purchaseOrder.lines', 'lines.purchasable'])->findOrFail($id);
        Gate::authorize('update', $inv);

        $this->selectedInvoiceId = $inv->id;
        $this->selectedInvoice = $inv;

        // Ambil GRN yang tersedia untuk PO faktur ini
        $poId = $inv->purchase_order_id;
        $grns = GoodsReceivedNote::with('lines')
            ->where('purchase_order_id', $poId)
            ->whereIn('status', ['POSTED', 'posted'])
            ->get();

        $this->matchPlanLines = [];

        foreach ($inv->lines as $invLine) {
            // Temukan baris GRN yang cocok dengan PO line ini
            $matchedGrnLine = null;
            foreach ($grns as $grn) {
                foreach ($grn->lines as $gLine) {
                    if ((int) $gLine->purchase_order_line_id === (int) $invLine->purchase_order_line_id) {
                        $matchedGrnLine = $gLine;
                        break 2;
                    }
                }
            }

            $this->matchPlanLines[] = [
                'supplier_invoice_line_id' => $invLine->id,
                'item_name' => $invLine->purchasable?->product_name ?? $invLine->purchasable?->name ?? 'Item #'.$invLine->purchasable_id,
                'invoiced_qty' => (string) $invLine->invoiced_quantity,
                'unit_price' => (string) $invLine->unit_price,
                'goods_received_note_line_id' => $matchedGrnLine?->id ?? '',
                'matched_qty' => (string) $invLine->invoiced_quantity,
            ];
        }

        $this->showMatchModal = true;
    }

    public function executeMatch(SupplierInvoiceMatchingService $service): void
    {
        if (! $this->selectedInvoiceId) {
            return;
        }

        $inv = SupplierInvoice::findOrFail($this->selectedInvoiceId);
        Gate::authorize('update', $inv);

        $plan = [];
        foreach ($this->matchPlanLines as $m) {
            if (! empty($m['goods_received_note_line_id'])) {
                $plan[] = [
                    'supplier_invoice_line_id' => (int) $m['supplier_invoice_line_id'],
                    'goods_received_note_line_id' => (int) $m['goods_received_note_line_id'],
                    'matched_qty' => (string) $m['matched_qty'],
                ];
            }
        }

        if (empty($plan)) {
            session()->flash('error', 'Pilih minimal 1 baris GRN penerimaan barang yang cocok.');

            return;
        }

        try {
            $result = $service->performThreeWayMatching($inv, $plan, auth()->user());
            session()->flash('message', "3-Way Matching selesai dengan status: {$result->matching_status?->label()}.");
            $this->showMatchModal = false;
            $this->viewInvoice($inv->id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal mencocokkan faktur: '.$e->getMessage());
        }
    }

    public function confirmApproveVariance(): void
    {
        $this->varianceNotes = '';
        $this->showVarianceModal = true;
    }

    public function approveVariance(SupplierInvoiceMatchingService $service): void
    {
        if (! $this->selectedInvoiceId) {
            return;
        }

        $inv = SupplierInvoice::findOrFail($this->selectedInvoiceId);
        Gate::authorize('update', $inv);

        $this->validate([
            'varianceNotes' => 'required|string|min:5',
        ], [
            'varianceNotes.required' => 'Alasan persetujuan selisih harga/kuantitas wajib diisi.',
        ]);

        try {
            $service->approveVariance($inv, auth()->user(), $this->varianceNotes);
            $this->showVarianceModal = false;
            session()->flash('message', 'Varians faktur pemasok telah disetujui. Faktur kini siap diposting ke pembayaran.');
            $this->viewInvoice($inv->id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal menyetujui varians: '.$e->getMessage());
        }
    }

    public function approvePayable(int $id, SupplierInvoiceMatchingService $service): void
    {
        $inv = SupplierInvoice::findOrFail($id);
        Gate::authorize('update', $inv);

        try {
            $approved = $service->approvePayable($inv, auth()->user());
            session()->flash('message', "Faktur #{$approved->supplier_invoice_number} berhasil diposting ke Hutang Dagang (AP). Transaksi dialirkan ke Buku Besar Akuntansi.");
            $this->viewInvoice($id);
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal memposting pembayaran: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $query = SupplierInvoice::query()
            ->with(['supplier', 'store', 'purchaseOrder'])
            ->where('tenant_id', $tenantId)
            ->latest('id');

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('supplier_invoice_number', 'like', "%{$this->search}%")
                    ->orWhere('internal_invoice_number', 'like', "%{$this->search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"));
            });
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->supplierFilter) {
            $query->where('supplier_id', $this->supplierFilter);
        }

        $invoices = $query->paginate(15);

        // Ambil PO yang memiliki status penerimaan GRN untuk pembuatan invoice
        $availablePos = PurchaseOrder::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ISSUED', 'PARTIALLY_RECEIVED', 'COMPLETED', 'issued', 'partially_received', 'completed'])
            ->latest('id')
            ->get();

        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('livewire.purchasing.supplier-invoice-manager', [
            'invoices' => $invoices,
            'availablePos' => $availablePos,
            'suppliers' => $suppliers,
        ]);
    }
}
