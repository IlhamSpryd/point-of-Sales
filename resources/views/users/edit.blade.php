<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Edit User</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Update details for {{ $user->name }}</p>
        </div>
        <a href="{{ route('users.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Back
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('users.update', $user->id) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <!-- Personal Info Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Full Name</x-form-label>
                    {{-- Menggunakan <x-form-input> alih-alih <input> manual, supaya style Create & Edit selalu seragam dan mudah dirawat dari satu sumber (komponen). --}}
                    <x-form-input type="text" id="name" name="name" 
                           value="{{ old('name', $user->name) }}" 
                           placeholder="Enter full name" required 
                           class="@error('name') input-error @enderror" />
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="email">Email Address</x-form-label>
                    {{-- Menggunakan <x-form-input> alih-alih <input> manual, supaya style Create & Edit selalu seragam dan mudah dirawat dari satu sumber (komponen). --}}
                    <x-form-input type="email" id="email" name="email" 
                           value="{{ old('email', $user->email) }}" 
                           placeholder="example@domain.com" required 
                           class="@error('email') input-error @enderror" />
                    @error('email')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Role Row -->
            <div>
                <x-form-label for="role_id">Assign Role</x-form-label>
                <div class="relative">
                    <select id="role_id" name="role_id" 
                            class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 appearance-none focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('role_id') input-error @enderror" required>
                        <option value="">Select Role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
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

            <!-- Password Row (Optional for UX) -->
            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <div class="mb-4">
                    <h5 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Update Password</h5>
                    <p class="text-xs text-zinc-500 mt-1">Leave blank if you don't want to change the password.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="password">New Password</x-form-label>
                        <x-form-input type="password" id="password" name="password" 
                                
                               placeholder="••••••••" autocomplete="new-password" class="@error('password') input-error @enderror" />
                        @error('password')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="password_confirmation">Confirm New Password</x-form-label>
                        <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                                
                               placeholder="••••••••" autocomplete="new-password" />
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                {{-- submitting mencegah user klik tombol dua kali saat form sedang diproses server, supaya tidak ada data duplikat --}}
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded">save</span> Update User
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
