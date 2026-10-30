<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Add New Role"
            description="Define a new operational role and configure module access permissions.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('roles.index') }}">Roles</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Add New</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="secondary" href="{{ route('roles.index') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">arrow_back</span> Back
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <form action="{{ route('roles.store') }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-8">
                @csrf

                <!-- Role Details Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <x-form-label for="name">Role Name</x-form-label>
                        <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                               placeholder="e.g. Manager" required class="{{ $errors->has('name') ? 'input-error' : '' }}" />
                        @error('name')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <x-form-label for="description">Role Description (Optional)</x-form-label>
                        <x-form-input type="text" id="description" name="description" value="{{ old('description') }}" 
                               placeholder="Brief summary of responsibilities for this role" class="{{ $errors->has('description') ? 'input-error' : '' }}" />
                        @error('description')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Permissions Row -->
                <div class="pt-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    <div class="mb-4">
                        <h5 class="text-base font-semibold text-apeiron-ink dark:text-zinc-100">Module Access Privileges (RBAC)</h5>
                        <p class="text-sm text-zinc-500">Select specific modules and access permissions granted to this role.</p>
                    </div>

                    <div class="mb-5 flex items-start gap-3 rounded-2xl bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 px-4 py-3" role="alert">
                        <span class="material-symbols-rounded text-[20px] text-amber-600 dark:text-amber-400 shrink-0 mt-0.5">info</span>
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Granular module permissions are advisory</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400/80 mt-0.5">Permissions selected below are stored in the database; however, module authorization currently maps to core standard roles (Owner, Manager, Cashier, etc.). Dynamic per-permission policy enforcement is being rolled out.</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8">
                        <!-- Kasir & Transaksi -->
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_access_pos]" value="1" {{ old('permissions.can_access_pos') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">POS Screen Access</span>
                                <span class="block text-xs text-zinc-500">Process checkout transactions and cashier duties.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_void_order]" value="1" {{ old('permissions.can_void_order') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Void Authority</span>
                                <span class="block text-xs text-zinc-500">Cancel paid orders and void receipts.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_give_discount]" value="1" {{ old('permissions.can_give_discount') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Discount Privileges</span>
                                <span class="block text-xs text-zinc-500">Apply manual discounts at POS.</span>
                            </div>
                        </label>

                        <!-- Dapur & Manajemen -->
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_access_kds]" value="1" {{ old('permissions.can_access_kds') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Kitchen Screen (KDS)</span>
                                <span class="block text-xs text-zinc-500">Manage Kitchen Display System.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_manage_menu]" value="1" {{ old('permissions.can_manage_menu') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Catalog Management</span>
                                <span class="block text-xs text-zinc-500">Modify items, pricing, and stock.</span>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input type="checkbox" name="permissions[can_view_reports]" value="1" {{ old('permissions.can_view_reports') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <div>
                                <span class="block text-sm font-medium text-apeiron-ink dark:text-zinc-200 group-hover:text-apeiron-primary transition-colors">Sales & Shift Reports</span>
                                <span class="block text-xs text-zinc-500">Access sales summaries and shift reconciliations.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Status Row -->
                <div class="pt-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    <div class="flex items-center">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-apeiron-primary focus:ring-apeiron-primary/20 transition-colors">
                            <span class="text-sm font-medium text-apeiron-ink dark:text-zinc-200">Active Role</span>
                        </label>
                    </div>
                    <p class="text-xs text-zinc-500 mt-1 ml-8">Inactive roles cannot be assigned to new staff accounts.</p>
                </div>

                <div class="pt-6 border-t border-[#E5E7EB] dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">save</span> Save Role
                        </span>
                        <span x-show="submitting" x-cloak>Saving...</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
