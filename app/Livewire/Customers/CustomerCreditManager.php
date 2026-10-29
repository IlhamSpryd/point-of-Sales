<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerCreditManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showEditModal = false;

    public bool $showStatusModal = false;

    public bool $showLogsModal = false;

    #[Locked]
    public ?int $selectedCustomerId = null;

    public ?Customer $selectedCustomer = null;

    public ?CustomerCreditAccount $selectedAccount = null;

    // Form Edit Limit & Terms
    public string $creditLimit = '0';

    public int $paymentTermsDays = 0;

    public int $gracePeriodDays = 0;

    public string $limitChangeReason = '';

    // Form Status
    public string $targetStatus = 'ACTIVE';

    public string $statusChangeReason = '';

    public function openEditModal(int $customerId, ?CustomerCreditService $service = null): void
    {
        $service = $service ?? app(CustomerCreditService::class);
        $customer = Customer::with('creditAccount')->findOrFail($customerId);
        Gate::authorize('manageCreditLimit', $customer);

        $this->selectedCustomerId = $customer->id;
        $this->selectedCustomer = $customer;

        $account = $service->getOrCreateCreditAccount($customer);
        $this->selectedAccount = $account;

        $this->creditLimit = (string) $account->credit_limit;
        $this->paymentTermsDays = (int) $account->payment_terms_days;
        $this->gracePeriodDays = (int) $account->grace_period_days;
        $this->limitChangeReason = '';

        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->selectedCustomerId = null;
        $this->selectedCustomer = null;
        $this->selectedAccount = null;
    }

    public function saveLimitAndTerms(?CustomerCreditService $service = null): void
    {
        $service = $service ?? app(CustomerCreditService::class);
        if (! $this->selectedCustomerId) {
            return;
        }

        $customer = Customer::findOrFail($this->selectedCustomerId);
        Gate::authorize('manageCreditLimit', $customer);

        $this->validate([
            'creditLimit' => ['required', 'numeric', 'min:0'],
            'paymentTermsDays' => ['required', 'integer', 'min:0', 'max:365'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:90'],
            'limitChangeReason' => ['required', 'string', 'min:3'],
        ], [
            'creditLimit.required' => 'Plafon batas kredit wajib diisi.',
            'limitChangeReason.required' => 'Alasan perubahan batas kredit wajib diisi.',
        ]);

        $actor = Auth::user();

        try {
            $service->updateCreditLimit($customer->id, (string) $this->creditLimit, $actor, $this->limitChangeReason);
            $service->updateCreditTerms($customer->id, $this->paymentTermsDays, $this->gracePeriodDays, $actor, $this->limitChangeReason);

            session()->flash('message', "Batas kredit dan termin pembayaran untuk {$customer->name} berhasil diperbarui.");
            $this->closeEditModal();
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openStatusModal(int $customerId, ?CustomerCreditService $service = null): void
    {
        $service = $service ?? app(CustomerCreditService::class);
        $customer = Customer::with('creditAccount')->findOrFail($customerId);
        Gate::authorize('manageCreditStatus', $customer);

        $this->selectedCustomerId = $customer->id;
        $this->selectedCustomer = $customer;

        $account = $service->getOrCreateCreditAccount($customer);
        $this->selectedAccount = $account;

        $this->targetStatus = $account->credit_status->value;
        $this->statusChangeReason = '';

        $this->showStatusModal = true;
    }

    public function closeStatusModal(): void
    {
        $this->showStatusModal = false;
        $this->selectedCustomerId = null;
        $this->selectedCustomer = null;
        $this->selectedAccount = null;
    }

    public function saveStatus(?CustomerCreditService $service = null): void
    {
        $service = $service ?? app(CustomerCreditService::class);
        if (! $this->selectedCustomerId) {
            return;
        }

        $customer = Customer::findOrFail($this->selectedCustomerId);
        Gate::authorize('manageCreditStatus', $customer);

        $this->validate([
            'targetStatus' => ['required', 'in:ACTIVE,ON_HOLD,SUSPENDED'],
            'statusChangeReason' => ['required', 'string', 'min:3'],
        ], [
            'statusChangeReason.required' => 'Alasan perubahan status kredit wajib diisi.',
        ]);

        $actor = Auth::user();

        try {
            $statusEnum = CreditStatusEnum::from($this->targetStatus);
            $service->setCreditStatus($customer->id, $statusEnum, $actor, $this->statusChangeReason);

            session()->flash('message', "Status kredit untuk {$customer->name} dialihkan ke {$statusEnum->value}.");
            $this->closeStatusModal();
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openLogsModal(int $customerId): void
    {
        $customer = Customer::with(['creditAccount', 'creditLogs.actor'])->findOrFail($customerId);
        Gate::authorize('viewCredit', $customer);

        $this->selectedCustomerId = $customer->id;
        $this->selectedCustomer = $customer;
        $this->showLogsModal = true;
    }

    public function closeLogsModal(): void
    {
        $this->showLogsModal = false;
        $this->selectedCustomerId = null;
        $this->selectedCustomer = null;
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Customer::with('creditAccount')
            ->where('tenant_id', $tenantId);

        if ($this->search !== '') {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('customer_code', 'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%");
            });
        }

        if ($this->statusFilter !== '') {
            $query->whereHas('creditAccount', function ($q) {
                $q->where('credit_status', $this->statusFilter);
            });
        }

        $customers = $query->orderBy('name')->paginate(15);

        // Ringkasan metrik tenant
        $summary = CustomerCreditAccount::where('tenant_id', $tenantId)
            ->selectRaw('
                SUM(credit_limit) as total_limit,
                SUM(reserved_exposure) as total_reserved,
                SUM(invoiced_exposure) as total_invoiced
            ')
            ->first();

        return view('livewire.customers.customer-credit-manager', [
            'customers' => $customers,
            'summary' => $summary,
        ]);
    }
}
