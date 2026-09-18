<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Ubah Pengguna</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Perbarui detail untuk {{ $user->name }}</p>
        </div>
        <a href="{{ route('users.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full">
        <form action="{{ route('users.update', $user->id) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <!-- Personal Info Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Nama Lengkap</x-form-label>
                    <x-form-input type="text" id="name" name="name" 
                           value="{{ old('name', $user->name) }}" 
                           placeholder="Masukkan nama lengkap" required  
                           class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                    @error('name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>

                <div>
                    <x-form-label for="email">Alamat Email</x-form-label>
                    <x-form-input type="email" id="email" name="email" 
                           value="{{ old('email', $user->email) }}" 
                           placeholder="example@domain.com" required 
                           class="{{ $errors->has('email') ? 'input-error' : '' }}" />
                    @error('email')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>
            </div>

            <!-- Role Row -->
            <div>
                <x-form-label for="role_id">Pilih Peran</x-form-label>
                <div class="relative">
                    <select id="role_id" name="role_id" 
                            class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-[#37352F] appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all duration-200 shadow-sm {{ $errors->has('role_id') ? 'input-error' : '' }}" required>
                        <option value="">Pilih Peran</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                        <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                    </div>
                </div>
                @error('role_id')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password Row (Optional) -->
            <div class="pt-4 border-t border-[#E9E9E7]">
                <div class="mb-4">
                    <h5 class="text-sm font-semibold text-[#37352F]">Perbarui Kata Sandi</h5>
                    <p class="text-xs text-[#787774] mt-1">Biarkan kosong jika Anda tidak ingin mengubah kata sandi.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="password">Kata Sandi Baru</x-form-label>
                        <x-form-input type="password" id="password" name="password" 
                               placeholder="••••••••" autocomplete="new-password" class="{{ $errors->has('password') ? 'input-error' : '' }}" />
                        @error('password')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                    </div>

                    <div>
                        <x-form-label for="password_confirmation">Konfirmasi Kata Sandi Baru</x-form-label>
                        <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                               placeholder="••••••••" autocomplete="new-password" />
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Perbarui Pengguna
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
