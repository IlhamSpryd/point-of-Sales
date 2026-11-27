<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Customers\Concerns\ManagesCustomerAddressesAndContacts;
use App\Livewire\Customers\Concerns\ManagesCustomerStatementsAndMerge;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerContact;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Services\CustomerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerManager extends Component
{
    use ManagesCustomerAddressesAndContacts;
    use ManagesCustomerStatementsAndMerge;
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $typeFilter = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    #[Url(history: true)]
    public string $groupFilter = '';

    // Modal state
    public bool $showModal = false;

    public bool $isEditing = false;

    // Customer Form State
    #[Locked]
    public ?int $customerId = null;

    public string $customer_code = '';

    public string $name = '';

    public string $customer_type = 'INDIVIDUAL';

    public ?int $customer_group_id = null;

    public string $company_name = '';

    public string $tax_id = '';

    public string $business_registration_no = '';

    public string $phone = '';

    public string $email = '';

    public string $date_of_birth = '';

    public bool $is_active = true;

    public bool $is_loyalty_eligible = true;

    // Active Customer Context for Sub-modals
    #[Locked]
    public ?int $activeCustomerId = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'customer_type' => 'required|in:INDIVIDUAL,COMPANY',
            'customer_group_id' => 'nullable|integer',
            'company_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:64',
            'business_registration_no' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'date_of_birth' => 'nullable|date',
            'is_active' => 'boolean',
            'is_loyalty_eligible' => 'boolean',
        ];
    }

    public function boot(): void
    {
        $this->bootRequiresTenantContext();
        $this->authorize('viewAny', Customer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingGroupFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        Gate::authorize('create', Customer::class);

        $this->resetCustomerForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        Gate::authorize('update', $customer);

        $this->customerId = $customer->id;
        $this->customer_code = (string) $customer->customer_code;
        $this->name = (string) $customer->name;
        $this->customer_type = (string) ($customer->customer_type ?? 'INDIVIDUAL');
        $this->customer_group_id = $customer->customer_group_id ? (int) $customer->customer_group_id : null;
        $this->company_name = (string) ($customer->company_name ?? '');
        $this->tax_id = (string) ($customer->tax_id ?? '');
        $this->business_registration_no = (string) ($customer->business_registration_no ?? '');
        $this->phone = (string) ($customer->phone ?? '');
        $this->email = (string) ($customer->email ?? '');
        $this->date_of_birth = $customer->date_of_birth ? $customer->date_of_birth->format('Y-m-d') : '';
        $this->is_active = (bool) $customer->is_active;
        $this->is_loyalty_eligible = (bool) $customer->is_loyalty_eligible;

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(CustomerService $customerService): void
    {
        $this->validate();

        $actor = auth()->user();

        try {
            if ($this->isEditing && $this->customerId) {
                $customer = Customer::query()
                    ->where('tenant_id', app(TenantContext::class)->requireTenantId())
                    ->findOrFail($this->customerId);

                Gate::authorize('update', $customer);

                $customerService->updateCustomer($customer, [
                    'name' => $this->name,
                    'customer_type' => $this->customer_type,
                    'customer_group_id' => $this->customer_group_id,
                    'company_name' => $this->company_name,
                    'tax_id' => $this->tax_id,
                    'business_registration_no' => $this->business_registration_no,
                    'phone' => $this->phone,
                    'email' => $this->email,
                    'date_of_birth' => $this->date_of_birth ?: null,
                    'is_active' => $this->is_active,
                    'is_loyalty_eligible' => $this->is_loyalty_eligible,
                ], $actor);

                session()->flash('message', "Customer '{$customer->name}' updated successfully.");
            } else {
                Gate::authorize('create', Customer::class);

                $customer = $customerService->createCustomer([
                    'name' => $this->name,
                    'customer_type' => $this->customer_type,
                    'customer_group_id' => $this->customer_group_id,
                    'company_name' => $this->company_name,
                    'tax_id' => $this->tax_id,
                    'business_registration_no' => $this->business_registration_no,
                    'phone' => $this->phone,
                    'email' => $this->email,
                    'date_of_birth' => $this->date_of_birth ?: null,
                    'is_active' => $this->is_active,
                    'is_loyalty_eligible' => $this->is_loyalty_eligible,
                ], $actor);

                session()->flash('message', "New customer '{$customer->name}' ({$customer->customer_code}) created successfully.");
            }

            $this->showModal = false;
            $this->resetCustomerForm();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function delete(int $id, CustomerService $customerService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        Gate::authorize('delete', $customer);

        try {
            $customerService->deleteCustomer($customer, auth()->user(), 'Deactivated via web dashboard');
            session()->flash('message', "Customer '{$customer->name}' deactivated successfully.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function resetCustomerForm(): void
    {
        $this->customerId = null;
        $this->customer_code = '';
        $this->name = '';
        $this->customer_type = 'INDIVIDUAL';
        $this->customer_group_id = null;
        $this->company_name = '';
        $this->tax_id = '';
        $this->business_registration_no = '';
        $this->phone = '';
        $this->email = '';
        $this->date_of_birth = '';
        $this->is_active = true;
        $this->is_loyalty_eligible = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('merged_into_customer_id')
            ->with(['group', 'defaultBillingAddress', 'defaultShippingAddress', 'addresses']);

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('customer_code', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if ($this->typeFilter !== '') {
            $query->where('customer_type', $this->typeFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($this->groupFilter !== '') {
            $query->where('customer_group_id', (int) $this->groupFilter);
        }

        $customers = $query->orderBy('name')->paginate(15);

        $customerGroups = CustomerGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $activeAddresses = collect();
        $activeContacts = collect();
        $activeCustomer = null;

        if ($this->activeCustomerId) {
            $activeCustomer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->find($this->activeCustomerId);

            if ($this->showAddressModal) {
                $activeAddresses = CustomerAddress::query()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $this->activeCustomerId)
                    ->orderByDesc('is_default_shipping')
                    ->get();
            }

            if ($this->showContactModal) {
                $activeContacts = CustomerContact::query()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $this->activeCustomerId)
                    ->orderByDesc('is_primary')
                    ->orderBy('name')
                    ->get();
            }
        }

        $mergeTargetCandidates = collect();
        if ($this->showMergeModal && $this->mergeSourceId) {
            $mergeTargetCandidates = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('merged_into_customer_id')
                ->where('id', '!=', $this->mergeSourceId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(50)
                ->get();
        }

        return view('livewire.customers.customer-manager', [
            'customers' => $customers,
            'customerGroups' => $customerGroups,
            'activeCustomer' => $activeCustomer,
            'activeAddresses' => $activeAddresses,
            'activeContacts' => $activeContacts,
            'mergeTargetCandidates' => $mergeTargetCandidates,
        ]);
    }
}
