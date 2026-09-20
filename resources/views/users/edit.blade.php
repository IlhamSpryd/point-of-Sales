<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Edit Pengguna</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Ubah data pengguna sistem</p>
        </div>
        <a href="{{ route('users.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
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
                    <x-form-input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" 
                           placeholder="Masukkan nama lengkap" required autocomplete="name" class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                    @error('name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="email">Alamat Email</x-form-label>
                    <x-form-input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" 
                           placeholder="example@domain.com" required autocomplete="username" class="{{ $errors->has('email') ? 'input-error' : '' }}" />
                    @error('email')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
                
                <div>
                    <x-form-label for="phone_number">Nomor HP / WhatsApp</x-form-label>
                    <x-form-input type="text" id="phone_number" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" 
                           placeholder="08123456789" class="{{ $errors->has('phone_number') ? 'input-error' : '' }}" />
                    @error('phone_number')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="join_date">Tanggal Bergabung</x-form-label>
                    <x-form-input type="date" id="join_date" name="join_date" value="{{ old('join_date', $user->join_date?->format('Y-m-d')) }}" 
                           class="{{ $errors->has('join_date') ? 'input-error' : '' }}" />
                    @error('join_date')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Role & Status Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-[#E9E9E7]">
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

                <div class="flex items-center pt-8">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }} class="w-5 h-5 rounded border-[#E9E9E7] text-[#37352F] focus:ring-[#37352F] transition-colors">
                        <span class="text-sm font-medium text-[#37352F]">Karyawan Aktif</span>
                    </label>
                </div>
            </div>

            <!-- Security Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4 border-t border-[#E9E9E7]">
                <div>
                    <x-form-label for="pin_code">PIN Kasir Baru (Opsional)</x-form-label>
                    <x-form-input type="password" id="pin_code" name="pin_code" 
                           placeholder="Kosongkan jika tidak diubah" class="{{ $errors->has('pin_code') ? 'input-error' : '' }}" />
                    @error('pin_code')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="password">Kata Sandi Web Baru (Opsional)</x-form-label>
                    <x-form-input type="password" id="password" name="password" 
                           placeholder="Kosongkan jika tidak diubah" autocomplete="new-password" class="{{ $errors->has('password') ? 'input-error' : '' }}" />
                    @error('password')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="password_confirmation">Konfirmasi Kata Sandi</x-form-label>
                    <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                           placeholder="••••••••" autocomplete="new-password" />
                </div>
            </div>

            <div class="pt-6 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Perubahan
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
