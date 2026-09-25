<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">Edit Peran</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Ubah hak akses dan konfigurasi peran</p>
        </div>
        <a href="{{ route('roles.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-yovel-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form action="{{ route('roles.update', $role->id) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-8">
            @csrf
            @method('PUT')

            <!-- Role Details Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Nama Peran</x-form-label>
                    <x-form-input type="text" id="name" name="name" value="{{ old('name', $role->name) }}" 
                           placeholder="Contoh: Manager" required class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
                
                <div>
                    <x-form-label for="description">Deskripsi Tugas (Opsional)</x-form-label>
                    <x-form-input type="text" id="description" name="description" value="{{ old('description', $role->description) }}" 
                           placeholder="Penjelasan singkat tugas peran ini" class="{{ $errors->has('description') ? 'input-error' : '' }}" />
                    @error('description')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Permissions Row -->
            <div class="pt-4 border-t border-[#E9E9E7]">
                <div class="mb-4">
                    <h5 class="text-base font-semibold text-yovel-ink">Hak Akses Modul (RBAC)</h5>
                    <p class="text-sm text-[#787774]">Pilih modul dan izin spesifik yang diberikan kepada peran ini.</p>
                </div>

                {{-- Disclaimer jujur — @see §2.4 audit navigasi --}}
                <div class="mb-5 flex items-start gap-3 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3" role="alert">
                    <span class="material-symbols-rounded text-[20px] text-amber-600 shrink-0 mt-0.5">info</span>
                    <div>
                        <p class="text-sm font-medium text-amber-800">Izin granular belum ditegakkan secara otomatis</p>
                        <p class="text-xs text-amber-700 mt-0.5">Izin yang dicentang di bawah akan tersimpan di database, namun akses modul saat ini masih ditentukan berdasarkan <strong>nama peran</strong> bawaan (Owner, Manager, Kasir, dll). Fitur penegakan otomatis izin per-modul sedang dalam pengembangan.</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8">
                    @php $perms = $role->permissions ?? []; @endphp
                    <!-- Kasir & Transaksi -->
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_access_pos]" value="1" {{ old('permissions.can_access_pos', $perms['can_access_pos'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Akses Layar POS</span>
                            <span class="block text-xs text-[#787774]">Melakukan transaksi dan kasir.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_void_order]" value="1" {{ old('permissions.can_void_order', $perms['can_void_order'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Otoritas Void</span>
                            <span class="block text-xs text-[#787774]">Membatalkan pesanan terbayar.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_give_discount]" value="1" {{ old('permissions.can_give_discount', $perms['can_give_discount'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Pemberian Diskon</span>
                            <span class="block text-xs text-[#787774]">Memberikan potongan harga di POS.</span>
                        </div>
                    </label>

                    <!-- Dapur & Manajemen -->
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_access_kds]" value="1" {{ old('permissions.can_access_kds', $perms['can_access_kds'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Akses Dapur (KDS)</span>
                            <span class="block text-xs text-[#787774]">Mengelola Kitchen Display System.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_manage_menu]" value="1" {{ old('permissions.can_manage_menu', $perms['can_manage_menu'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Manajemen Menu</span>
                            <span class="block text-xs text-[#787774]">Mengubah menu dan kategori.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_view_reports]" value="1" {{ old('permissions.can_view_reports', $perms['can_view_reports'] ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-yovel-ink group-hover:text-yovel-ink transition-colors">Laporan Penjualan</span>
                            <span class="block text-xs text-[#787774]">Melihat rekap dan laporan shift.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Status Row -->
            <div class="pt-4 border-t border-[#E9E9E7]">
                <div class="flex items-center">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $role->is_active) ? 'checked' : '' }} class="w-5 h-5 rounded border-[#E9E9E7] text-yovel-ink focus:ring-[#37352F] transition-colors">
                        <span class="text-sm font-medium text-yovel-ink">Peran Aktif</span>
                    </label>
                </div>
                <p class="text-xs text-[#787774] mt-1 ml-8">Peran yang dinonaktifkan tidak dapat ditugaskan ke karyawan baru.</p>
            </div>

            <div class="pt-6 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Perubahan
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
