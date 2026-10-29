<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Models\OrderReturn;
use App\Modules\Sales\Enums\CreditNoteStatusEnum;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Services\Context\TenantContext;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ReturnCreditNoteManager extends Component
{
    use WithPagination;

    public string $activeTab = 'credit_notes'; // 'returns' | 'credit_notes'

    public string $search = '';

    public string $statusFilter = '';

    // Apply modal state
    public bool $showApplyModal = false;

    public ?int $selectedCreditNoteId = null;

    public ?int $targetInvoiceId = null;

    public string $applyAmount = '';

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    protected $queryString = [
        'activeTab' => ['except' => 'credit_notes'],
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
        $this->reset(['errorMessage', 'successMessage']);
    }

    public function openApplyModal(int $creditNoteId): void
    {
        $this->reset(['errorMessage', 'successMessage']);
        $this->selectedCreditNoteId = $creditNoteId;

        $cn = SalesCreditNote::findOrFail($creditNoteId);
        $this->applyAmount = (string) $cn->remaining_amount;
        $this->targetInvoiceId = null;
        $this->showApplyModal = true;
    }

    public function closeApplyModal(): void
    {
        $this->showApplyModal = false;
        $this->reset(['selectedCreditNoteId', 'targetInvoiceId', 'applyAmount']);
    }

    public function applyCreditNote(ReturnRefundService $service): void
    {
        $this->reset(['errorMessage', 'successMessage']);

        $this->validate([
            'selectedCreditNoteId' => 'required|integer',
            'targetInvoiceId' => 'required|integer',
            'applyAmount' => 'required|numeric|min:0.01',
        ]);

        try {
            $cn = SalesCreditNote::findOrFail($this->selectedCreditNoteId);
            $invoice = CustomerInvoice::findOrFail($this->targetInvoiceId);

            $service->applyCreditNoteToInvoice(
                creditNote: $cn,
                invoice: $invoice,
                amount: (string) $this->applyAmount,
                actorUserId: (int) Auth::id()
            );

            $this->successMessage = "Nota Kredit #{$cn->credit_note_number} berhasil diaplikasikan ke Faktur #{$invoice->invoice_number}.";
            $this->closeApplyModal();
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function convertToStoreCredit(int $creditNoteId, ReturnRefundService $service): void
    {
        $this->reset(['errorMessage', 'successMessage']);

        try {
            $cn = SalesCreditNote::findOrFail($creditNoteId);
            $service->convertCreditNoteToStoreCredit($cn, (int) Auth::id());
            $this->successMessage = "Nota Kredit #{$cn->credit_note_number} berhasil dikonversi ke Store Credit.";
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $creditNotes = null;
        $returns = null;
        $eligibleInvoices = collect();

        if ($this->activeTab === 'credit_notes') {
            $query = SalesCreditNote::query()
                ->where('tenant_id', $tenantId)
                ->with(['customer', 'store', 'orderReturn', 'originCustomerInvoice']);

            if ($this->search !== '') {
                $query->where(function ($q) {
                    $q->where('credit_note_number', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"));
                });
            }

            if ($this->statusFilter !== '') {
                $query->where('status', $this->statusFilter);
            }

            $creditNotes = $query->latest()->paginate(15);
        } else {
            $query = OrderReturn::query()
                ->where('tenant_id', $tenantId)
                ->with(['customer', 'order', 'store']);

            if ($this->search !== '') {
                $query->where(function ($q) {
                    $q->where('return_number', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"));
                });
            }

            if ($this->statusFilter !== '') {
                $query->where('status', $this->statusFilter);
            }

            $returns = $query->latest()->paginate(15);
        }

        if ($this->showApplyModal && $this->selectedCreditNoteId) {
            $cn = SalesCreditNote::find($this->selectedCreditNoteId);
            if ($cn) {
                $eligibleInvoices = CustomerInvoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $cn->customer_id)
                    ->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])
                    ->where('balance_due', '>', 0)
                    ->orderBy('due_date', 'asc')
                    ->get();
            }
        }

        return view('livewire.sales.return-credit-note-manager', [
            'creditNotes' => $creditNotes,
            'returns' => $returns,
            'eligibleInvoices' => $eligibleInvoices,
            'statuses' => CreditNoteStatusEnum::cases(),
        ])->layout('layouts.app', ['title' => 'Retur & Nota Kredit Penjualan']);
    }
}
