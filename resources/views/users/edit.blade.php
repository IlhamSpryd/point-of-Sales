<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Edit User: {{ $user->name }}"
            description="Update profile details, role assignments, and system credentials.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('users.index') }}">Users</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Edit</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('users.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Back
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <form action="{{ route('users.update', $user->id) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
                @csrf
                @method('PUT')

                <!-- Personal Info Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="name">Full Name</x-form-label>
                        <x-form-input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" 
                               placeholder="Enter full name" required autocomplete="name" class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                        @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="email">Email Address</x-form-label>
                        <x-form-input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" 
                               placeholder="example@domain.com" required autocomplete="username" class="{{ $errors->has('email') ? 'input-error' : '' }}" />
                        @error('email')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <x-form-label for="phone_number">Phone Number / WhatsApp</x-form-label>
                        <x-form-input type="text" id="phone_number" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" 
                               placeholder="+62 812 3456 7890" class="{{ $errors->has('phone_number') ? 'input-error' : '' }}" />
                        @error('phone_number')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="join_date">Join Date</x-form-label>
                        <x-form-input type="date" id="join_date" name="join_date" value="{{ old('join_date', $user->join_date?->format('Y-m-d')) }}" 
                               class="{{ $errors->has('join_date') ? 'input-error' : '' }}" />
                        @error('join_date')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Role & Status Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    <div>
                        <x-form-label for="role_id">Select Role</x-form-label>
                        <div class="relative">
                            <select id="role_id" name="role_id" 
                                    class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('role_id') ? 'input-error' : '' }}" required>
                                <option value="">Select Role</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}" {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
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
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <span class="text-sm font-medium text-apeiron-ink dark:text-zinc-200">Active Employee</span>
                        </label>
                    </div>
                </div>

                <!-- Security Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    <div>
                        <x-form-label for="pin_code">New Cashier PIN (Optional)</x-form-label>
                        <x-form-input type="password" id="pin_code" name="pin_code" 
                               placeholder="Leave blank to keep unchanged" class="{{ $errors->has('pin_code') ? 'input-error' : '' }}" />
                        @error('pin_code')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="password">New Web Password (Optional)</x-form-label>
                        <x-form-input type="password" id="password" name="password" 
                               placeholder="Leave blank to keep unchanged" autocomplete="new-password" class="{{ $errors->has('password') ? 'input-error' : '' }}" />
                        @error('password')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="password_confirmation">Confirm Password</x-form-label>
                        <x-form-input type="password" id="password_confirmation" name="password_confirmation" 
                               placeholder="••••••••" autocomplete="new-password" />
                    </div>
                </div>

                <div class="pt-6 border-t border-[#E5E7EB] dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Update User
                        </span>
                        <span x-show="submitting" x-cloak>Saving...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
