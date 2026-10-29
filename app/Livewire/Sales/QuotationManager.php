<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Modules\Sales\Services\QuotationService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class QuotationManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showCreateModal = false;

    public bool $showDetailModal = false;

    public bool $showRejectModal = false;

    #[Locked]
    public ?int $selectedQuotationId = null;

    public ?Quotation $selectedQuotation = null;

    // Form inputs
    public ?int $customer_id = null;

    public string $issue_date = '';

    public string $valid_until = '';

    public int $payment_terms_days = 0;

    public string $notes = '';

    public string $terms_and_conditions = '';

    public string $rejectReason = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public function mount(): void
    {
        $this->issue_date = Carbon::today()->toDateString();
        $this->valid_until = Carbon::today()->addDays(30)->toDateString();
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'product_id' => null,
            'quantity' => '1.0000',
            'unit_price' => '',
            'discount_amount' => '0.00',
            'tax_rate' => '0.00',
        ];
    }

    public function removeLine(int $index): void
    {
        if (isset($this->lines[$index])) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }

        if (count($this->lines) === 0) {
            $this->addLine();
        }
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Quotation::class);

        $this->resetValidation();
        $this->customer_id = null;
        $this->issue_date = Carbon::today()->toDateString();
        $this->valid_until = Carbon::today()->addDays(30)->toDateString();
        $this->payment_terms_days = 0;
        $this->notes = '';
        $this->terms_and_conditions = '';
        $this->lines = [];
        $this->addLine();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    public function createQuotation(QuotationService $quotationService): void
    {
        Gate::authorize('create', Quotation::class);

        $this->validate([
            'customer_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ], [
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'valid_until.after_or_equal' => 'Masa berlaku harus sama atau setelah tanggal penawaran.',
            'lines.*.product_id.required' => 'Produk pada baris penawaran wajib dipilih.',
        ]);

        $actor = Auth::user();

        $payload = [
            'customer_id' => $this->customer_id,
            'issue_date' => $this->issue_date,
            'valid_until' => $this->valid_until,
            'payment_terms_days' => $this->payment_terms_days,
            'notes' => $this->notes ?: null,
            'terms_and_conditions' => $this->terms_and_conditions ?: null,
            'items' => array_map(function ($line) {
                return [
                    'product_id' => (int) $line['product_id'],
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => ! empty($line['unit_price']) ? (string) $line['unit_price'] : null,
                    'discount_amount' => ! empty($line['discount_amount']) ? (string) $line['discount_amount'] : '0.00',
                    'tax_rate' => ! empty($line['tax_rate']) ? (string) $line['tax_rate'] : '0.00',
                ];
            }, $this->lines),
        ];

        $quotation = $quotationService->createDraft($payload, $actor);

        $this->showCreateModal = false;
        session()->flash('message', "Quotation {$quotation->quotation_number} (v{$quotation->version_number}) berhasil dibuat.");
    }

    public function viewQuotation(int $id): void
    {
        $quotation = Quotation::with(['customer', 'store', 'lines.product', 'lines.variant', 'createdBy', 'approvedBy', 'convertedSalesOrder'])
            ->findOrFail($id);

        Gate::authorize('view', $quotation);

        $this->selectedQuotationId = $quotation->id;
        $this->selectedQuotation = $quotation;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedQuotation = null;
        $this->selectedQuotationId = null;
    }

    public function submitForApprovalOrSend(QuotationService $quotationService, int $id): void
    {
        $quotation = Quotation::findOrFail($id);
        Gate::authorize('update', $quotation);

        $actor = Auth::user();
        $updated = $quotationService->submitForApprovalOrSend($quotation, $actor);

        session()->flash('message', "Quotation {$updated->quotation_number} berhasil dialihkan ke status {$updated->status->value}.");
        $this->viewQuotation($id);
    }

    public function approve(QuotationService $quotationService, int $id): void
    {
        $quotation = Quotation::findOrFail($id);
        Gate::authorize('approve', $quotation);

        $actor = Auth::user();
        $approved = $quotationService->approve($quotation, $actor);

        session()->flash('message', "Quotation {$approved->quotation_number} berhasil disetujui (APPROVED).");
        $this->viewQuotation($id);
    }

    public function openRejectModal(int $id): void
    {
        $this->selectedQuotationId = $id;
        $this->rejectReason = '';
        $this->showRejectModal = true;
    }

    public function reject(QuotationService $quotationService): void
    {
        if (! $this->selectedQuotationId) {
            return;
        }

        $quotation = Quotation::findOrFail($this->selectedQuotationId);
        Gate::authorize('update', $quotation);

        $this->validate([
            'rejectReason' => ['required', 'string', 'min:3'],
        ], [
            'rejectReason.required' => 'Alasan penolakan wajib diisi.',
        ]);

        $actor = Auth::user();
        $rejected = $quotationService->reject($quotation, $actor, $this->rejectReason);

        $this->showRejectModal = false;
        session()->flash('message', "Quotation {$rejected->quotation_number} telah ditolak.");

        if ($this->showDetailModal) {
            $this->viewQuotation($this->selectedQuotationId);
        }
    }

    public function accept(QuotationService $quotationService, int $id): void
    {
        $quotation = Quotation::findOrFail($id);
        Gate::authorize('update', $quotation);

        $actor = Auth::user();
        $accepted = $quotationService->accept($quotation, $actor);

        session()->flash('message', "Quotation {$accepted->quotation_number} diterima oleh pelanggan (ACCEPTED).");
        $this->viewQuotation($id);
    }

    public function convertToSalesOrder(QuotationService $quotationService, int $id): void
    {
        $quotation = Quotation::findOrFail($id);
        Gate::authorize('convert', $quotation);

        $actor = Auth::user();

        try {
            $salesOrder = $quotationService->convertToSalesOrder($quotation, $actor);
            session()->flash('message', "Quotation {$quotation->quotation_number} BERHASIL dikonversi menjadi Sales Order: {$salesOrder->so_number}!");
            $this->viewQuotation($id);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Quotation::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Quotation::with(['customer', 'store', 'lines.product'])
            ->where('tenant_id', $tenantId);

        if ($this->search !== '') {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('quotation_number', 'like', "%{$s}%")
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

        $quotations = $query->orderByDesc('id')->paginate(15);

        $customers = Customer::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'customer_code', 'customer_type', 'company_name']);

        $products = Product::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'product_code']);

        return view('livewire.sales.quotation-manager', [
            'quotations' => $quotations,
            'customers' => $customers,
            'products' => $products,
        ]);
    }
}
