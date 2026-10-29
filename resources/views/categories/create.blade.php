<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="flex items-center justify-between mb-6" data-gsap="page-enter">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Tambah Kategori</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambah kategori produk baru</p>
        </div>
        <a href="{{ route('categories.index') }}" wire:navigate>
            <x-ui.button variant="outline" type="button" class="h-10">
                <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
            </x-ui.button>
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form action="{{ route('categories.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="category_code">Kode Kategori (Opsional)</x-form-label>
                    <x-form-input type="text" id="category_code" name="category_code" value="{{ old('category_code') }}"
                           placeholder="Contoh: BEV, FOOD (Dibuat otomatis jika kosong)" class="{{ $errors->has('category_code') ? 'input-error' : '' }}" />
                    @error('category_code')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="category_name">Nama Kategori</x-form-label>
                    <x-form-input type="text" id="category_name" name="category_name" value="{{ old('category_name') }}"
                           placeholder="Masukkan nama kategori" required class="{{ $errors->has('category_name') ? 'input-error' : '' }}" />
                    @error('category_name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="parent_id">Kategori Induk (Opsional)</x-form-label>
                    <select id="parent_id" name="parent_id" class="w-full rounded-xl border border-[#E9E9E7] bg-white px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 {{ $errors->has('parent_id') ? 'border-rose-500' : '' }}">
                        <option value="">-- Tanpa Induk (Root) --</option>
                        @foreach($parentCategories as $parent)
                            <option value="{{ $parent->id }}" {{ old('parent_id') == $parent->id ? 'selected' : '' }}>
                                {{ $parent->category_name }} ({{ $parent->category_code }})
                            </option>
                        @endforeach
                    </select>
                    @error('parent_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="sort_order">Urutan Tampilan</x-form-label>
                    <x-form-input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}"
                           min="0" class="{{ $errors->has('sort_order') ? 'input-error' : '' }}" />
                    @error('sort_order')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <x-form-label for="description">Deskripsi (Opsional)</x-form-label>
                <textarea id="description" name="description" rows="3"
                          class="w-full rounded-xl border border-[#E9E9E7] bg-white px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 {{ $errors->has('description') ? 'border-rose-500' : '' }}"
                          placeholder="Deskripsi kategori...">{{ old('description') }}</textarea>
                @error('description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}
                           class="rounded border-[#E9E9E7] text-primary-600 focus:ring-primary-500">
                    <span class="text-sm font-medium text-apeiron-ink">Aktif</span>
                </label>
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-ui.button type="submit" variant="primary" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    <span x-show="!submitting" class="flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Kategori
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
