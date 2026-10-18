<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Ubah Cabang: {{ $store->name }}</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Perbarui informasi cabang dan pengaturan operasional</p>
        </div>
        <a href="{{ route('stores.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form action="{{ route('stores.update', $store) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Nama Cabang / Toko</x-form-label>
                    <x-form-input type="text" id="name" name="name" value="{{ old('name', $store->name) }}" 
                           required :class="$errors->has('name') ? 'input-error' : ''" />
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="code">Kode Cabang</x-form-label>
                    <x-form-input type="text" id="code" name="code" value="{{ $store->code }}" 
                           disabled class="bg-[#F7F7F5] cursor-not-allowed opacity-80" />
                    <p class="text-xs text-[#787774] mt-1">Kode cabang bersifat permanen untuk integritas riwayat transaksi.</p>
                </div>

                <div>
                    <x-form-label for="timezone">Zona Waktu Operasional</x-form-label>
                    <div class="relative">
                        <select id="timezone" name="timezone" class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm appearance-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 outline-none shadow-sm {{ $errors->has('timezone') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                            <option value="Asia/Jakarta" {{ old('timezone', $store->timezone) === 'Asia/Jakarta' ? 'selected' : '' }}>WIB — Waktu Indonesia Barat (Asia/Jakarta)</option>
                            <option value="Asia/Makassar" {{ old('timezone', $store->timezone) === 'Asia/Makassar' ? 'selected' : '' }}>WITA — Waktu Indonesia Tengah (Asia/Makassar)</option>
                            <option value="Asia/Jayapura" {{ old('timezone', $store->timezone) === 'Asia/Jayapura' ? 'selected' : '' }}>WIT — Waktu Indonesia Timur (Asia/Jayapura)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('timezone')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="phone">Nomor Telepon Kontak</x-form-label>
                    <x-form-input type="text" id="phone" name="phone" value="{{ old('phone', $store->phone) }}" 
                           :class="$errors->has('phone') ? 'input-error' : ''" />
                    @error('phone')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <x-form-label for="address">Alamat Lengkap Cabang</x-form-label>
                    <textarea id="address" name="address" rows="3" class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 outline-none shadow-sm {{ $errors->has('address') ? 'border-rose-300 focus:ring-rose-500' : '' }}">{{ old('address', $store->address) }}</textarea>
                    @error('address')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $store->is_active) ? 'checked' : '' }} class="w-5 h-5 rounded border-[#E9E9E7] text-apeiron-ink focus:ring-[#37352F] transition-colors">
                    <span class="text-sm font-medium text-apeiron-ink">Cabang Aktif (Dapat digunakan untuk operasional & transaksi kasir)</span>
                </label>
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting" class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px]">save</span> Perbarui Cabang
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
