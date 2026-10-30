<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class AccountsReceivableManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $activeTab = 'invoices'; // invoices, payment, aging, statement

    // Filter Invoices
    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    // Modal Detail
    public bool $showDetailModal = false;

    #[Locked]
    public ?int $selectedInvoiceId = null;

    #[Computed]
    public function selectedInvoice(): ?CustomerInvoice
    {
        if (! $this->selectedInvoiceId) {
            return null;
        }

        return CustomerInvoice::with(['customer', 'store', 'lines.product', 'lines.variant', 'postedBy', 'voidedBy', 'allocations.allocatedBy', 'ledgerEntries'])
            ->find($this->selectedInvoiceId);
    }

    // Modal Payment Allocation
    public bool $showPaymentModal = false;

    public ?int $paymentCustomerId = null;

    public string $paymentAmount = '';

    public string $paymentMethod = 'bank_transfer';

    public string $paymentReference = '';

    public string $paymentNotes = '';

    /**
     * @var array<int, string>
     */
    public array $allocatedAmounts = [];

    // Modal Void
    public bool $showVoidModal = false;

    public string $voidReason = '';

    // Tab Statement
    public ?int $statementCustomerId = null;

    public string $statementStartDate = '';

    public string $statementEndDate = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $statementData = null;

    public function mount(): void
    {
        $this->statementStartDate = Carbon::today()->startOfMonth()->toDateString();
        $this->statementEndDate = Carbon::today()->toDateString();
    }

    public function viewInvoice(int $id): void
    {
        $invoice = CustomerInvoice::findOrFail($id);

        Gate::authorize('view', $invoice);

        $this->selectedInvoiceId = $invoice->id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedInvoiceId = null;
    }

    public function openPaymentModal(?int $customerId = null): void
    {
        $this->paymentCustomerId = $customerId;
        $this->paymentAmount = '';
        $this->paymentReference = '';
        $this->paymentNotes = '';
        $this->allocatedAmounts = [];

        if ($customerId) {
            $this->loadCustomerOpenInvoices($customerId);
        }

        $this->showPaymentModal = true;
    }

    public function updatedPaymentCustomerId(int $customerId): void
    {
        $this->loadCustomerOpenInvoices($customerId);
    }

    protected function loadCustomerOpenInvoices(int $customerId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $openInvoices = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID])
            ->where('balance_due', '>', 0)
            ->orderBy('due_date', 'asc')
            ->get();

        $this->allocatedAmounts = [];
        foreach ($openInvoices as $inv) {
            $this->allocatedAmounts[$inv->id] = '';
        }
    }

    public function allocateFull(int $invoiceId, string $balanceDue): void
    {
        $this->allocatedAmounts[$invoiceId] = $balanceDue;

        // Auto sum total payment
        $sum = '0.00';
        foreach ($this->allocatedAmounts as $amt) {
            if (is_numeric($amt) && (float) $amt > 0) {
                $sum = bcadd($sum, (string) $amt, 2);
            }
        }
        $this->paymentAmount = $sum;
    }

    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->paymentCustomerId = null;
        $this->paymentAmount = '';
        $this->paymentReference = '';
        $this->paymentNotes = '';
        $this->allocatedAmounts = [];
    }

    public function submitPayment(AccountsReceivableService $arService): void
    {
        $this->validate([
            'paymentCustomerId' => ['required', 'integer'],
            'paymentAmount' => ['required', 'numeric', 'min:1'],
            'paymentReference' => ['required', 'string', 'min:3'],
        ], [
            'paymentCustomerId.required' => 'Pelanggan wajib dipilih.',
            'paymentAmount.required' => 'Jumlah pembayaran yang diterima wajib diisi.',
            'paymentReference.required' => 'Referensi/nomor bukti pembayaran wajib diisi.',
        ]);

        $actor = Auth::user();

        $allocations = [];
        foreach ($this->allocatedAmounts as $invId => $amt) {
            if (is_numeric($amt) && bccomp((string) $amt, '0.00', 2) > 0) {
                $allocations[] = [
                    'invoice_id' => (int) $invId,
                    'amount' => (string) $amt,
                ];
            }
        }

        if (count($allocations) === 0) {
            session()->flash('error', 'Minimal satu faktur harus dialokasikan nilai pembayarannya.');

            return;
        }

        try {
            $res = $arService->recordAndAllocatePayment(
                customerId: (int) $this->paymentCustomerId,
                paymentData: [
                    'amount' => $this->paymentAmount,
                    'payment_method' => $this->paymentMethod,
                    'payment_reference' => $this->paymentReference,
                    'notes' => $this->paymentNotes,
                ],
                invoiceAllocations: $allocations,
                actor: $actor
            );

            session()->flash('message', 'Pembayaran berhasil dicatat. Total dialokasikan: Rp '.number_format((float) $res['total_allocated'], 2, ',', '.').(bccomp($res['unapplied_credit'], '0.00', 2) > 0 ? ' (Sisa kredit: Rp '.number_format((float) $res['unapplied_credit'], 2, ',', '.').')' : ''));
            $this->closePaymentModal();
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openVoidModal(int $id): void
    {
        $invoice = CustomerInvoice::findOrFail($id);
        Gate::authorize('void', $invoice);

        $this->selectedInvoiceId = $invoice->id;
        $this->voidReason = '';
        $this->showVoidModal = true;
    }

    public function closeVoidModal(): void
    {
        $this->showVoidModal = false;
        $this->selectedInvoiceId = null;
        $this->voidReason = '';
    }

    public function submitVoid(AccountsReceivableService $arService): void
    {
        if (! $this->selectedInvoiceId) {
            return;
        }

        $invoice = CustomerInvoice::findOrFail($this->selectedInvoiceId);
        Gate::authorize('void', $invoice);

        $this->validate([
            'voidReason' => ['required', 'string', 'min:5'],
        ], [
            'voidReason.required' => 'Alasan pembatalan faktur wajib diisi minimal 5 karakter.',
        ]);

        $actor = Auth::user();

        try {
            $arService->voidInvoice($invoice, $actor, $this->voidReason);
            session()->flash('message', "Faktur {$invoice->invoice_number} berhasil dibatalkan (VOID).");
            $this->closeVoidModal();
            if ($this->showDetailModal) {
                $this->closeDetailModal();
            }
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function generateStatement(AccountsReceivableService $arService): void
    {
        $this->validate([
            'statementCustomerId' => ['required', 'integer'],
            'statementStartDate' => ['required', 'date'],
            'statementEndDate' => ['required', 'date', 'after_or_equal:statementStartDate'],
        ], [
            'statementCustomerId.required' => 'Pelanggan wajib dipilih untuk rekening koran.',
        ]);

        $start = Carbon::parse($this->statementStartDate);
        $end = Carbon::parse($this->statementEndDate);

        $this->statementData = $arService->generateCustomerStatement(
            (int) $this->statementCustomerId,
            $start,
            $end
        );
    }

    public function render(AccountsReceivableService $arService): View
    {
        Gate::authorize('viewAny', CustomerInvoice::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = CustomerInvoice::with(['customer', 'store', 'lines'])
            ->where('tenant_id', $tenantId);

        if ($this->search !== '') {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhere('customer_po_number', 'like', "%{$s}%")
                    ->orWhereHas('customer', function ($cq) use ($s) {
                        $cq->where('name', 'like', "%{$s}%")
                            ->orWhere('company_name', 'like', "%{$s}%")
                            ->orWhere('customer_code', 'like', "%{$s}%");
                    });
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $invoices = $query->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15);

        $customers = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'customer_code', 'company_name']);

        $openCustomerInvoices = [];
        if ($this->paymentCustomerId) {
            $openCustomerInvoices = CustomerInvoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $this->paymentCustomerId)
                ->whereIn('status', [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID])
                ->where('balance_due', '>', 0)
                ->orderBy('due_date', 'asc')
                ->get();
        }

        $agingBuckets = $arService->getAgingReport($tenantId);

        return view('livewire.sales.accounts-receivable-manager', [
            'invoices' => $invoices,
            'customers' => $customers,
            'openCustomerInvoices' => $openCustomerInvoices,
            'agingBuckets' => $agingBuckets,
            'selectedInvoice' => $this->selectedInvoice,
        ]);
    }
}
