<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Suppliers"
        description="Manage vendor profiles, contact persons, and item pricing catalogs for raw materials and retail products.">
        <x-slot:actions>
            @can('create', \App\Models\Supplier::class)
            <x-ui.button wire:click="create" wire:loading.attr="disabled" icon="add_business" variant="primary">
                Add Supplier
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert variant="success" icon="check_circle">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger" icon="error">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search name, code, email, or phone..."
                icon="search" />
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="statusFilter">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="on_hold">On Hold</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="table-standard min-w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Code & Name</th>
                        <th class="px-6 py-4">Primary Contact</th>
                        <th class="px-6 py-4">Payment Terms</th>
                        <th class="px-6 py-4 text-center">Item Catalog</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($suppliers as $supplier)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $supplier->name }}</div>
                            <div class="text-xs text-zinc-500 font-mono">{{ $supplier->code }}</div>
                            @if($supplier->tax_id)
                                <div class="text-[11px] text-zinc-400 mt-0.5">Tax ID: {{ $supplier->tax_id }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @if($supplier->primaryContact)
                                <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $supplier->primaryContact->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $supplier->primaryContact->phone ?: $supplier->primaryContact->email }}</div>
                            @elseif($supplier->email || $supplier->phone)
                                <div class="text-xs text-zinc-500">{{ $supplier->phone ?: $supplier->email }}</div>
                            @else
                                <span class="text-xs text-zinc-400 italic">No contact recorded</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200 tabular-nums">{{ $supplier->payment_terms_days }} Days</span>
                            <span class="text-xs text-zinc-400 ml-1">({{ $supplier->currency_code }})</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <button wire:click="openCatalog({{ $supplier->id }})" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition-colors">
                                <span class="material-symbols-rounded text-sm">inventory_2</span>
                                <span class="tabular-nums">{{ $supplier->supplier_products_count }}</span> Items
                            </button>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $badgeVariant = match($supplier->status->value) {
                                    'active' => 'success',
                                    'on_hold' => 'warning',
                                    'inactive' => 'neutral',
                                    'archived' => 'danger',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$badgeVariant">
                                {{ $supplier->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="openContacts({{ $supplier->id }})"
                                    title="Manage Contacts"
                                    aria-label="Contacts">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">contacts</span>
                                </x-ui.button>
                                @can('update', $supplier)
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="edit({{ $supplier->id }})"
                                    title="Edit Supplier"
                                    aria-label="Edit">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">edit</span>
                                </x-ui.button>
                                @endcan
                                @can('delete', $supplier)
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="delete({{ $supplier->id }})"
                                    wire:confirm="Delete this supplier? All associated catalog entries will be disabled."
                                    title="Delete Supplier"
                                    aria-label="Delete">
                                    <span class="material-symbols-rounded text-base text-rose-500">delete</span>
                                </x-ui.button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-5xl text-zinc-300 dark:text-zinc-700">store_mall_directory</span>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">No suppliers found</h3>
                                <p class="mt-1 text-xs text-zinc-500">Get started by adding a new supplier for procurement.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($suppliers->hasPages())
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $suppliers->links() }}
        </div>
        @endif
    </div>

    <!-- Modal Form Pemasok -->
    <x-ui.modal wire:model="showModal" maxWidth="2xl">
        <x-slot:title>
            {{ $isEditing ? 'Edit Supplier' : 'Add New Supplier' }}
        </x-slot:title>

        <x-slot:content>
            <form id="supplierForm" wire:submit="saveSupplier" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="code">Supplier Code (Optional)</x-ui.label>
                        <x-ui.input type="text" id="code" wire:model="code" placeholder="Auto-generated if blank" />
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="name">Supplier Name *</x-ui.label>
                        <x-ui.input type="text" id="name" wire:model="name" required placeholder="Acme Supplies" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="email">Company Email</x-ui.label>
                        <x-ui.input type="email" id="email" wire:model="email" placeholder="sales@vendor.com" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="phone">Phone Number</x-ui.label>
                        <x-ui.input type="text" id="phone" wire:model="phone" placeholder="021-5551234" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <x-ui.label for="tax_id">Tax ID</x-ui.label>
                        <x-ui.input type="text" id="tax_id" wire:model="tax_id" placeholder="01.234.567.8-901.000" />
                        <x-input-error :messages="$errors->get('tax_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="payment_terms_days">Payment Terms (Days)</x-ui.label>
                        <x-ui.input type="number" id="payment_terms_days" min="0" wire:model="payment_terms_days" />
                        <x-input-error :messages="$errors->get('payment_terms_days')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="status">Supplier Status</x-ui.label>
                        <x-ui.select id="status" wire:model="status">
                            <option value="active">Active</option>
                            <option value="on_hold">On Hold</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-ui.label for="address">Address</x-ui.label>
                    <textarea id="address" wire:model="address" rows="2" placeholder="Office or warehouse address..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-apeiron-ink/20 focus:border-apeiron-ink"></textarea>
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>

                <div>
                    <x-ui.label for="notes">Additional Notes</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Special terms, warranty conditions, notes..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-apeiron-ink/20 focus:border-apeiron-ink"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="supplierForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save</span>
                    <span wire:loading>Saving...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Kontak Pemasok -->
    <x-ui.modal wire:model="showContactModal" maxWidth="2xl">
        <x-slot:title>
            <div>
                <span>Supplier Contacts</span>
                @if($activeSupplierName)
                    <p class="text-xs text-zinc-500 mt-0.5">Supplier: {{ $activeSupplierName }}</p>
                @endif
            </div>
        </x-slot:title>

        <x-slot:content>
            @if($activeSupplierId)
                @if(session()->has('contact_message'))
                    <x-ui.alert variant="success" icon="check_circle" class="mb-4">
                        {{ session('contact_message') }}
                    </x-ui.alert>
                @endif

                <!-- Form Kontak Baru/Ubah -->
                <form wire:submit="saveContact" class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 space-y-3 border border-zinc-200 dark:border-zinc-800">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="contactName">Contact Name *</x-ui.label>
                            <x-ui.input type="text" id="contactName" wire:model="contactName" required placeholder="e.g. John Doe" />
                            <x-input-error :messages="$errors->get('contactName')" class="mt-1" />
                        </div>
                        <div>
                            <x-ui.label for="contactTitle">Job Title / Department</x-ui.label>
                            <x-ui.input type="text" id="contactTitle" wire:model="contactTitle" placeholder="Sales Executive" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="contactEmail">Email</x-ui.label>
                            <x-ui.input type="email" id="contactEmail" wire:model="contactEmail" placeholder="budi@vendor.com" />
                        </div>
                        <div>
                            <x-ui.label for="contactPhone">Phone / Mobile</x-ui.label>
                            <x-ui.input type="text" id="contactPhone" wire:model="contactPhone" placeholder="08123456789" />
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="contactIsPrimary" wire:model="contactIsPrimary" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                        <label for="contactIsPrimary" class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Set as Primary Contact</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" variant="secondary" wire:click="resetContactForm">Reset</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save Contact</x-ui.button>
                    </div>
                </form>

                <!-- Daftar Kontak Saat Ini -->
                <div class="space-y-2 mt-4">
                    <h4 class="text-xs font-bold uppercase text-zinc-400">Contact List</h4>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-56 overflow-y-auto">
                        @forelse($activeContacts as $contact)
                        <div class="py-2.5 flex items-center justify-between">
                            <div>
                                <div class="font-medium text-sm text-zinc-900 dark:text-white flex items-center gap-2">
                                    {{ $contact->name }}
                                    @if($contact->is_primary)
                                        <x-ui.badge variant="success">Primary</x-ui.badge>
                                    @endif
                                    @if($contact->title)
                                        <span class="text-xs text-zinc-400">({{ $contact->title }})</span>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500">{{ $contact->phone }} &bull; {{ $contact->email }}</div>
                            </div>
                            <div class="flex items-center gap-1">
                                <x-ui.button size="icon" variant="ghost" wire:click="editContact({{ $contact->id }})" aria-label="Edit">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </x-ui.button>
                                <x-ui.button size="icon" variant="ghost" wire:click="deleteContact({{ $contact->id }})" aria-label="Delete">
                                    <span class="material-symbols-rounded text-sm text-rose-500">delete</span>
                                </x-ui.button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 py-3 text-center">No contacts registered yet.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showContactModal', false)">Close</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Katalog Item Pemasok -->
    <x-ui.modal wire:model="showCatalogModal" maxWidth="3xl">
        <x-slot:title>
            <div>
                <span>Supplier Item & Price Catalog</span>
                @if($activeSupplierName)
                    <p class="text-xs text-zinc-500 mt-0.5">Supplier: {{ $activeSupplierName }}</p>
                @endif
            </div>
        </x-slot:title>

        <x-slot:content>
            @if($activeSupplierId)
                @if(session()->has('catalog_message'))
                    <x-ui.alert variant="success" icon="check_circle" class="mb-4">
                        {{ session('catalog_message') }}
                    </x-ui.alert>
                @endif

                <!-- Form Tambah/Ubah Item Katalog -->
                <form wire:submit="saveCatalogItem" class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 space-y-3 border border-zinc-200 dark:border-zinc-800">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <x-ui.label for="catalogPurchasableType">Item Type *</x-ui.label>
                            <x-ui.select id="catalogPurchasableType" wire:model.live="catalogPurchasableType">
                                <option value="product">Retail Product (Catalog)</option>
                                <option value="ingredient">Raw Material (Inventory)</option>
                            </x-ui.select>
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.label for="catalogPurchasableId">Select Master Item *</x-ui.label>
                            <x-ui.select id="catalogPurchasableId" wire:model="catalogPurchasableId" required>
                                <option value="">-- Select Item --</option>
                                @if($catalogPurchasableType === 'product')
                                    @foreach($availableProducts as $prod)
                                        <option value="{{ $prod->id }}">{{ $prod->name }} (SKU: {{ $prod->sku }})</option>
                                    @endforeach
                                @else
                                    @foreach($availableIngredients as $ing)
                                        <option value="{{ $ing->id }}">{{ $ing->name }} (Unit: {{ $ing->unit }})</option>
                                    @endforeach
                                @endif
                            </x-ui.select>
                            <x-input-error :messages="$errors->get('catalogPurchasableId')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <x-ui.label for="catalogSupplierSku">Supplier SKU</x-ui.label>
                            <x-ui.input type="text" id="catalogSupplierSku" wire:model="catalogSupplierSku" placeholder="VND-1002" />
                        </div>
                        <div>
                            <x-ui.label for="catalogPurchaseUom">Purchase UOM *</x-ui.label>
                            <x-ui.input type="text" id="catalogPurchaseUom" wire:model="catalogPurchaseUom" required placeholder="BOX, CARTON" />
                        </div>
                        <div>
                            <x-ui.label for="catalogConversionFactor">Conversion Factor *</x-ui.label>
                            <x-ui.input type="number" step="0.000001" min="0.000001" id="catalogConversionFactor" wire:model="catalogConversionFactor" required />
                        </div>
                        <div>
                            <x-ui.label for="catalogStandardPrice">Standard Purchase Price (Rp) *</x-ui.label>
                            <x-ui.input type="number" step="0.01" min="0" id="catalogStandardPrice" wire:model="catalogStandardPrice" required />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <x-ui.label for="catalogLeadTimeDays">Lead Time (Days)</x-ui.label>
                            <x-ui.input type="number" min="0" id="catalogLeadTimeDays" wire:model="catalogLeadTimeDays" />
                        </div>
                        <div>
                            <x-ui.label for="catalogMinOrderQty">Min Order (MOQ)</x-ui.label>
                            <x-ui.input type="number" step="0.0001" min="0.0001" id="catalogMinOrderQty" wire:model="catalogMinOrderQty" />
                        </div>
                        <div>
                            <x-ui.label for="catalogOrderMultipleQty">Order Multiple</x-ui.label>
                            <x-ui.input type="number" step="0.0001" min="0.0001" id="catalogOrderMultipleQty" wire:model="catalogOrderMultipleQty" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="catalogIsPreferred" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                            <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Preferred Supplier</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="catalogIsActive" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                            <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Active</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" variant="secondary" wire:click="resetCatalogForm">Reset</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save Catalog Item</x-ui.button>
                    </div>
                </form>

                <!-- Tabel Katalog Saat Ini -->
                <div class="space-y-2 mt-4">
                    <h4 class="text-xs font-bold uppercase text-zinc-400">Registered Catalog Items</h4>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-56 overflow-y-auto">
                        @forelse($activeCatalogItems as $item)
                        <div class="py-2.5 flex items-center justify-between text-xs">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white flex items-center gap-1.5">
                                    {{ $item->purchasable?->name ?? 'Item #' . $item->purchasable_id }}
                                    <x-ui.badge variant="neutral">{{ strtoupper($item->purchasable_type) }}</x-ui.badge>
                                    @if($item->is_preferred)
                                        <x-ui.badge variant="warning">Preferred</x-ui.badge>
                                    @endif
                                </div>
                                <div class="text-zinc-500 mt-0.5">
                                    UOM: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $item->purchase_uom }}</span> (x{{ (float) $item->uom_conversion_factor }}) &bull;
                                    Price: <span class="font-semibold text-zinc-700 dark:text-zinc-300">Rp {{ number_format((float) $item->standard_price, 2) }}</span> &bull;
                                    Lead Time: {{ $item->lead_time_days }} days
                                </div>
                            </div>
                            <x-ui.button size="icon" variant="ghost" wire:click="deleteCatalogItem({{ $item->id }})" aria-label="Delete">
                                <span class="material-symbols-rounded text-sm text-rose-500">delete</span>
                            </x-ui.button>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 py-3 text-center">No items in this supplier's catalog.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCatalogModal', false)">Close</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
