<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Customer Directory (CRM)"
        description="Manage individual (B2C) and corporate (B2B) customers, shipping addresses, and contact persons.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('customers.index') }}">Customers</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Customer Directory</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.groups.index') }}" class="gap-2">
                <span class="material-symbols-rounded text-base">folder_shared</span>
                Customer Groups
            </x-ui.button>
            @can('create', \App\Models\Customer::class)
            <x-ui.button variant="primary" wire:click="create" wire:loading.attr="disabled" class="gap-2">
                <span class="material-symbols-rounded text-base">person_add</span>
                Add Customer
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="An Error Occurred" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name, code, company, phone, email..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="typeFilter" class="w-auto text-sm">
                <option value="">All Types</option>
                <option value="INDIVIDUAL">Individual (B2C)</option>
                <option value="COMPANY">Company (B2B)</option>
            </x-ui.select>
            <x-ui.select wire:model.live="groupFilter" class="w-auto text-sm">
                <option value="">All Groups</option>
                @foreach($customerGroups as $grp)
                    <option value="{{ $grp->id }}">{{ $grp->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Code & Name</th>
                        <th class="px-6 py-4">Type & Group</th>
                        <th class="px-6 py-4">Contact</th>
                        <th class="px-6 py-4">Primary Address</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800">
                    @forelse($customers as $c)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition">
                        <td class="px-6 py-4">
                            <div class="font-semibold text-apeiron-ink dark:text-white">{{ $c->name }}</div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-xs font-mono text-zinc-400">{{ $c->customer_code }}</span>
                                @if($c->isCompany() && $c->company_name)
                                    <x-ui.badge variant="info" size="xs">
                                        {{ $c->company_name }}
                                    </x-ui.badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col gap-1">
                                <x-ui.badge variant="{{ $c->isCompany() ? 'info' : 'neutral' }}" size="sm">
                                    {{ $c->isCompany() ? 'B2B (Company)' : 'B2C (Individual)' }}
                                </x-ui.badge>
                                @if($c->group)
                                    <span class="text-xs text-zinc-500 font-medium">{{ $c->group->name }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs space-y-0.5">
                                @if($c->phone)
                                    <div class="flex items-center gap-1.5 text-zinc-700 dark:text-zinc-200 font-mono">
                                        <span class="material-symbols-rounded text-xs text-zinc-400">call</span>
                                        {{ $c->phone }}
                                    </div>
                                @endif
                                @if($c->email)
                                    <div class="flex items-center gap-1.5 text-zinc-500 font-mono">
                                        <span class="material-symbols-rounded text-xs text-zinc-400">mail</span>
                                        {{ $c->email }}
                                    </div>
                                @endif
                                @if(!$c->phone && !$c->email)
                                    <span class="text-zinc-400 italic">-</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 max-w-xs">
                            @php
                                $addr = $c->defaultShippingAddress ?? $c->defaultBillingAddress ?? $c->addresses->first();
                            @endphp
                            @if($addr)
                                <div class="text-xs text-zinc-600 dark:text-zinc-400 line-clamp-2">
                                    {{ $addr->address_line1 }}{{ $addr->city ? ', '.$addr->city : '' }}
                                </div>
                            @else
                                <span class="text-xs text-zinc-400 italic">No address yet</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            <x-ui.badge variant="{{ $c->is_active ? 'success' : 'neutral' }}" size="sm">
                                {{ $c->is_active ? 'Active' : 'Inactive' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button wire:click="openAddresses({{ $c->id }})" title="Manage Addresses" class="p-1.5 rounded-lg text-zinc-500 hover:text-apeiron-primary hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">home_pin</span>
                                </button>
                                <button wire:click="openStatement({{ $c->id }})" title="Statement & Transaction History" class="p-1.5 rounded-lg text-zinc-500 hover:text-apeiron-primary hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">receipt_long</span>
                                </button>
                                @if($c->isCompany())
                                <button wire:click="openContacts({{ $c->id }})" title="Manage B2B Contacts" class="p-1.5 rounded-lg text-zinc-500 hover:text-indigo-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">contacts</span>
                                </button>
                                @endif
                                @can('merge', $c)
                                <button wire:click="openMerge({{ $c->id }})" title="Merge Customer" class="p-1.5 rounded-lg text-zinc-500 hover:text-amber-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">merge</span>
                                </button>
                                @endcan
                                @can('update', $c)
                                <button wire:click="edit({{ $c->id }})" title="Edit Customer" class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">edit</span>
                                </button>
                                @endcan
                                @can('delete', $c)
                                <button wire:click="delete({{ $c->id }})" wire:confirm="Are you sure you want to deactivate this customer?" title="Delete Customer" class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">delete</span>
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-zinc-400">
                            <span class="material-symbols-rounded text-4xl block mb-2">person_off</span>
                            No customer data matches the search criteria.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($customers->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $customers->links() }}
            </div>
        @endif
    </div>

    <!-- Modal 1: Customer Form -->
    <x-ui.modal wire:model="showModal" maxWidth="2xl">
        <x-slot:title>
            {{ $isEditing ? 'Edit Customer' : 'Add New Customer' }}
        </x-slot:title>

        <x-slot:content>
            <form id="customerForm" wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="customer_type">Customer Type *</x-ui.label>
                        <x-ui.select id="customer_type" wire:model.live="customer_type">
                            <option value="INDIVIDUAL">Individual (B2C / Retail)</option>
                            <option value="COMPANY">Company / Business Entity (B2B)</option>
                        </x-ui.select>
                        @error('customer_type') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="customer_group_id">Customer Group / Segment</x-ui.label>
                        <x-ui.select id="customer_group_id" wire:model="customer_group_id">
                            <option value="">-- No Group (General) --</option>
                            @foreach($customerGroups as $grp)
                                <option value="{{ $grp->id }}">{{ $grp->name }} ({{ $grp->default_discount_rate }}% Discount)</option>
                            @endforeach
                        </x-ui.select>
                        @error('customer_group_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="{{ $customer_type === 'COMPANY' ? 'md:col-span-1' : 'md:col-span-2' }}">
                        <x-ui.label for="name">
                            {{ $customer_type === 'COMPANY' ? 'Contact Name / PIC *' : 'Customer Full Name *' }}
                        </x-ui.label>
                        <x-ui.input type="text" id="name" wire:model="name" placeholder="e.g. John Doe" />
                        @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($customer_type === 'COMPANY')
                    <div>
                        <x-ui.label for="company_name">Company / Business Name</x-ui.label>
                        <x-ui.input type="text" id="company_name" wire:model="company_name" placeholder="e.g. Acme Corp LLC" />
                        @error('company_name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="tax_id">Tax ID (NPWP)</x-ui.label>
                        <x-ui.input type="text" id="tax_id" wire:model="tax_id" placeholder="e.g. 01.234.567.8-901.000" />
                        @error('tax_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="business_registration_no">Business Registration No. (NIB)</x-ui.label>
                        <x-ui.input type="text" id="business_registration_no" wire:model="business_registration_no" placeholder="Business License / Registration Number" />
                        @error('business_registration_no') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    @endif

                    <div>
                        <x-ui.label for="phone">Phone Number / WhatsApp</x-ui.label>
                        <x-ui.input type="text" id="phone" wire:model="phone" placeholder="e.g. +628123456789" />
                        @error('phone') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="email">Email Address</x-ui.label>
                        <x-ui.input type="email" id="email" wire:model="email" placeholder="e.g. customer@domain.com" />
                        @error('email') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($customer_type === 'INDIVIDUAL')
                    <div>
                        <x-ui.label for="date_of_birth">Date of Birth (For Birthday Promotions)</x-ui.label>
                        <x-ui.input type="date" id="date_of_birth" wire:model="date_of_birth" />
                        @error('date_of_birth') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    @endif
                </div>

                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800 flex items-center gap-6">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_active" class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Active Account Status</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_loyalty_eligible" class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Eligible for Loyalty Points</span>
                    </label>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="save" wire:loading.attr="disabled">
                    {{ $isEditing ? 'Save Changes' : 'Create Customer' }}
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 2: Manage Addresses -->
    <x-ui.modal wire:model="showAddressModal" maxWidth="2xl">
        <x-slot:title>
            Manage Customer Addresses
        </x-slot:title>

        <x-slot:content>
            @if($activeCustomer)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Customer: <strong class="text-zinc-800 dark:text-zinc-200">{{ $activeCustomer->name }}</strong> ({{ $activeCustomer->customer_code }})
                </div>

                @if (session()->has('address_message'))
                    <x-ui.alert type="success" size="sm" class="rounded-xl">
                        {{ session('address_message') }}
                    </x-ui.alert>
                @endif

                <!-- Saved Addresses List -->
                <div class="space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-500 uppercase">Saved Addresses</h4>
                    <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                        @forelse($activeAddresses as $addr)
                        <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ $addr->label ?: 'Address' }}</span>
                                    <x-ui.badge variant="neutral" size="xs">{{ $addr->address_type }}</x-ui.badge>
                                    @if($addr->is_default_shipping)
                                        <x-ui.badge variant="success" size="xs">Default Shipping</x-ui.badge>
                                    @endif
                                    @if($addr->is_default_billing)
                                        <x-ui.badge variant="info" size="xs">Default Billing</x-ui.badge>
                                    @endif
                                </div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-1">{{ $addr->formatted_address }}</p>
                                @if($addr->recipient_name)
                                    <p class="text-[11px] text-zinc-500 mt-0.5">Recipient: {{ $addr->recipient_name }} ({{ $addr->phone }})</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="editAddress({{ $addr->id }})" class="p-1 text-zinc-400 hover:text-blue-600">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </button>
                                <button wire:click="deleteAddress({{ $addr->id }})" wire:confirm="Delete this address?" class="p-1 text-zinc-400 hover:text-rose-600">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 italic">No saved addresses yet.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Form Add/Edit Address -->
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $addressId ? 'Edit Address' : 'Add New Address' }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="address_type">Address Type</x-ui.label>
                            <x-ui.select id="address_type" wire:model="address_type">
                                <option value="SHIPPING">Shipping</option>
                                <option value="BILLING">Billing</option>
                                <option value="BOTH">Both</option>
                            </x-ui.select>
                        </div>
                        <div>
                            <x-ui.label for="address_label">Address Label</x-ui.label>
                            <x-ui.input type="text" id="address_label" wire:model="address_label" placeholder="e.g. Headquarters, Main Warehouse" />
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.label for="address_line1">Address Line *</x-ui.label>
                            <textarea id="address_line1" wire:model="address_line1" rows="2" placeholder="Street address, building number, suite/unit" class="w-full text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 text-zinc-800 dark:text-zinc-200"></textarea>
                            @error('address_line1') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-ui.label for="city">City / District</x-ui.label>
                            <x-ui.input type="text" id="city" wire:model="city" placeholder="e.g. Surabaya" />
                        </div>
                        <div>
                            <x-ui.label for="province">State / Province</x-ui.label>
                            <x-ui.input type="text" id="province" wire:model="province" placeholder="e.g. East Java" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4 pt-2">
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="is_default_shipping" class="rounded border-zinc-300 text-apeiron-primary">
                            Default Shipping
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="is_default_billing" class="rounded border-zinc-300 text-apeiron-primary">
                            Default Billing
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        @if($addressId)
                            <x-ui.button type="button" variant="secondary" size="xs" wire:click="addAddress">Cancel Edit</x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="primary" size="xs" wire:click="saveAddress">
                            {{ $addressId ? 'Update Address' : 'Save Address' }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="$set('showAddressModal', false)">
                    Close
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 3: Manage B2B Contact Persons -->
    <x-ui.modal wire:model="showContactModal" maxWidth="xl">
        <x-slot:title>
            B2B Contact Persons
        </x-slot:title>

        <x-slot:content>
            @if($activeCustomer)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Customer: <strong class="text-zinc-800 dark:text-zinc-200">{{ $activeCustomer->name }}</strong> ({{ $activeCustomer->company_name }})
                </div>

                @if (session()->has('contact_message'))
                    <x-ui.alert type="success" size="sm" class="rounded-xl">
                        {{ session('contact_message') }}
                    </x-ui.alert>
                @endif

                <!-- Contact List -->
                <div class="space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-500 uppercase">Contact Persons List</h4>
                    <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                        @forelse($activeContacts as $contact)
                        <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-center justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ $contact->name }}</span>
                                    @if($contact->role_title)
                                        <x-ui.badge variant="info" size="xs">{{ $contact->role_title }}</x-ui.badge>
                                    @endif
                                    @if($contact->is_primary)
                                        <x-ui.badge variant="success" size="xs">Primary</x-ui.badge>
                                    @endif
                                </div>
                                <div class="text-[11px] text-zinc-500 flex items-center gap-3 mt-1 font-mono">
                                    @if($contact->phone) <span>{{ $contact->phone }}</span> @endif
                                    @if($contact->email) <span>{{ $contact->email }}</span> @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="editContact({{ $contact->id }})" class="p-1 text-zinc-400 hover:text-blue-600">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </button>
                                <button wire:click="deleteContact({{ $contact->id }})" wire:confirm="Delete this contact person?" class="p-1 text-zinc-400 hover:text-rose-600">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 italic">No contact persons saved yet.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $contactId ? 'Edit Contact' : 'Add New Contact' }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="contact_name">Contact Person Name *</x-ui.label>
                            <x-ui.input type="text" id="contact_name" wire:model="contact_name" placeholder="e.g. Jane Smith" />
                            @error('contact_name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-ui.label for="contact_role_title">Job Title / Role</x-ui.label>
                            <x-ui.input type="text" id="contact_role_title" wire:model="contact_role_title" placeholder="e.g. Purchasing, Finance, Logistics" />
                        </div>
                        <div>
                            <x-ui.label for="contact_phone">Phone Number</x-ui.label>
                            <x-ui.input type="text" id="contact_phone" wire:model="contact_phone" placeholder="e.g. +628129876543" />
                        </div>
                        <div>
                            <x-ui.label for="contact_email">Email</x-ui.label>
                            <x-ui.input type="email" id="contact_email" wire:model="contact_email" placeholder="e.g. finance@company.com" />
                        </div>
                    </div>

                    <div class="pt-2">
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="contact_is_primary" class="rounded border-zinc-300 text-apeiron-primary">
                            Set as Primary Contact Person (Primary PIC)
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        @if($contactId)
                            <x-ui.button type="button" variant="secondary" size="xs" wire:click="addContact">Cancel Edit</x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="primary" size="xs" wire:click="saveContact">
                            {{ $contactId ? 'Update Contact' : 'Save Contact' }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="$set('showContactModal', false)">
                    Close
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 4: Customer Merge -->
    <x-ui.modal wire:model="showMergeModal" maxWidth="lg">
        <x-slot:title>
            Merge Customer
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                @if (session()->has('merge_error'))
                    <x-ui.alert type="danger" size="sm" class="rounded-xl">
                        {{ session('merge_error') }}
                    </x-ui.alert>
                @endif

                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-xs leading-relaxed">
                    <strong>Important Warning:</strong> The source customer will be permanently deactivated (soft-deleted). All transaction history (Orders, Addresses, Contacts, and Loyalty Points) will be atomically transferred to the selected target customer.
                </div>

                <div>
                    <x-ui.label for="mergeTargetId">Target Customer (Merge Destination) *</x-ui.label>
                    <x-ui.select id="mergeTargetId" wire:model="mergeTargetId">
                        <option value="">-- Select Target Customer --</option>
                        @foreach($mergeTargetCandidates as $tgt)
                            <option value="{{ $tgt->id }}">{{ $tgt->name }} ({{ $tgt->customer_code }}) - {{ $tgt->phone ?: $tgt->email }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('mergeTargetId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="mergeReason">Merge Reason *</x-ui.label>
                    <textarea id="mergeReason" wire:model="mergeReason" rows="3" placeholder="e.g. Duplicate account registered with old and new phone number belonging to the same individual." class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5 text-zinc-800 dark:text-zinc-200"></textarea>
                    @error('mergeReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showMergeModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="executeMerge" wire:loading.attr="disabled">
                    Confirm & Merge
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 5: Statement & History Modal -->
    <x-ui.modal wire:model="showStatementModal" maxWidth="4xl">
        <x-slot:title>
            Customer Statement of Account
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-5">
                <div class="text-xs text-zinc-500 -mt-2">
                    Receivable transactions history, invoices, payments, and running balance.
                </div>

                <!-- Date Filter Bar & Export -->
                <div class="flex flex-wrap items-center justify-between gap-3 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-[#E5E7EB] dark:border-zinc-800">
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <x-ui.label for="statementStartDate">From Date</x-ui.label>
                            <x-ui.input type="date" id="statementStartDate" wire:model="statementStartDate" class="text-xs" />
                        </div>
                        <div>
                            <x-ui.label for="statementEndDate">To Date</x-ui.label>
                            <x-ui.input type="date" id="statementEndDate" wire:model="statementEndDate" class="text-xs" />
                        </div>
                        <div class="self-end">
                            <x-ui.button variant="secondary" size="sm" wire:click="loadStatementPreview" type="button" class="gap-1.5">
                                <span class="material-symbols-rounded text-sm">refresh</span>
                                Refresh
                            </x-ui.button>
                        </div>
                    </div>
                    <div class="self-end">
                        <x-ui.button variant="primary" size="sm" wire:click="exportStatement" type="button" class="gap-1.5 shadow-xs">
                            <span class="material-symbols-rounded text-sm">download</span>
                            Export Excel (.xlsx)
                        </x-ui.button>
                    </div>
                </div>

                <!-- Balance Summary Cards -->
                @if($statementPreview)
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 rounded-xl bg-zinc-100/70 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-800">
                        <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Opening Balance (Period)</div>
                        <div class="text-xl font-bold font-mono text-apeiron-ink dark:text-white mt-1">
                            Rp {{ number_format((float) $statementPreview['opening_balance'], 2, ',', '.') }}
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800">
                        <div class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Closing Balance (Balance Due)</div>
                        <div class="text-xl font-bold font-mono text-emerald-800 dark:text-emerald-300 mt-1">
                            Rp {{ number_format((float) $statementPreview['closing_balance'], 2, ',', '.') }}
                        </div>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="rounded-xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden max-h-80 overflow-y-auto">
                    <table class="table-standard w-full min-w-full text-left text-xs text-zinc-600 dark:text-zinc-300">
                        <thead class="bg-zinc-50 dark:bg-zinc-900/80 text-[11px] font-semibold uppercase text-zinc-500 sticky top-0 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="px-3 py-2.5">Date</th>
                                <th class="px-3 py-2.5">Entry Type</th>
                                <th class="px-3 py-2.5">Reference No.</th>
                                <th class="px-3 py-2.5">Description</th>
                                <th class="px-3 py-2.5 text-right">Debit</th>
                                <th class="px-3 py-2.5 text-right">Credit</th>
                                <th class="px-3 py-2.5 text-right">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800">
                            @forelse($statementPreview['transactions'] as $tx)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($tx['entry_date'])->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">
                                    <x-ui.badge variant="neutral" size="xs">{{ $tx['entry_type_label'] }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 font-mono text-[11px]">{{ $tx['reference_number'] ?: '-' }}</td>
                                <td class="px-3 py-2 max-w-xs truncate" title="{{ $tx['notes'] }}">{{ $tx['notes'] ?: '-' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ (float) $tx['debit_amount'] > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-zinc-400' }}">
                                    {{ (float) $tx['debit_amount'] > 0 ? 'Rp '.number_format((float) $tx['debit_amount'], 2, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ (float) $tx['credit_amount'] > 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-zinc-400' }}">
                                    {{ (float) $tx['credit_amount'] > 0 ? 'Rp '.number_format((float) $tx['credit_amount'], 2, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums font-bold text-zinc-800 dark:text-zinc-200">
                                    Rp {{ number_format((float) $tx['running_balance'], 2, ',', '.') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-zinc-400 italic">
                                    No receivable transactions in this date range.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="closeStatement">
                    Close
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
