<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Tambah Meja"
            description="Tambahkan meja baru dan terbitkan QR Code self-order untuk outlet aktif.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('tables.index') }}">Meja</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Tambah</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('tables.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Kembali
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
            <form action="{{ route('tables.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="table_name">Nama / Nomor Meja</x-form-label>
                        <x-form-input type="text" id="table_name" name="table_name" value="{{ old('table_name') }}" 
                               placeholder="Contoh: Meja 12 / Sofa VIP" required :class="$errors->has('table_name') ? 'input-error' : ''" />
                        @error('table_name')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="capacity">Kapasitas Kursi</x-form-label>
                        <x-form-input type="number" id="capacity" name="capacity" value="{{ old('capacity', 2) }}" min="1" required :class="$errors->has('capacity') ? 'input-error' : ''" />
                        @error('capacity')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="area">Area / Lokasi</x-form-label>
                        <div class="relative">
                            <select id="area" name="area" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('area') ? 'border-rose-300 focus:ring-rose-500' : '' }}">
                                <option value="Indoor" {{ old('area') === 'Indoor' ? 'selected' : '' }}>Indoor</option>
                                <option value="Outdoor" {{ old('area') === 'Outdoor' ? 'selected' : '' }}>Outdoor</option>
                                <option value="Rooftop" {{ old('area') === 'Rooftop' ? 'selected' : '' }}>Rooftop</option>
                                <option value="VIP Room" {{ old('area') === 'VIP Room' ? 'selected' : '' }}>VIP Room</option>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                            </div>
                        </div>
                        @error('area')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="operational_status">Status Operasional</x-form-label>
                        <div class="relative">
                            <select id="operational_status" name="operational_status" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('operational_status') ? 'border-rose-300 focus:ring-rose-500' : '' }}">
                                <option value="available" {{ old('operational_status') === 'available' ? 'selected' : '' }}>Available (Kosong)</option>
                                <option value="occupied" {{ old('operational_status') === 'occupied' ? 'selected' : '' }}>Occupied (Terisi)</option>
                                <option value="cleaning" {{ old('operational_status') === 'cleaning' ? 'selected' : '' }}>Cleaning (Pembersihan)</option>
                                <option value="reserved" {{ old('operational_status') === 'reserved' ? 'selected' : '' }}>Reserved (Dipesan)</option>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                            </div>
                        </div>
                        @error('operational_status')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                        <span class="text-sm font-medium text-apeiron-ink dark:text-zinc-200">Meja Aktif (Tersedia untuk digunakan)</span>
                    </label>
                </div>

                <div class="pt-4 border-t border-zinc-200/80 dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Simpan Meja
                        </span>
                        <span x-show="submitting" x-cloak>Menyimpan...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
