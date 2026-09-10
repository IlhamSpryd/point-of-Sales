<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Create User</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Add a new user to the system</p>
        </div>
        <a href="{{ route('users.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Back
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('users.store') }}" method="POST" class="p-6 md:p-8 space-y-6">
            @csrf

            <!-- Personal Info Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Full Name</x-form-label>
                    <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                            
                           placeholder="Enter full name" required autocomplete="name" class="@error('name') input-error @enderror" />
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="email">Email Address</x-form-label>
                    <x-form-input type="email" id="email" name="email" value="{{ old('email') }}" 
                            
                           placeholder="example@domain.com" required autocomplete="username" class="@error('email') input-error @enderror" />
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
                    <x-form-label for="password">Password</x-form-label>
                    <x-form-input type="password" id="password" name="password" 
                            
                           placeholder="••••••••" required autocomplete="new-password" class="@error('password') input-error @enderror" />
                    @error('password')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="password_confirmation">Confirm Password</x-form-label>
                    <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                            
                           placeholder="••••••••" required autocomplete="new-password" />
                </div>
            </div>

            <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                <x-button type="submit" variant="primary">
                    <span class="material-symbols-rounded">save</span> Save User
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
