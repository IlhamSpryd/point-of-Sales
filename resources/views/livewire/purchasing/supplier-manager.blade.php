<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Data Induk Pemasok"
        description="Kelola data vendor pengadaan, kontak narahubung, dan katalog harga item bahan baku serta produk ritel.">
        <x-slot:actions>
            @can('create', \App\Models\Supplier::class)
            <x-ui.button wire:click="create" wire:loading.attr="disabled" icon="add_business" variant="primary">
                Tambah Pemasok
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
                placeholder="Cari nama, kode, email, atau telepon..."
                icon="search" />
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="statusFilter">
                <option value="">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="on_hold">Ditahan (On Hold)</option>
                <option value="inactive">Non-Aktif</option>
                <option value="archived">Diarsipkan</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Kode & Nama</th>
                        <th class="px-6 py-4">Kontak Utama</th>
                        <th class="px-6 py-4">Termin Bayar</th>
                        <th class="px-6 py-4 text-center">Katalog Item</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($suppliers as $supplier)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $supplier->name }}</div>
                            <div class="text-xs text-zinc-500 font-mono">{{ $supplier->code }}</div>
                            @if($supplier->tax_id)
                                <div class="text-[11px] text-zinc-400 mt-0.5">NPWP: {{ $supplier->tax_id }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @if($supplier->primaryContact)
                                <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $supplier->primaryContact->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $supplier->primaryContact->phone ?: $supplier->primaryContact->email }}</div>
                            @elseif($supplier->email || $supplier->phone)
                                <div class="text-xs text-zinc-500">{{ $supplier->phone ?: $supplier->email }}</div>
                            @else
                                <span class="text-xs text-zinc-400 italic">Belum ada kontak</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $supplier->payment_terms_days }} Hari</span>
                            <span class="text-xs text-zinc-400 ml-1">({{ $supplier->currency_code }})</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <button wire:click="openCatalog({{ $supplier->id }})" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition-colors">
                                <span class="material-symbols-rounded text-sm">inventory_2</span>
                                {{ $supplier->supplier_products_count }} Item
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
                                    title="Kelola Kontak Narahubung"
                                    aria-label="Kontak">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">contacts</span>
                                </x-ui.button>
                                @can('update', $supplier)
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="edit({{ $supplier->id }})"
                                    title="Ubah Data Pemasok"
                                    aria-label="Edit">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">edit</span>
                                </x-ui.button>
                                @endcan
                                @can('delete', $supplier)
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="delete({{ $supplier->id }})"
                                    wire:confirm="Yakin ingin menghapus pemasok ini? Seluruh data katalog terkait akan dinonaktifkan."
                                    title="Hapus Pemasok"
                                    aria-label="Hapus">
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
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Tidak ada data pemasok</h3>
                                <p class="mt-1 text-xs text-zinc-500">Mulai dengan menambahkan pemasok baru untuk pengadaan barang.</p>
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
            {{ $isEditing ? 'Ubah Data Pemasok' : 'Tambah Pemasok Baru' }}
        </x-slot:title>

        <x-slot:content>
            <form id="supplierForm" wire:submit="saveSupplier" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="code">Kode Pemasok (Opsional)</x-ui.label>
                        <x-ui.input type="text" id="code" wire:model="code" placeholder="Otomatis jika dikosongkan" />
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="name">Nama Pemasok *</x-ui.label>
                        <x-ui.input type="text" id="name" wire:model="name" required placeholder="PT Sumber Pangan" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="email">Email Perusahaan</x-ui.label>
                        <x-ui.input type="email" id="email" wire:model="email" placeholder="sales@vendor.com" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="phone">Nomor Telepon</x-ui.label>
                        <x-ui.input type="text" id="phone" wire:model="phone" placeholder="021-5551234" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <x-ui.label for="tax_id">NPWP / Tax ID</x-ui.label>
                        <x-ui.input type="text" id="tax_id" wire:model="tax_id" placeholder="01.234.567.8-901.000" />
                        <x-input-error :messages="$errors->get('tax_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="payment_terms_days">Termin Bayar (Hari)</x-ui.label>
                        <x-ui.input type="number" id="payment_terms_days" min="0" wire:model="payment_terms_days" />
                        <x-input-error :messages="$errors->get('payment_terms_days')" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.label for="status">Status Pemasok</x-ui.label>
                        <x-ui.select id="status" wire:model="status">
                            <option value="active">Aktif</option>
                            <option value="on_hold">Ditahan (On Hold)</option>
                            <option value="inactive">Non-Aktif</option>
                            <option value="archived">Diarsipkan</option>
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-ui.label for="address">Alamat Lengkap</x-ui.label>
                    <textarea id="address" wire:model="address" rows="2" placeholder="Alamat kantor / gudang supplier..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-apeiron-ink/20 focus:border-apeiron-ink"></textarea>
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>

                <div>
                    <x-ui.label for="notes">Catatan Tambahan</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Keterangan khusus, syarat garansi, dsb..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-apeiron-ink/20 focus:border-apeiron-ink"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">Batal</x-ui.button>
                <x-ui.button type="submit" form="supplierForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan</span>
                    <span wire:loading>Menyimpan...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Kontak Pemasok -->
    <x-ui.modal wire:model="showContactModal" maxWidth="2xl">
        <x-slot:title>
            <div>
                <span>Kontak Narahubung</span>
                @if($activeSupplierName)
                    <p class="text-xs text-zinc-500 mt-0.5">Pemasok: {{ $activeSupplierName }}</p>
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
                            <x-ui.label for="contactName">Nama Narahubung *</x-ui.label>
                            <x-ui.input type="text" id="contactName" wire:model="contactName" required placeholder="Bpk. Budi Santoso" />
                            <x-input-error :messages="$errors->get('contactName')" class="mt-1" />
                        </div>
                        <div>
                            <x-ui.label for="contactTitle">Jabatan / Divisi</x-ui.label>
                            <x-ui.input type="text" id="contactTitle" wire:model="contactTitle" placeholder="Sales Executive" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="contactEmail">Email</x-ui.label>
                            <x-ui.input type="email" id="contactEmail" wire:model="contactEmail" placeholder="budi@vendor.com" />
                        </div>
                        <div>
                            <x-ui.label for="contactPhone">Nomor WhatsApp / HP</x-ui.label>
                            <x-ui.input type="text" id="contactPhone" wire:model="contactPhone" placeholder="08123456789" />
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="contactIsPrimary" wire:model="contactIsPrimary" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                        <label for="contactIsPrimary" class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Jadikan Kontak Utama</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" variant="secondary" wire:click="resetContactForm">Reset</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Simpan Kontak</x-ui.button>
                    </div>
                </form>

                <!-- Daftar Kontak Saat Ini -->
                <div class="space-y-2 mt-4">
                    <h4 class="text-xs font-bold uppercase text-zinc-400">Daftar Kontak</h4>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-56 overflow-y-auto">
                        @forelse($activeContacts as $contact)
                        <div class="py-2.5 flex items-center justify-between">
                            <div>
                                <div class="font-medium text-sm text-zinc-900 dark:text-white flex items-center gap-2">
                                    {{ $contact->name }}
                                    @if($contact->is_primary)
                                        <x-ui.badge variant="success">Utama</x-ui.badge>
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
                                <x-ui.button size="icon" variant="ghost" wire:click="deleteContact({{ $contact->id }})" aria-label="Hapus">
                                    <span class="material-symbols-rounded text-sm text-rose-500">delete</span>
                                </x-ui.button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 py-3 text-center">Belum ada narahubung yang terdaftar.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showContactModal', false)">Tutup</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Katalog Item Pemasok -->
    <x-ui.modal wire:model="showCatalogModal" maxWidth="3xl">
        <x-slot:title>
            <div>
                <span>Katalog Produk & Harga Pemasok</span>
                @if($activeSupplierName)
                    <p class="text-xs text-zinc-500 mt-0.5">Pemasok: {{ $activeSupplierName }}</p>
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
                            <x-ui.label for="catalogPurchasableType">Tipe Item *</x-ui.label>
                            <x-ui.select id="catalogPurchasableType" wire:model.live="catalogPurchasableType">
                                <option value="product">Produk Ritel (M3)</option>
                                <option value="ingredient">Bahan Baku (M4)</option>
                            </x-ui.select>
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.label for="catalogPurchasableId">Pilih Item Master *</x-ui.label>
                            <x-ui.select id="catalogPurchasableId" wire:model="catalogPurchasableId" required>
                                <option value="">-- Pilih Item --</option>
                                @if($catalogPurchasableType === 'product')
                                    @foreach($availableProducts as $prod)
                                        <option value="{{ $prod->id }}">{{ $prod->name }} (SKU: {{ $prod->sku }})</option>
                                    @endforeach
                                @else
                                    @foreach($availableIngredients as $ing)
                                        <option value="{{ $ing->id }}">{{ $ing->name }} (Satuan: {{ $ing->unit }})</option>
                                    @endforeach
                                @endif
                            </x-ui.select>
                            <x-input-error :messages="$errors->get('catalogPurchasableId')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <x-ui.label for="catalogSupplierSku">SKU Pemasok</x-ui.label>
                            <x-ui.input type="text" id="catalogSupplierSku" wire:model="catalogSupplierSku" placeholder="VND-1002" />
                        </div>
                        <div>
                            <x-ui.label for="catalogPurchaseUom">Satuan Beli *</x-ui.label>
                            <x-ui.input type="text" id="catalogPurchaseUom" wire:model="catalogPurchaseUom" required placeholder="DUS, CARTON" />
                        </div>
                        <div>
                            <x-ui.label for="catalogConversionFactor">Faktor Konversi *</x-ui.label>
                            <x-ui.input type="number" step="0.000001" min="0.000001" id="catalogConversionFactor" wire:model="catalogConversionFactor" required />
                        </div>
                        <div>
                            <x-ui.label for="catalogStandardPrice">Harga Standar (Rp) *</x-ui.label>
                            <x-ui.input type="number" step="0.01" min="0" id="catalogStandardPrice" wire:model="catalogStandardPrice" required />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <x-ui.label for="catalogLeadTimeDays">Lead Time (Hari)</x-ui.label>
                            <x-ui.input type="number" min="0" id="catalogLeadTimeDays" wire:model="catalogLeadTimeDays" />
                        </div>
                        <div>
                            <x-ui.label for="catalogMinOrderQty">Min Order (MOQ)</x-ui.label>
                            <x-ui.input type="number" step="0.0001" min="0.0001" id="catalogMinOrderQty" wire:model="catalogMinOrderQty" />
                        </div>
                        <div>
                            <x-ui.label for="catalogOrderMultipleQty">Kelipatan Order</x-ui.label>
                            <x-ui.input type="number" step="0.0001" min="0.0001" id="catalogOrderMultipleQty" wire:model="catalogOrderMultipleQty" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="catalogIsPreferred" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                            <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Pemasok Utama (Preferred)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="catalogIsActive" class="rounded border-zinc-300 dark:border-zinc-700 text-zinc-900 shadow-xs focus:ring-2 focus:ring-zinc-900/20">
                            <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Aktif</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" variant="secondary" wire:click="resetCatalogForm">Reset</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Simpan Item Katalog</x-ui.button>
                    </div>
                </form>

                <!-- Tabel Katalog Saat Ini -->
                <div class="space-y-2 mt-4">
                    <h4 class="text-xs font-bold uppercase text-zinc-400">Daftar Item Terdaftar</h4>
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
                                    Satuan: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $item->purchase_uom }}</span> (x{{ (float) $item->uom_conversion_factor }}) &bull;
                                    Harga: <span class="font-semibold text-zinc-700 dark:text-zinc-300">Rp {{ number_format((float) $item->standard_price, 2) }}</span> &bull;
                                    Lead Time: {{ $item->lead_time_days }} hari
                                </div>
                            </div>
                            <x-ui.button size="icon" variant="ghost" wire:click="deleteCatalogItem({{ $item->id }})" aria-label="Hapus">
                                <span class="material-symbols-rounded text-sm text-rose-500">delete</span>
                            </x-ui.button>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 py-3 text-center">Belum ada item dalam katalog pemasok ini.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCatalogModal', false)">Tutup</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
