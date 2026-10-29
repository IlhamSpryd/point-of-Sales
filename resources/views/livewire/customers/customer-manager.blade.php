<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Data Induk Pelanggan (CRM)"
        description="Kelola direktori pelanggan individu (B2C), korporat (B2B), alamat pengiriman, dan kontak narahubung.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('customers.index') }}">Pelanggan</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Direktori Pelanggan</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.groups.index') }}" class="gap-2">
                <span class="material-symbols-rounded text-base">folder_shared</span>
                Grup Pelanggan
            </x-ui.button>
            @can('create', \App\Models\Customer::class)
            <x-ui.button variant="primary" wire:click="create" wire:loading.attr="disabled" class="gap-2">
                <span class="material-symbols-rounded text-base">person_add</span>
                Tambah Pelanggan
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nama, kode, perusahaan, telepon, email..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="typeFilter" class="w-auto text-sm">
                <option value="">Semua Tipe</option>
                <option value="INDIVIDUAL">Individu (B2C)</option>
                <option value="COMPANY">Perusahaan (B2B)</option>
            </x-ui.select>
            <x-ui.select wire:model.live="groupFilter" class="w-auto text-sm">
                <option value="">Semua Grup</option>
                @foreach($customerGroups as $grp)
                    <option value="{{ $grp->id }}">{{ $grp->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="inactive">Non-Aktif</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Kode & Nama</th>
                        <th class="px-6 py-4">Tipe & Grup</th>
                        <th class="px-6 py-4">Kontak</th>
                        <th class="px-6 py-4">Alamat Utama</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
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
                                    {{ $c->isCompany() ? 'B2B (Company)' : 'B2C (Individu)' }}
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
                                <span class="text-xs text-zinc-400 italic">Belum ada alamat</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <x-ui.badge variant="{{ $c->is_active ? 'success' : 'neutral' }}" size="sm">
                                {{ $c->is_active ? 'Aktif' : 'Non-Aktif' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button wire:click="openAddresses({{ $c->id }})" title="Kelola Alamat" class="p-1.5 rounded-lg text-zinc-500 hover:text-apeiron-primary hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">home_pin</span>
                                </button>
                                <button wire:click="openStatement({{ $c->id }})" title="Rekening Koran & Histori Mutasi" class="p-1.5 rounded-lg text-zinc-500 hover:text-apeiron-primary hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">receipt_long</span>
                                </button>
                                @if($c->isCompany())
                                <button wire:click="openContacts({{ $c->id }})" title="Kelola Narahubung B2B" class="p-1.5 rounded-lg text-zinc-500 hover:text-indigo-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">contacts</span>
                                </button>
                                @endif
                                @can('merge', $c)
                                <button wire:click="openMerge({{ $c->id }})" title="Gabungkan Pelanggan (Merge)" class="p-1.5 rounded-lg text-zinc-500 hover:text-amber-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">merge</span>
                                </button>
                                @endcan
                                @can('update', $c)
                                <button wire:click="edit({{ $c->id }})" title="Edit Pelanggan" class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                    <span class="material-symbols-rounded text-lg">edit</span>
                                </button>
                                @endcan
                                @can('delete', $c)
                                <button wire:click="delete({{ $c->id }})" wire:confirm="Apakah Anda yakin ingin menonaktifkan pelanggan ini?" title="Hapus Pelanggan" class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
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
                            Tidak ada data pelanggan yang cocok dengan kriteria pencarian.
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

    <!-- Modal 1: Form Pelanggan -->
    <x-ui.modal wire:model="showModal" maxWidth="2xl">
        <x-slot:title>
            {{ $isEditing ? 'Ubah Data Pelanggan' : 'Tambah Pelanggan Baru' }}
        </x-slot:title>

        <x-slot:content>
            <form id="customerForm" wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="customer_type">Tipe Pelanggan *</x-ui.label>
                        <x-ui.select id="customer_type" wire:model.live="customer_type">
                            <option value="INDIVIDUAL">Individu (B2C / Ritel)</option>
                            <option value="COMPANY">Perusahaan / Badan Usaha (B2B)</option>
                        </x-ui.select>
                        @error('customer_type') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="customer_group_id">Grup / Segmen Pelanggan</x-ui.label>
                        <x-ui.select id="customer_group_id" wire:model="customer_group_id">
                            <option value="">-- Tanpa Grup (Umum) --</option>
                            @foreach($customerGroups as $grp)
                                <option value="{{ $grp->id }}">{{ $grp->name }} (Diskon {{ $grp->default_discount_rate }}%)</option>
                            @endforeach
                        </x-ui.select>
                        @error('customer_group_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="{{ $customer_type === 'COMPANY' ? 'md:col-span-1' : 'md:col-span-2' }}">
                        <x-ui.label for="name">
                            {{ $customer_type === 'COMPANY' ? 'Nama Kontak / Penanggung Jawab *' : 'Nama Lengkap Pelanggan *' }}
                        </x-ui.label>
                        <x-ui.input type="text" id="name" wire:model="name" placeholder="Contoh: Budi Santoso" />
                        @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($customer_type === 'COMPANY')
                    <div>
                        <x-ui.label for="company_name">Nama Perusahaan / PT / CV</x-ui.label>
                        <x-ui.input type="text" id="company_name" wire:model="company_name" placeholder="Contoh: PT Sumber Makmur Abadi" />
                        @error('company_name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="tax_id">NPWP (Tax ID)</x-ui.label>
                        <x-ui.input type="text" id="tax_id" wire:model="tax_id" placeholder="Contoh: 01.234.567.8-901.000" />
                        @error('tax_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="business_registration_no">NIB / No. Registrasi Bisnis</x-ui.label>
                        <x-ui.input type="text" id="business_registration_no" wire:model="business_registration_no" placeholder="Nomor Izin Berusaha / SIUP" />
                        @error('business_registration_no') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    @endif

                    <div>
                        <x-ui.label for="phone">Nomor Telepon / WhatsApp</x-ui.label>
                        <x-ui.input type="text" id="phone" wire:model="phone" placeholder="Contoh: 08123456789" />
                        @error('phone') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="email">Alamat Email</x-ui.label>
                        <x-ui.input type="email" id="email" wire:model="email" placeholder="Contoh: customer@domain.com" />
                        @error('email') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($customer_type === 'INDIVIDUAL')
                    <div>
                        <x-ui.label for="date_of_birth">Tanggal Lahir (Untuk Promo Ulang Tahun)</x-ui.label>
                        <x-ui.input type="date" id="date_of_birth" wire:model="date_of_birth" />
                        @error('date_of_birth') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    @endif
                </div>

                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800 flex items-center gap-6">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_active" class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Status Akun Aktif</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_loyalty_eligible" class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Berhak Mengumpulkan Poin Loyalitas</span>
                    </label>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showModal', false)">
                    Batal
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="save" wire:loading.attr="disabled">
                    {{ $isEditing ? 'Simpan Perubahan' : 'Buat Pelanggan' }}
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 2: Kelola Alamat -->
    <x-ui.modal wire:model="showAddressModal" maxWidth="2xl">
        <x-slot:title>
            Kelola Alamat Pelanggan
        </x-slot:title>

        <x-slot:content>
            @if($activeCustomer)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Pelanggan: <strong class="text-zinc-800 dark:text-zinc-200">{{ $activeCustomer->name }}</strong> ({{ $activeCustomer->customer_code }})
                </div>

                @if (session()->has('address_message'))
                    <x-ui.alert type="success" size="sm" class="rounded-xl">
                        {{ session('address_message') }}
                    </x-ui.alert>
                @endif

                <!-- Daftar Alamat Eksisting -->
                <div class="space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-500 uppercase">Alamat Tersimpan</h4>
                    <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                        @forelse($activeAddresses as $addr)
                        <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ $addr->label ?: 'Alamat' }}</span>
                                    <x-ui.badge variant="neutral" size="xs">{{ $addr->address_type }}</x-ui.badge>
                                    @if($addr->is_default_shipping)
                                        <x-ui.badge variant="success" size="xs">Default Kirim</x-ui.badge>
                                    @endif
                                    @if($addr->is_default_billing)
                                        <x-ui.badge variant="info" size="xs">Default Tagih</x-ui.badge>
                                    @endif
                                </div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-1">{{ $addr->formatted_address }}</p>
                                @if($addr->recipient_name)
                                    <p class="text-[11px] text-zinc-500 mt-0.5">Penerima: {{ $addr->recipient_name }} ({{ $addr->phone }})</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="editAddress({{ $addr->id }})" class="p-1 text-zinc-400 hover:text-blue-600">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </button>
                                <button wire:click="deleteAddress({{ $addr->id }})" wire:confirm="Hapus alamat ini?" class="p-1 text-zinc-400 hover:text-rose-600">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 italic">Belum ada alamat tersimpan.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Form Tambah/Ubah Alamat -->
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $addressId ? 'Ubah Alamat' : 'Tambah Alamat Baru' }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="address_type">Tipe Alamat</x-ui.label>
                            <x-ui.select id="address_type" wire:model="address_type">
                                <option value="SHIPPING">Pengiriman (Shipping)</option>
                                <option value="BILLING">Penagihan (Billing)</option>
                                <option value="BOTH">Keduanya (Both)</option>
                            </x-ui.select>
                        </div>
                        <div>
                            <x-ui.label for="address_label">Label Alamat</x-ui.label>
                            <x-ui.input type="text" id="address_label" wire:model="address_label" placeholder="Contoh: Kantor Pusat, Gudang Utama" />
                        </div>
                        <div class="md:col-span-2">
                            <x-ui.label for="address_line1">Baris Alamat *</x-ui.label>
                            <textarea id="address_line1" wire:model="address_line1" rows="2" placeholder="Jalan, Nomor Bangunan, RT/RW, Kelurahan, Kecamatan" class="w-full text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 text-zinc-800 dark:text-zinc-200"></textarea>
                            @error('address_line1') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-ui.label for="city">Kota / Kabupaten</x-ui.label>
                            <x-ui.input type="text" id="city" wire:model="city" placeholder="Contoh: Surabaya" />
                        </div>
                        <div>
                            <x-ui.label for="province">Provinsi</x-ui.label>
                            <x-ui.input type="text" id="province" wire:model="province" placeholder="Contoh: Jawa Timur" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4 pt-2">
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="is_default_shipping" class="rounded border-zinc-300 text-apeiron-primary">
                            Default Pengiriman
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="is_default_billing" class="rounded border-zinc-300 text-apeiron-primary">
                            Default Penagihan
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        @if($addressId)
                            <x-ui.button type="button" variant="secondary" size="xs" wire:click="addAddress">Batal Ubah</x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="primary" size="xs" wire:click="saveAddress">
                            {{ $addressId ? 'Perbarui Alamat' : 'Simpan Alamat' }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="$set('showAddressModal', false)">
                    Tutup
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 3: Kelola Kontak PIC B2B -->
    <x-ui.modal wire:model="showContactModal" maxWidth="xl">
        <x-slot:title>
            Kontak Narahubung B2B
        </x-slot:title>

        <x-slot:content>
            @if($activeCustomer)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Pelanggan: <strong class="text-zinc-800 dark:text-zinc-200">{{ $activeCustomer->name }}</strong> ({{ $activeCustomer->company_name }})
                </div>

                @if (session()->has('contact_message'))
                    <x-ui.alert type="success" size="sm" class="rounded-xl">
                        {{ session('contact_message') }}
                    </x-ui.alert>
                @endif

                <!-- Daftar Kontak -->
                <div class="space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-500 uppercase">Daftar PIC / Narahubung</h4>
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
                                        <x-ui.badge variant="success" size="xs">Utama</x-ui.badge>
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
                                <button wire:click="deleteContact({{ $contact->id }})" wire:confirm="Hapus kontak narahubung ini?" class="p-1 text-zinc-400 hover:text-rose-600">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </button>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-zinc-400 italic">Belum ada kontak PIC tersimpan.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Form Kontak -->
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                    <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $contactId ? 'Ubah Kontak' : 'Tambah Kontak Baru' }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <x-ui.label for="contact_name">Nama PIC *</x-ui.label>
                            <x-ui.input type="text" id="contact_name" wire:model="contact_name" placeholder="Contoh: Rina Finance" />
                            @error('contact_name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-ui.label for="contact_role_title">Jabatan / Role</x-ui.label>
                            <x-ui.input type="text" id="contact_role_title" wire:model="contact_role_title" placeholder="Contoh: Purchasing, Finance, Logistik" />
                        </div>
                        <div>
                            <x-ui.label for="contact_phone">Nomor Telepon</x-ui.label>
                            <x-ui.input type="text" id="contact_phone" wire:model="contact_phone" placeholder="Contoh: 08129876543" />
                        </div>
                        <div>
                            <x-ui.label for="contact_email">Email</x-ui.label>
                            <x-ui.input type="email" id="contact_email" wire:model="contact_email" placeholder="Contoh: finance@company.com" />
                        </div>
                    </div>

                    <div class="pt-2">
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="contact_is_primary" class="rounded border-zinc-300 text-apeiron-primary">
                            Jadikan Kontak Utama (Primary PIC)
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        @if($contactId)
                            <x-ui.button type="button" variant="secondary" size="xs" wire:click="addContact">Batal Ubah</x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="primary" size="xs" wire:click="saveContact">
                            {{ $contactId ? 'Perbarui Kontak' : 'Simpan Kontak' }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="$set('showContactModal', false)">
                    Tutup
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 4: Customer Merge -->
    <x-ui.modal wire:model="showMergeModal" maxWidth="lg">
        <x-slot:title>
            Gabungkan Pelanggan (Merge)
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                @if (session()->has('merge_error'))
                    <x-ui.alert type="danger" size="sm" class="rounded-xl">
                        {{ session('merge_error') }}
                    </x-ui.alert>
                @endif

                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-xs leading-relaxed">
                    <strong>Peringatan Penting:</strong> Pelanggan sumber akan dinonaktifkan permanen (soft-deleted). Seluruh riwayat transaksi (Order, Alamat, Kontak, dan Poin Loyalitas) akan dipindahkan ke pelanggan target terpilih secara atomik.
                </div>

                <div>
                    <x-ui.label for="mergeTargetId">Pelanggan Target (Tujuan Penggabungan) *</x-ui.label>
                    <x-ui.select id="mergeTargetId" wire:model="mergeTargetId">
                        <option value="">-- Pilih Pelanggan Target --</option>
                        @foreach($mergeTargetCandidates as $tgt)
                            <option value="{{ $tgt->id }}">{{ $tgt->name }} ({{ $tgt->customer_code }}) - {{ $tgt->phone ?: $tgt->email }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('mergeTargetId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="mergeReason">Alasan Penggabungan *</x-ui.label>
                    <textarea id="mergeReason" wire:model="mergeReason" rows="3" placeholder="Contoh: Akun duplikat terdaftar dengan nomor HP lama dan baru milik orang yang sama." class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5 text-zinc-800 dark:text-zinc-200"></textarea>
                    @error('mergeReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showMergeModal', false)">
                    Batal
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="executeMerge" wire:loading.attr="disabled">
                    Konfirmasi & Gabungkan
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 5: Statement & History Modal -->
    <x-ui.modal wire:model="showStatementModal" maxWidth="4xl">
        <x-slot:title>
            Rekening Koran Pelanggan (Customer Statement)
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-5">
                <div class="text-xs text-zinc-500 -mt-2">
                    Histori mutasi piutang usaha, faktur, pembayaran, dan saldo berjalan.
                </div>

                <!-- Date Filter Bar & Export -->
                <div class="flex flex-wrap items-center justify-between gap-3 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-[#E5E7EB] dark:border-zinc-800">
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <x-ui.label for="statementStartDate">Dari Tanggal</x-ui.label>
                            <x-ui.input type="date" id="statementStartDate" wire:model="statementStartDate" class="text-xs" />
                        </div>
                        <div>
                            <x-ui.label for="statementEndDate">Sampai Tanggal</x-ui.label>
                            <x-ui.input type="date" id="statementEndDate" wire:model="statementEndDate" class="text-xs" />
                        </div>
                        <div class="self-end">
                            <x-ui.button variant="secondary" size="sm" wire:click="loadStatementPreview" type="button" class="gap-1.5">
                                <span class="material-symbols-rounded text-sm">refresh</span>
                                Muat Ulang
                            </x-ui.button>
                        </div>
                    </div>
                    <div class="self-end">
                        <x-ui.button variant="primary" size="sm" wire:click="exportStatement" type="button" class="gap-1.5 shadow-xs">
                            <span class="material-symbols-rounded text-sm">download</span>
                            Ekspor Excel (.xlsx)
                        </x-ui.button>
                    </div>
                </div>

                <!-- Balance Summary Cards -->
                @if($statementPreview)
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 rounded-xl bg-zinc-100/70 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-800">
                        <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Saldo Awal Periode</div>
                        <div class="text-xl font-bold font-mono text-apeiron-ink dark:text-white mt-1">
                            Rp {{ number_format((float) $statementPreview['opening_balance'], 2, ',', '.') }}
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800">
                        <div class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Saldo Akhir (Sisa Piutang)</div>
                        <div class="text-xl font-bold font-mono text-emerald-800 dark:text-emerald-300 mt-1">
                            Rp {{ number_format((float) $statementPreview['closing_balance'], 2, ',', '.') }}
                        </div>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="rounded-xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden max-h-80 overflow-y-auto">
                    <table class="w-full text-left text-xs text-zinc-600 dark:text-zinc-300">
                        <thead class="bg-zinc-50 dark:bg-zinc-900/80 text-[11px] font-semibold uppercase text-zinc-500 sticky top-0 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="px-3 py-2.5">Tanggal</th>
                                <th class="px-3 py-2.5">Tipe Mutasi</th>
                                <th class="px-3 py-2.5">No. Referensi</th>
                                <th class="px-3 py-2.5">Keterangan</th>
                                <th class="px-3 py-2.5 text-right">Debet</th>
                                <th class="px-3 py-2.5 text-right">Kredit</th>
                                <th class="px-3 py-2.5 text-right">Saldo Berjalan</th>
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
                                <td class="px-3 py-2 text-right font-mono {{ (float) $tx['debit_amount'] > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-zinc-400' }}">
                                    {{ (float) $tx['debit_amount'] > 0 ? 'Rp '.number_format((float) $tx['debit_amount'], 2, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono {{ (float) $tx['credit_amount'] > 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-zinc-400' }}">
                                    {{ (float) $tx['credit_amount'] > 0 ? 'Rp '.number_format((float) $tx['credit_amount'], 2, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono font-bold text-zinc-800 dark:text-zinc-200">
                                    Rp {{ number_format((float) $tx['running_balance'], 2, ',', '.') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-zinc-400 italic">
                                    Tidak ada mutasi piutang dalam periode tanggal ini.
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
                    Tutup
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
