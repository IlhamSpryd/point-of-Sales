<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">Tambah Kategori</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambah kategori produk baru</p>
        </div>
        <a href="{{ route('categories.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-yovel-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form action="{{ route('categories.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <x-form-label for="category_name">Nama Kategori</x-form-label>
                <x-form-input type="text" id="category_name" name="category_name" value="{{ old('category_name') }}" 
                       placeholder="Masukkan nama kategori" required class="{{ $errors->has('category_name') ? 'input-error' : '' }}" />
                @error('category_name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Kategori
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
