<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Daftarkan Perangkat Baru</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Registrasikan terminal fisik kasir (POS), KDS, atau tablet sebelum dilakukan aktivasi/pairing</p>
        </div>
        <a href="{{ route('devices.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form action="{{ route('devices.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="device_name">Nama Perangkat</x-form-label>
                    <x-form-input type="text" id="device_name" name="device_name" value="{{ old('device_name') }}" 
                           placeholder="Contoh: Kasir Utama Lantai 1 / iPad Bar" required :class="$errors->has('device_name') ? 'input-error' : ''" />
                    @error('device_name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="store_id">Penempatan Cabang (Store)</x-form-label>
                    <div class="relative">
                        <select id="store_id" name="store_id" class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm appearance-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 outline-none shadow-sm {{ $errors->has('store_id') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                            <option value="">Pilih Cabang</option>
                            @foreach($stores as $st)
                                <option value="{{ $st->id }}" {{ old('store_id') == $st->id ? 'selected' : '' }}>
                                    {{ $st->name }} ({{ $st->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('store_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="device_type">Tipe Perangkat Fisik</x-form-label>
                    <div class="relative">
                        <select id="device_type" name="device_type" class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm appearance-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 outline-none shadow-sm {{ $errors->has('device_type') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                            <option value="pos_terminal" {{ old('device_type') === 'pos_terminal' ? 'selected' : '' }}>Terminal POS Kasir</option>
                            <option value="kds_display" {{ old('device_type') === 'kds_display' ? 'selected' : '' }}>Kitchen Display System (KDS)</option>
                            <option value="waiter_tablet" {{ old('device_type') === 'waiter_tablet' ? 'selected' : '' }}>Tablet Pelayan / Order Taker</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('device_type')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="device_code">Kode Perangkat (Opsional)</x-form-label>
                    <x-form-input type="text" id="device_code" name="device_code" value="{{ old('device_code') }}" 
                           placeholder="Otomatis jika dikosongkan (DEV-001)" :class="$errors->has('device_code') ? 'input-error' : ''" />
                    @error('device_code')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="p-4 rounded-xl bg-[#F7F7F5] border border-[#E9E9E7] text-xs text-[#787774] flex items-start gap-3">
                <span class="material-symbols-rounded text-lg text-[#37352F] shrink-0 mt-0.5">info</span>
                <div>
                    <p class="font-semibold text-apeiron-ink">Informasi Aktivasi Perangkat</p>
                    <p class="mt-0.5">Setelah pendaftaran disimpan, sistem akan menerbitkan token aktivasi 12-karakter. Masukkan token tersebut pada layar login terminal fisik kasir untuk mengikat perangkat ini secara aman ke cabang terpilih.</p>
                </div>
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting" class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan & Terbitkan Token
                    </span>
                    <span x-show="submitting" x-cloak>Mendaftarkan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
