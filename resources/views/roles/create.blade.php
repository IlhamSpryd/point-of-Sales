<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Tambah Peran Baru"
            description="Definisikan nama peran baru dan pilih hak akses modul sistem.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('roles.index') }}">Peran</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Tambah</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('roles.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Kembali
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
            <form action="{{ route('roles.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-8">
                @csrf

                <!-- Role Details Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="name">Nama Peran</x-form-label>
                        <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                               placeholder="Contoh: Manager" required class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                        @error('name')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <x-form-label for="description">Deskripsi Tugas (Opsional)</x-form-label>
                        <x-form-input type="text" id="description" name="description" value="{{ old('description') }}" 
                               placeholder="Penjelasan singkat tugas peran ini" class="{{ $errors->has('description') ? 'input-error' : '' }}" />
                        @error('description')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Permissions Row -->
                <div class="pt-4 border-t border-zinc-200/80 dark:border-zinc-800">
                    <div class="mb-4">
                        <h5 class="text-base font-semibold text-apeiron-ink dark:text-zinc-100">Hak Akses Modul (RBAC)</h5>
                        <p class="text-sm text-zinc-500">Pilih modul dan izin spesifik yang diberikan kepada peran ini.</p>
                    </div>

                    {{-- Disclaimer jujur: izin di bawah TERSIMPAN di database, tapi routing
                         masih memakai string nama role hardcoded. Sampai Gate-based authorization
                         dibangun, checkbox ini hanya untuk dokumentasi — bukan penegakan akses nyata.
                         @see §2.4 audit navigasi --}}
                    <div class="mb-5 flex items-start gap-3 rounded-2xl bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 px-4 py-3" role="alert">
                        <span class="material-symbols-rounded text-[20px] text-amber-600 dark:text-amber-400 shrink-0 mt-0.5">info</span>
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Izin granular belum ditegakkan secara otomatis</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400/80 mt-0.5">Izin yang dicentang di bawah akan tersimpan di database, namun akses modul saat ini masih ditentukan berdasarkan <strong>nama peran</strong> bawaan (Owner, Manager, Kasir, dll). Fitur penegakan otomatis izin per-modul sedang dalam pengembangan.</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8">
                        <!-- Kasir & Transaksi -->
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_access_pos]" value="1" {{ old('permissions.can_access_pos') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Akses Layar POS</span>
                                <span class="block text-xs text-zinc-500">Melakukan transaksi dan kasir.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_void_order]" value="1" {{ old('permissions.can_void_order') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Otoritas Void</span>
                                <span class="block text-xs text-zinc-500">Membatalkan pesanan terbayar.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_give_discount]" value="1" {{ old('permissions.can_give_discount') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Pemberian Diskon</span>
                                <span class="block text-xs text-zinc-500">Memberikan potongan harga di POS.</span>
                            </div>
                        </label>

                        <!-- Dapur & Manajemen -->
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_access_kds]" value="1" {{ old('permissions.can_access_kds') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Akses Dapur (KDS)</span>
                                <span class="block text-xs text-zinc-500">Mengelola Kitchen Display System.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_manage_menu]" value="1" {{ old('permissions.can_manage_menu') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Manajemen Menu</span>
                                <span class="block text-xs text-zinc-500">Mengubah menu dan kategori.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_view_reports]" value="1" {{ old('permissions.can_view_reports') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Laporan Penjualan</span>
                                <span class="block text-xs text-zinc-500">Melihat rekap dan laporan shift.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Status Row -->
                <div class="pt-4 border-t border-zinc-200/80 dark:border-zinc-800">
                    <div class="flex items-center">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <span class="text-sm font-medium text-apeiron-ink dark:text-zinc-200">Peran Aktif</span>
                        </label>
                    </div>
                    <p class="text-xs text-zinc-500 mt-1 ml-8">Peran yang dinonaktifkan tidak dapat ditugaskan ke karyawan baru.</p>
                </div>

                <div class="pt-6 border-t border-zinc-200/80 dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Simpan Peran
                        </span>
                        <span x-show="submitting" x-cloak>Menyimpan...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
