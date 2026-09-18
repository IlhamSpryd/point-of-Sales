<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Ubah Peran</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Perbarui peran #{{ $role->id }}</p>
        </div>
        <a href="{{ route('roles.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full">
        <form action="{{ route('roles.update', $role->id) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <div>
                <x-form-label for="name">Nama Peran</x-form-label>
                <x-form-input type="text" id="name" name="name" 
                       value="{{ old('name', $role->name) }}" 
                       placeholder="Masukkan nama peran" required 
                       class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                @error('name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Perbarui Peran
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
