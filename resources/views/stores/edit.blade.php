<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Ubah Cabang: {{ $store->name }}"
            description="Perbarui informasi cabang, zona waktu, dan pengaturan status operasional.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('stores.index') }}">Cabang</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Edit</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('stores.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Kembali
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
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
                               disabled class="bg-zinc-100 dark:bg-zinc-800 cursor-not-allowed opacity-80" />
                        <p class="text-xs text-zinc-500 mt-1">Kode cabang bersifat permanen untuk integritas riwayat transaksi.</p>
                    </div>

                    <div>
                        <x-form-label for="timezone">Zona Waktu Operasional</x-form-label>
                        <div class="relative">
                            <select id="timezone" name="timezone" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('timezone') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                                <option value="Asia/Jakarta" {{ old('timezone', $store->timezone) === 'Asia/Jakarta' ? 'selected' : '' }}>WIB — Waktu Indonesia Barat (Asia/Jakarta)</option>
                                <option value="Asia/Makassar" {{ old('timezone', $store->timezone) === 'Asia/Makassar' ? 'selected' : '' }}>WITA — Waktu Indonesia Tengah (Asia/Makassar)</option>
                                <option value="Asia/Jayapura" {{ old('timezone', $store->timezone) === 'Asia/Jayapura' ? 'selected' : '' }}>WIT — Waktu Indonesia Timur (Asia/Jayapura)</option>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
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
                        <textarea id="address" name="address" rows="3" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('address') ? 'border-rose-300 focus:ring-rose-500' : '' }}">{{ old('address', $store->address) }}</textarea>
                        @error('address')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $store->is_active) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                        <span class="text-sm font-medium text-apeiron-ink dark:text-zinc-200">Cabang Aktif (Dapat digunakan untuk operasional & transaksi kasir)</span>
                    </label>
                </div>

                <div class="pt-4 border-t border-[#E5E7EB] dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Perbarui Cabang
                        </span>
                        <span x-show="submitting" x-cloak>Menyimpan...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
