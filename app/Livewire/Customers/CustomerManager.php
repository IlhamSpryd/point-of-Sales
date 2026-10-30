<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessCustomerStatementExportJob;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\CustomerGroup;
use App\Models\ExportTask;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
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

    public bool $showAddressModal = false;

    public bool $showContactModal = false;

    public bool $showMergeModal = false;

    public bool $showStatementModal = false;

    public string $statementStartDate = '';

    public string $statementEndDate = '';

    public ?array $statementPreview = null;

    public ?int $statementExportTaskId = null;

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

    // Address Form State
    #[Locked]
    public ?int $addressId = null;

    public string $address_type = 'SHIPPING';

    public string $address_label = '';

    public string $recipient_name = '';

    public string $address_phone = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $province = '';

    public string $postal_code = '';

    public string $country_code = 'ID';

    public bool $is_default_billing = false;

    public bool $is_default_shipping = false;

    // Contact Form State
    #[Locked]
    public ?int $contactId = null;

    public string $contact_name = '';

    public string $contact_role_title = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public bool $contact_is_primary = false;

    // Merge State
    #[Locked]
    public ?int $mergeSourceId = null;

    public ?int $mergeTargetId = null;

    public string $mergeReason = '';

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
        $customer = Customer::withoutGlobalScopes()
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
                $customer = Customer::withoutGlobalScopes()
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

                session()->flash('message', "Data pelanggan '{$customer->name}' berhasil diperbarui.");
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

                session()->flash('message', "Pelanggan baru '{$customer->name}' ({$customer->customer_code}) berhasil dibuat.");
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
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        Gate::authorize('delete', $customer);

        try {
            $customerService->deleteCustomer($customer, auth()->user(), 'Dihapus melalui web dashboard');
            session()->flash('message', "Pelanggan '{$customer->name}' berhasil dinonaktifkan.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // --- Sub-Modal: Address Management ---
    public function openAddresses(int $customerId): void
    {
        $this->activeCustomerId = $customerId;
        $this->resetAddressForm();
        $this->showAddressModal = true;
    }

    public function addAddress(): void
    {
        $this->resetAddressForm();
    }

    public function editAddress(int $addressId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $address = CustomerAddress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($addressId);

        $this->addressId = $address->id;
        $this->address_type = (string) $address->address_type;
        $this->address_label = (string) ($address->label ?? '');
        $this->recipient_name = (string) ($address->recipient_name ?? '');
        $this->address_phone = (string) ($address->phone ?? '');
        $this->address_line1 = (string) $address->address_line1;
        $this->address_line2 = (string) ($address->address_line2 ?? '');
        $this->city = (string) ($address->city ?? '');
        $this->province = (string) ($address->province ?? '');
        $this->postal_code = (string) ($address->postal_code ?? '');
        $this->country_code = (string) ($address->country_code ?? 'ID');
        $this->is_default_billing = (bool) $address->is_default_billing;
        $this->is_default_shipping = (bool) $address->is_default_shipping;
    }

    public function saveAddress(CustomerService $customerService): void
    {
        $this->validate([
            'address_line1' => 'required|string',
            'address_type' => 'required|in:BILLING,SHIPPING,BOTH',
        ]);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->activeCustomerId);

        Gate::authorize('update', $customer);

        try {
            $data = [
                'address_type' => $this->address_type,
                'label' => $this->address_label,
                'recipient_name' => $this->recipient_name ?: $customer->name,
                'phone' => $this->address_phone ?: $customer->phone,
                'address_line1' => $this->address_line1,
                'address_line2' => $this->address_line2,
                'city' => $this->city,
                'province' => $this->province,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
                'is_default_billing' => $this->is_default_billing,
                'is_default_shipping' => $this->is_default_shipping,
            ];

            if ($this->addressId) {
                $address = CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($this->addressId);
                $customerService->updateAddress($address, $data);
                session()->flash('address_message', 'Alamat berhasil diperbarui.');
            } else {
                $customerService->addAddress($customer, $data);
                session()->flash('address_message', 'Alamat baru berhasil ditambahkan.');
            }

            $this->resetAddressForm();
        } catch (\Throwable $e) {
            session()->flash('address_error', $e->getMessage());
        }
    }

    public function deleteAddress(int $addressId, CustomerService $customerService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $address = CustomerAddress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($addressId);

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($address->customer_id);

        Gate::authorize('update', $customer);

        try {
            $customerService->deleteAddress($address);
            session()->flash('address_message', 'Alamat berhasil dihapus.');
        } catch (\Throwable $e) {
            session()->flash('address_error', $e->getMessage());
        }
    }

    // --- Sub-Modal: Contact Management (B2B) ---
    public function openContacts(int $customerId): void
    {
        $this->activeCustomerId = $customerId;
        $this->resetContactForm();
        $this->showContactModal = true;
    }

    public function addContact(): void
    {
        $this->resetContactForm();
    }

    public function editContact(int $contactId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $contact = CustomerContact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($contactId);

        $this->contactId = $contact->id;
        $this->contact_name = (string) $contact->name;
        $this->contact_role_title = (string) ($contact->role_title ?? '');
        $this->contact_email = (string) ($contact->email ?? '');
        $this->contact_phone = (string) ($contact->phone ?? '');
        $this->contact_is_primary = (bool) $contact->is_primary;
    }

    public function saveContact(CustomerService $customerService): void
    {
        $this->validate([
            'contact_name' => 'required|string|max:255',
        ]);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->activeCustomerId);

        Gate::authorize('update', $customer);

        try {
            $data = [
                'name' => $this->contact_name,
                'role_title' => $this->contact_role_title,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
                'is_primary' => $this->contact_is_primary,
            ];

            if ($this->contactId) {
                $contact = CustomerContact::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($this->contactId);
                $customerService->updateContact($contact, $data);
                session()->flash('contact_message', 'Kontak narahubung berhasil diperbarui.');
            } else {
                $customerService->addContact($customer, $data);
                session()->flash('contact_message', 'Kontak narahubung baru berhasil ditambahkan.');
            }

            $this->resetContactForm();
        } catch (\Throwable $e) {
            session()->flash('contact_error', $e->getMessage());
        }
    }

    public function deleteContact(int $contactId, CustomerService $customerService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $contact = CustomerContact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($contactId);

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($contact->customer_id);

        Gate::authorize('update', $customer);

        try {
            $customerService->deleteContact($contact);
            session()->flash('contact_message', 'Kontak narahubung berhasil dihapus.');
        } catch (\Throwable $e) {
            session()->flash('contact_error', $e->getMessage());
        }
    }

    // --- Sub-Modal: Customer Merge ---
    public function openMerge(int $sourceId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $source = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($sourceId);

        Gate::authorize('merge', $source);

        $this->mergeSourceId = $source->id;
        $this->mergeTargetId = null;
        $this->mergeReason = '';
        $this->showMergeModal = true;
    }

    public function executeMerge(CustomerService $customerService): void
    {
        $this->validate([
            'mergeTargetId' => 'required|integer',
            'mergeReason' => 'required|string|min:5',
        ]);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $source = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->mergeSourceId);

        $target = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->mergeTargetId);

        Gate::authorize('merge', $source);

        try {
            $customerService->mergeCustomers($source, $target, auth()->user(), $this->mergeReason);
            session()->flash('message', "Pelanggan '{$source->customer_code}' berhasil digabungkan ke '{$target->customer_code}'.");
            $this->showMergeModal = false;
        } catch (\Throwable $e) {
            session()->flash('merge_error', $e->getMessage());
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

    private function resetAddressForm(): void
    {
        $this->addressId = null;
        $this->address_type = 'SHIPPING';
        $this->address_label = '';
        $this->recipient_name = '';
        $this->address_phone = '';
        $this->address_line1 = '';
        $this->address_line2 = '';
        $this->city = '';
        $this->province = '';
        $this->postal_code = '';
        $this->country_code = 'ID';
        $this->is_default_billing = false;
        $this->is_default_shipping = false;
        $this->resetErrorBag();
    }

    private function resetContactForm(): void
    {
        $this->contactId = null;
        $this->contact_name = '';
        $this->contact_role_title = '';
        $this->contact_email = '';
        $this->contact_phone = '';
        $this->contact_is_primary = false;
        $this->resetErrorBag();
    }

    public function openStatement(int $customerId): void
    {
        $this->activeCustomerId = $customerId;
        $this->statementStartDate = now()->startOfMonth()->toDateString();
        $this->statementEndDate = now()->toDateString();
        $this->loadStatementPreview();
        $this->showStatementModal = true;
    }

    public function loadStatementPreview(): void
    {
        if (! $this->activeCustomerId) {
            return;
        }

        try {
            $arService = app(AccountsReceivableService::class);
            $start = Carbon::parse($this->statementStartDate);
            $end = Carbon::parse($this->statementEndDate);
            $this->statementPreview = $arService->generateCustomerStatement($this->activeCustomerId, $start, $end);
        } catch (\Throwable $e) {
            $this->statementPreview = null;
            session()->flash('error', 'Gagal memuat statement: '.$e->getMessage());
        }
    }

    public function exportStatement(): void
    {
        if (! $this->activeCustomerId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $task = ExportTask::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'requested_by' => auth()->id(),
            'type' => 'customer_statement',
            'parameters' => [
                'customer_id' => $this->activeCustomerId,
                'start_date' => $this->statementStartDate,
                'end_date' => $this->statementEndDate,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        ProcessCustomerStatementExportJob::dispatch($task, $tenantId, $storeId);
        $this->statementExportTaskId = $task->id;
        session()->flash('message', 'Ekspor rekening koran sedang diproses di latar belakang (Task #'.$task->id.').');
    }

    public function closeStatement(): void
    {
        $this->showStatementModal = false;
        $this->statementPreview = null;
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Customer::withoutGlobalScopes()
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

        $customerGroups = CustomerGroup::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $activeAddresses = collect();
        $activeContacts = collect();
        $activeCustomer = null;

        if ($this->activeCustomerId) {
            $activeCustomer = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($this->activeCustomerId);

            if ($this->showAddressModal) {
                $activeAddresses = CustomerAddress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $this->activeCustomerId)
                    ->orderByDesc('is_default_shipping')
                    ->get();
            }

            if ($this->showContactModal) {
                $activeContacts = CustomerContact::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $this->activeCustomerId)
                    ->orderByDesc('is_primary')
                    ->orderBy('name')
                    ->get();
            }
        }

        $mergeTargetCandidates = collect();
        if ($this->showMergeModal && $this->mergeSourceId) {
            $mergeTargetCandidates = Customer::withoutGlobalScopes()
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
