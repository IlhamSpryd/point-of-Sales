<?php

declare(strict_types=1);

namespace App\Livewire\Customers\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerContact;
use App\Modules\Customers\Services\CustomerService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

trait ManagesCustomerAddressesAndContacts
{
    public bool $showAddressModal = false;

    public bool $showContactModal = false;

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
                session()->flash('address_message', 'Address updated successfully.');
            } else {
                $customerService->addAddress($customer, $data);
                session()->flash('address_message', 'New address added successfully.');
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
            session()->flash('address_message', 'Address deleted successfully.');
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
                session()->flash('contact_message', 'Contact person updated successfully.');
            } else {
                $customerService->addContact($customer, $data);
                session()->flash('contact_message', 'New contact person added successfully.');
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
            session()->flash('contact_message', 'Contact person deleted successfully.');
        } catch (\Throwable $e) {
            session()->flash('contact_error', $e->getMessage());
        }
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
}
