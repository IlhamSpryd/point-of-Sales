<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Tambah Peran</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambahkan peran pengguna baru beserta hak aksesnya</p>
        </div>
        <a href="{{ route('roles.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full">
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
            <div class="pt-4 border-t border-[#E9E9E7]">
                <div class="mb-4">
                    <h5 class="text-base font-semibold text-[#37352F]">Hak Akses Modul (RBAC)</h5>
                    <p class="text-sm text-[#787774]">Pilih modul dan izin spesifik yang diberikan kepada peran ini.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8">
                    <!-- Kasir & Transaksi -->
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_access_pos]" value="1" {{ old('permissions.can_access_pos') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Akses Layar POS</span>
                            <span class="block text-xs text-[#787774]">Melakukan transaksi dan kasir.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_void_order]" value="1" {{ old('permissions.can_void_order') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Otoritas Void</span>
                            <span class="block text-xs text-[#787774]">Membatalkan pesanan terbayar.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_give_discount]" value="1" {{ old('permissions.can_give_discount') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Pemberian Diskon</span>
                            <span class="block text-xs text-[#787774]">Memberikan potongan harga di POS.</span>
                        </div>
                    </label>

                    <!-- Dapur & Manajemen -->
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_access_kds]" value="1" {{ old('permissions.can_access_kds') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Akses Dapur (KDS)</span>
                            <span class="block text-xs text-[#787774]">Mengelola Kitchen Display System.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_manage_menu]" value="1" {{ old('permissions.can_manage_menu') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Manajemen Menu</span>
                            <span class="block text-xs text-[#787774]">Mengubah menu dan kategori.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" name="permissions[can_view_reports]" value="1" {{ old('permissions.can_view_reports') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <div>
                            <span class="block text-sm font-medium text-[#37352F] group-hover:text-black transition-colors">Laporan Penjualan</span>
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
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-5 h-5 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <span class="text-sm font-medium text-[#37352F]">Peran Aktif</span>
                    </label>
                </div>
                <p class="text-xs text-[#787774] mt-1 ml-8">Peran yang dinonaktifkan tidak dapat ditugaskan ke karyawan baru.</p>
            </div>

            <div class="pt-6 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Peran
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
