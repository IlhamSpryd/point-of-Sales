<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Tambah Pengguna <!-- Standarisasi bahasa UjiKom --></h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Tambahkan pengguna baru ke sistem <!-- Standarisasi bahasa UjiKom --></p>
        </div>
        <a href="{{ route('users.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Kembali <!-- Standarisasi bahasa UjiKom -->
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('users.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <!-- Personal Info Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Nama Lengkap <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                            
                           placeholder="Masukkan nama lengkap" required autocomplete="name" class="@error('name') input-error @enderror" /> <!-- Standarisasi bahasa UjiKom -->
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="email">Alamat Email <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    <x-form-input type="email" id="email" name="email" value="{{ old('email') }}" 
                            
                           placeholder="example@domain.com" required autocomplete="username" class="@error('email') input-error @enderror" />
                    @error('email')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Role Row -->
            <div>
                <x-form-label for="role_id">Pilih Peran <!-- Standarisasi bahasa UjiKom --></x-form-label>
                <div class="relative">
                    <select id="role_id" name="role_id" 
                            class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 appearance-none focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('role_id') input-error @enderror" required>
                        <option value="">Pilih Peran <!-- Standarisasi bahasa UjiKom --></option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" {{ old('role_id') == $role->id ? 'selected' : '' }}>
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-500">
                        <span class="material-symbols-rounded text-xs">keyboard_arrow_down</span>
                    </div>
                </div>
                @error('role_id')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <div>
                    <x-form-label for="password">Kata Sandi <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    <x-form-input type="password" id="password" name="password" 
                            
                           placeholder="••••••••" required autocomplete="new-password" class="@error('password') input-error @enderror" />
                    @error('password')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="password_confirmation">Konfirmasi Kata Sandi <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                            
                           placeholder="••••••••" required autocomplete="new-password" />
                </div>
            </div>

            <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                {{-- submitting mencegah user klik tombol dua kali saat form sedang diproses server, supaya tidak ada data duplikat --}}
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded">save</span> Simpan Pengguna <!-- Standarisasi bahasa UjiKom -->
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
