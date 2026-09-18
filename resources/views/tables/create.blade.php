<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Tambah Meja</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambah meja & QR Code baru</p>
        </div>
        <a href="{{ route('tables.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full">
        <form action="{{ route('tables.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <x-form-label for="table_number">Nomor Meja</x-form-label>
                <x-form-input type="text" id="table_number" name="table_number" value="{{ old('table_number') }}" 
                       placeholder="Contoh: 12" required :class="$errors->has('table_number') ? 'input-error' : ''" />
                @error('table_number')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>
            
            <div>
                <x-form-label for="status">Status</x-form-label>
                <div class="relative">
                    <select id="status" name="status" class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-[#37352F] text-sm appearance-none focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all duration-200 outline-none shadow-sm {{ $errors->has('status') ? 'border-rose-300 focus:ring-rose-500' : '' }}">
                        <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                        <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                    </div>
                </div>
                @error('status')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Meja
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
