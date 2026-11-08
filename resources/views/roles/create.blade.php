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

        <div class="card-surface overflow-hidden w-full rounded-2xl border border-border dark:border-zinc-800 shadow-xs">
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
                <div class="pt-4 border-t border-border dark:border-zinc-800">
                    <div class="mb-4">
                        <h5 class="text-base font-semibold text-foreground dark:text-zinc-100">Module Access Privileges (RBAC)</h5>
                        <p class="text-sm text-muted-foreground">Select specific operational domains and granular capabilities granted to this role.</p>
                    </div>

                    <x-erp.permission-matrix :selected="old('permissions', [])" />
                </div>

                <!-- Status Row -->
                <div class="pt-4 border-t border-border dark:border-zinc-800">
                    <div class="flex items-center">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="w-5 h-5 rounded border-zinc-300 dark:border-zinc-700 text-primary focus:ring-ring/20 transition-colors">
                            <span class="text-sm font-medium text-foreground dark:text-zinc-200">Active Role</span>
                        </label>
                    </div>
                    <p class="text-xs text-zinc-500 mt-1 ml-8">Inactive roles cannot be assigned to new staff accounts.</p>
                </div>

                <div class="pt-6 border-t border-border dark:border-zinc-800 flex justify-end">
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
