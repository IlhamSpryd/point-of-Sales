<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Register New Device"
            description="Register a physical POS terminal, kitchen display (KDS), or order-taking tablet prior to activation.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('devices.index') }}">Devices</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Register</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('devices.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Back
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <form action="{{ route('devices.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="device_name">Device Name</x-form-label>
                        <x-form-input type="text" id="device_name" name="device_name" value="{{ old('device_name') }}" 
                               placeholder="e.g. Main POS Floor 1 / Bar iPad" required :class="$errors->has('device_name') ? 'input-error' : ''" />
                        @error('device_name')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="store_id">Assigned Branch</x-form-label>
                        <div class="relative">
                            <select id="store_id" name="store_id" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('store_id') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                                <option value="">Select Branch</option>
                                @foreach($stores as $st)
                                    <option value="{{ $st->id }}" {{ old('store_id') == $st->id ? 'selected' : '' }}>
                                        {{ $st->name }} ({{ $st->code }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                            </div>
                        </div>
                        @error('store_id')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="device_type">Physical Device Type</x-form-label>
                        <div class="relative">
                            <select id="device_type" name="device_type" class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 text-sm appearance-none focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 transition-all duration-200 shadow-xs {{ $errors->has('device_type') ? 'border-rose-300 focus:ring-rose-500' : '' }}" required>
                                <option value="pos_terminal" {{ old('device_type') === 'pos_terminal' ? 'selected' : '' }}>POS Cashier Terminal</option>
                                <option value="kds_display" {{ old('device_type') === 'kds_display' ? 'selected' : '' }}>Kitchen Display System (KDS)</option>
                                <option value="waiter_tablet" {{ old('device_type') === 'waiter_tablet' ? 'selected' : '' }}>Server / Order-Taking Tablet</option>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-400">
                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                            </div>
                        </div>
                        @error('device_type')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-form-label for="device_code">Device Code (Optional)</x-form-label>
                        <x-form-input type="text" id="device_code" name="device_code" value="{{ old('device_code') }}" 
                               placeholder="Auto-generated if left blank (e.g. DEV-001)" :class="$errors->has('device_code') ? 'input-error' : ''" />
                        @error('device_code')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 text-xs text-zinc-600 dark:text-zinc-400 flex items-start gap-3">
                    <span class="material-symbols-rounded text-lg text-apeiron-primary shrink-0 mt-0.5">info</span>
                    <div>
                        <p class="font-semibold text-apeiron-ink dark:text-zinc-200">Device Activation Details</p>
                        <p class="mt-0.5">Once registration is saved, the system generates a 12-character activation pairing token. Enter this token on your physical terminal login screen to securely bind this device to the selected branch.</p>
                    </div>
                </div>

                <div class="pt-4 border-t border-[#E5E7EB] dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Save &amp; Generate Token
                        </span>
                        <span x-show="submitting" x-cloak>Registering...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
