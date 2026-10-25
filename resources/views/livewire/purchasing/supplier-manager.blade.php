<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-apeiron-ink dark:text-white">Data Induk Pemasok</h1>
            <p class="text-sm font-medium text-apeiron-muted mt-1">Kelola data vendor, kontak narahubung, dan katalog item bahan baku serta produk ritel.</p>
        </div>
        <div class="flex items-center gap-3">
            @can('create', \App\Models\Supplier::class)
            <x-primary-button wire:click="create" wire:loading.attr="disabled" class="gap-2">
                <span class="material-symbols-rounded text-lg">add_business</span>
                Tambah Pemasok
            </x-primary-button>
            @endcan
        </div>
    </div>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-emerald-600">check_circle</span>
            <span>{{ session('message') }}</span>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300 text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-rose-600">error</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nama, kode, email, atau telepon..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500">
            </div>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="statusFilter" class="text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-zinc-700 dark:text-zinc-300 focus:ring-2 focus:ring-emerald-500/20">
                <option value="">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="on_hold">Ditahan (On Hold)</option>
                <option value="inactive">Non-Aktif</option>
                <option value="archived">Diarsipkan</option>
            </select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-zinc-200/80 dark:border-zinc-800 shadow-sm">
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
                            <button wire:click="openCatalog({{ $supplier->id }})" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition-colors">
                                <span class="material-symbols-rounded text-sm">inventory_2</span>
                                {{ $supplier->supplier_products_count }} Item
                            </button>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $badgeVariant = match($supplier->status->value) {
                                    'active' => 'success',
                                    'on_hold' => 'warning',
                                    'inactive' => 'secondary',
                                    'archived' => 'danger',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-badge :variant="$badgeVariant">{{ $supplier->status->label() }}</x-badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="inline-flex items-center gap-1">
                                <button wire:click="openContacts({{ $supplier->id }})" title="Kelola Kontak" class="p-1.5 rounded-lg text-zinc-500 hover:text-zinc-800 dark:hover:text-white hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                                    <span class="material-symbols-rounded text-lg">contacts</span>
                                </button>
                                @can('update', $supplier)
                                <button wire:click="edit({{ $supplier->id }})" title="Ubah Pemasok" class="p-1.5 rounded-lg text-zinc-500 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition-colors">
                                    <span class="material-symbols-rounded text-lg">edit</span>
                                </button>
                                @endcan
                                @can('archive', $supplier)
                                @if($supplier->status->value !== 'archived')
                                <button wire:click="archiveSupplier({{ $supplier->id }})" wire:confirm="Apakah Anda yakin ingin mengarsipkan pemasok ini?" title="Arsipkan Pemasok" class="p-1.5 rounded-lg text-zinc-500 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30 transition-colors">
                                    <span class="material-symbols-rounded text-lg">archive</span>
                                </button>
                                @endif
                                @endcan
                                @can('delete', $supplier)
                                <button wire:click="deleteSupplier({{ $supplier->id }})" wire:confirm="Hapus pemasok ini secara permanen?" title="Hapus Pemasok" class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors">
                                    <span class="material-symbols-rounded text-lg">delete</span>
                                </button>
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
    @if($showModal)
    <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="card-surface rounded-2xl max-w-2xl w-full p-6 space-y-6 shadow-xl border border-zinc-200 dark:border-zinc-800 animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between pb-4 border-b border-zinc-200 dark:border-zinc-800">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                    {{ $isEditing ? 'Ubah Data Pemasok' : 'Tambah Pemasok Baru' }}
                </h3>
                <button wire:click="$set('showModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <form wire:submit="saveSupplier" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="code" value="Kode Pemasok (Opsional)" />
                        <input type="text" id="code" wire:model="code" placeholder="Otomatis jika dikosongkan" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="name" value="Nama Pemasok *" />
                        <input type="text" id="name" wire:model="name" required placeholder="PT Sumber Pangan" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="email" value="Email Perusahaan" />
                        <input type="email" id="email" wire:model="email" placeholder="sales@vendor.com" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="phone" value="Nomor Telepon" />
                        <input type="text" id="phone" wire:model="phone" placeholder="021-5551234" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="tax_id" value="NPWP / Tax ID" />
                        <input type="text" id="tax_id" wire:model="tax_id" placeholder="01.234.567.8-901.000" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('tax_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="payment_terms_days" value="Termin Bayar (Hari)" />
                        <input type="number" id="payment_terms_days" min="0" wire:model="payment_terms_days" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <x-input-error :messages="$errors->get('payment_terms_days')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="status" value="Status Pemasok" />
                        <select id="status" wire:model="status" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                            <option value="active">Aktif</option>
                            <option value="on_hold">Ditahan (On Hold)</option>
                            <option value="inactive">Non-Aktif</option>
                            <option value="archived">Diarsipkan</option>
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="address" value="Alamat Lengkap" />
                    <textarea id="address" wire:model="address" rows="2" placeholder="Alamat kantor / gudang supplier..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="notes" value="Catatan Tambahan" />
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Keterangan khusus, syarat garansi, dsb..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                    <x-secondary-button type="button" wire:click="$set('showModal', false)">Batal</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove>Simpan</span>
                        <span wire:loading>Menyimpan...</span>
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Modal Kontak Pemasok -->
    @if($showContactModal)
    <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="card-surface rounded-2xl max-w-2xl w-full p-6 space-y-6 shadow-xl border border-zinc-200 dark:border-zinc-800">
            <div class="flex items-center justify-between pb-4 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Kontak Narahubung</h3>
                    <p class="text-xs text-zinc-500">Pemasok: {{ $activeSupplierName }}</p>
                </div>
                <button wire:click="$set('showContactModal', false)" class="text-zinc-400 hover:text-zinc-600">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            @if(session()->has('contact_message'))
                <div class="p-3 rounded-lg bg-emerald-50 text-emerald-800 text-xs">{{ session('contact_message') }}</div>
            @endif

            <!-- Form Kontak Baru/Ubah -->
            <form wire:submit="saveContact" class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 space-y-3 border border-zinc-200 dark:border-zinc-800">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="contactName" value="Nama Narahubung *" />
                        <input type="text" id="contactName" wire:model="contactName" required placeholder="Bpk. Budi Santoso" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                        <x-input-error :messages="$errors->get('contactName')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="contactTitle" value="Jabatan / Divisi" />
                        <input type="text" id="contactTitle" wire:model="contactTitle" placeholder="Sales Executive" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="contactEmail" value="Email" />
                        <input type="email" id="contactEmail" wire:model="contactEmail" placeholder="budi@vendor.com" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="contactPhone" value="Nomor WhatsApp / HP" />
                        <input type="text" id="contactPhone" wire:model="contactPhone" placeholder="08123456789" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="contactIsPrimary" wire:model="contactIsPrimary" class="rounded text-emerald-600 focus:ring-emerald-500">
                    <label for="contactIsPrimary" class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Jadikan Kontak Utama</label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <x-secondary-button type="button" wire:click="resetContactForm">Reset</x-secondary-button>
                    <x-primary-button type="submit">Simpan Kontak</x-primary-button>
                </div>
            </form>

            <!-- Daftar Kontak Saat Ini -->
            <div class="space-y-2">
                <h4 class="text-xs font-bold uppercase text-zinc-400">Daftar Kontak</h4>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-56 overflow-y-auto">
                    @forelse($activeContacts as $contact)
                    <div class="py-2.5 flex items-center justify-between">
                        <div>
                            <div class="font-medium text-sm text-zinc-900 dark:text-white flex items-center gap-2">
                                {{ $contact->name }}
                                @if($contact->is_primary)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Utama</span>
                                @endif
                                @if($contact->title)
                                    <span class="text-xs text-zinc-400">({{ $contact->title }})</span>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500">{{ $contact->phone }} &bull; {{ $contact->email }}</div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button wire:click="editContact({{ $contact->id }})" class="p-1 text-zinc-400 hover:text-emerald-600"><span class="material-symbols-rounded text-sm">edit</span></button>
                            <button wire:click="deleteContact({{ $contact->id }})" class="p-1 text-zinc-400 hover:text-rose-600"><span class="material-symbols-rounded text-sm">delete</span></button>
                        </div>
                    </div>
                    @empty
                    <p class="text-xs text-zinc-400 py-3 text-center">Belum ada narahubung yang terdaftar.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Modal Katalog Item Pemasok -->
    @if($showCatalogModal)
    <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="card-surface rounded-2xl max-w-3xl w-full p-6 space-y-6 shadow-xl border border-zinc-200 dark:border-zinc-800">
            <div class="flex items-center justify-between pb-4 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Katalog Produk & Harga Pemasok</h3>
                    <p class="text-xs text-zinc-500">Pemasok: {{ $activeSupplierName }}</p>
                </div>
                <button wire:click="$set('showCatalogModal', false)" class="text-zinc-400 hover:text-zinc-600">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            @if(session()->has('catalog_message'))
                <div class="p-3 rounded-lg bg-emerald-50 text-emerald-800 text-xs">{{ session('catalog_message') }}</div>
            @endif

            <!-- Form Tambah/Ubah Item Katalog -->
            <form wire:submit="saveCatalogItem" class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 space-y-3 border border-zinc-200 dark:border-zinc-800">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <x-input-label for="catalogPurchasableType" value="Tipe Item *" />
                        <select id="catalogPurchasableType" wire:model.live="catalogPurchasableType" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                            <option value="product">Produk Ritel (M3)</option>
                            <option value="ingredient">Bahan Baku (M4)</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="catalogPurchasableId" value="Pilih Item Master *" />
                        <select id="catalogPurchasableId" wire:model="catalogPurchasableId" required class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
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
                        </select>
                        <x-input-error :messages="$errors->get('catalogPurchasableId')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <x-input-label for="catalogSupplierSku" value="SKU Pemasok" />
                        <input type="text" id="catalogSupplierSku" wire:model="catalogSupplierSku" placeholder="VND-1002" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="catalogPurchaseUom" value="Satuan Beli *" />
                        <input type="text" id="catalogPurchaseUom" wire:model="catalogPurchaseUom" required placeholder="DUS, CARTON" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="catalogConversionFactor" value="Faktor Konversi *" />
                        <input type="number" step="0.000001" min="0.000001" id="catalogConversionFactor" wire:model="catalogConversionFactor" required class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="catalogStandardPrice" value="Harga Standar (Rp) *" />
                        <input type="number" step="0.01" min="0" id="catalogStandardPrice" wire:model="catalogStandardPrice" required class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <x-input-label for="catalogLeadTimeDays" value="Lead Time (Hari)" />
                        <input type="number" min="0" id="catalogLeadTimeDays" wire:model="catalogLeadTimeDays" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="catalogMinOrderQty" value="Min Order (MOQ)" />
                        <input type="number" step="0.0001" min="0.0001" id="catalogMinOrderQty" wire:model="catalogMinOrderQty" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <x-input-label for="catalogOrderMultipleQty" value="Kelipatan Order" />
                        <input type="number" step="0.0001" min="0.0001" id="catalogOrderMultipleQty" wire:model="catalogOrderMultipleQty" class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm">
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="catalogIsPreferred" class="rounded text-emerald-600 focus:ring-emerald-500">
                        <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Pemasok Utama (Preferred)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="catalogIsActive" class="rounded text-emerald-600 focus:ring-emerald-500">
                        <span class="text-xs text-zinc-700 dark:text-zinc-300 font-medium">Aktif</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <x-secondary-button type="button" wire:click="resetCatalogForm">Reset</x-secondary-button>
                    <x-primary-button type="submit">Simpan Item Katalog</x-primary-button>
                </div>
            </form>

            <!-- Tabel Katalog Saat Ini -->
            <div class="space-y-2">
                <h4 class="text-xs font-bold uppercase text-zinc-400">Daftar Item Terdaftar</h4>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800 max-h-56 overflow-y-auto">
                    @forelse($activeCatalogItems as $item)
                    <div class="py-2.5 flex items-center justify-between text-xs">
                        <div>
                            <div class="font-medium text-zinc-900 dark:text-white flex items-center gap-1.5">
                                {{ $item->purchasable?->name ?? 'Item #' . $item->purchasable_id }}
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-zinc-100 dark:bg-zinc-800 uppercase">{{ $item->purchasable_type }}</span>
                                @if($item->is_preferred)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Preferred</span>
                                @endif
                            </div>
                            <div class="text-zinc-500 mt-0.5">
                                Satuan: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $item->purchase_uom }}</span> (x{{ (float) $item->uom_conversion_factor }}) &bull;
                                Harga: <span class="font-semibold text-zinc-700 dark:text-zinc-300">Rp {{ number_format((float) $item->standard_price, 2) }}</span> &bull;
                                Lead Time: {{ $item->lead_time_days }} hari
                            </div>
                        </div>
                        <button wire:click="deleteCatalogItem({{ $item->id }})" class="p-1 text-zinc-400 hover:text-rose-600">
                            <span class="material-symbols-rounded text-sm">delete</span>
                        </button>
                    </div>
                    @empty
                    <p class="text-xs text-zinc-400 py-3 text-center">Belum ada item dalam katalog pemasok ini.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
