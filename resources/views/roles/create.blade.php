<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Tambah Peran <!-- Standarisasi bahasa UjiKom --></h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Tambahkan peran pengguna baru <!-- Standarisasi bahasa UjiKom --></p>
        </div>
        <a href="{{ route('roles.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Kembali <!-- Standarisasi bahasa UjiKom -->
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('roles.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <x-form-label for="name">Nama Peran <!-- Standarisasi bahasa UjiKom --></x-form-label>
                <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                        
                       placeholder="Masukkan nama peran (misal Administrator)" required class="@error('name') input-error @enderror" /> <!-- Standarisasi bahasa UjiKom -->
                @error('name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                {{-- submitting mencegah user klik tombol dua kali saat form sedang diproses server, supaya tidak ada data duplikat --}}
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded">save</span> Simpan Peran <!-- Standarisasi bahasa UjiKom -->
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
