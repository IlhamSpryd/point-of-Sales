<x-app-layout>
    <div x-data="{ createRoleDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="governance" />

        <!-- Header -->
        <x-erp.page-header
            title="Roles & Permissions"
            description="Define role authorities, staff access privileges, and feature permissions across your multi-tenant organization.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Roles</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button variant="secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="gap-2">
                        <span class="material-symbols-rounded text-base">download</span> Export
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="createRoleDrawer = true" class="gap-2">
                        <span class="material-symbols-rounded text-base">add_moderator</span> Add Role
                    </x-ui.button>
                </div>
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif

        @if(session('error'))
            <x-ui.alert type="danger" title="Error" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <x-erp.filter-bar
            search-action="{{ route('roles.index') }}"
            search-placeholder="Search role name or code..."
        />

        <div class="flex flex-col flex-1 min-h-0 space-y-4">
            {{-- Desktop/tablet-landscape: existing table --}}
            <x-erp.data-table
                :hideOnMobile="true"
                :is-empty="$roles->isEmpty()"
                empty-title="No roles found."
                empty-description="Create roles to manage tenant access permissions."
                empty-icon="admin_panel_settings"
                :colspan="5"
            >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Role Code / ID</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Role Name</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Permissions & Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Users</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>
                
                @foreach($roles as $role)
                    <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                        <td class="px-6 py-4 font-sans font-medium text-foreground text-sm">{{ $role->role_code }}</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-semibold text-foreground dark:text-zinc-100">{{ $role->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $role->description ?: '—' }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col items-start gap-1.5">
                                @php
                                    $rawPerms = is_array($role->permissions) ? $role->permissions : [];
                                    $isWildcard = in_array('*', $rawPerms, true);
                                    $permsCount = count(array_filter($rawPerms));
                                @endphp
                                <x-ui.badge variant="primary">{{ $isWildcard ? 'Full Access (*)' : $permsCount.' Permissions' }}</x-ui.badge>
                                
                                <x-ui.badge :variant="$role->is_active ? 'success' : 'destructive'">
                                    {{ $role->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $role->users_count ?? 0 }} Users
                        </td>
                        <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                            <a href="{{ route('roles.edit', $role->id) }}" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-600 hover:text-foreground hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors active:scale-90" aria-label="Edit role" wire:navigate>
                                <span class="material-symbols-rounded text-[20px]">edit</span>
                            </a>
                            @if ($role->name === 'Owner')
                                <span class="inline-flex items-center justify-center size-9 rounded-lg text-muted-foreground bg-muted/50 cursor-not-allowed" title="System Owner role is locked and cannot be deleted">
                                    <span class="material-symbols-rounded text-[20px]">lock</span>
                                </span>
                            @else
                                <form id="delete-form-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmSubmit('delete-form-{{ $role->id }}', '{{ addslashes($role->name) }}')" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors active:scale-90" aria-label="Delete role">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if($roles->hasPages())
                    <x-slot:pagination>
                        {{ $roles->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>

            {{-- Mobile/tablet-portrait: card list --}}
            <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
                @forelse($roles as $role)
                    <div class="card-surface p-4 rounded-xl border border-border">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex flex-col">
                                <span class="font-bold text-foreground text-sm">{{ $role->name }}</span>
                                <span class="text-xs text-muted-foreground font-sans">{{ $role->role_code }}</span>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                @php
                                    $permsCount = count(array_filter($role->permissions ?? []));
                                @endphp
                                <x-ui.badge variant="primary">{{ $permsCount }} Permissions</x-ui.badge>
                                <x-ui.badge :variant="$role->is_active ? 'success' : 'destructive'">
                                    {{ $role->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-border">
                            <div class="flex-1">
                                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-0.5">Users</span>
                                <span class="text-xs text-foreground">{{ $role->users_count ?? 0 }} Assigned</span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <a href="{{ route('roles.edit', $role->id) }}" class="size-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" aria-label="Edit role" wire:navigate>
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </a>
                                <form id="delete-form-mobile-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmSubmit('delete-form-mobile-{{ $role->id }}', '{{ addslashes($role->name) }}')" class="size-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors" aria-label="Delete role">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-muted-foreground text-sm flex flex-col items-center justify-center">
                        <div class="w-12 h-12 rounded-2xl bg-muted flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-[24px] text-muted-foreground">admin_panel_settings</span>
                        </div>
                        <p class="font-medium text-foreground">No roles found.</p>
                    </div>
                @endforelse
            </div>
            @if($roles->hasPages())
                <div class="lg:hidden p-4 border-t border-border shrink-0">
                    {{ $roles->links() }}
                </div>
            @endif
        </div>

        {{-- Contextual Drawer for PAGE-078 (Create Role) --}}
        <x-ui.drawer x-model="createRoleDrawer" maxWidth="xl">
            <x-slot:header>
                <span>Add New Role & Permissions</span>
            </x-slot:header>

            <form action="{{ route('roles.store') }}" method="POST" id="createRoleDrawerForm" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Role Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g. Supervisor, Barista, Auditor" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Role Description (Optional)</label>
                    <input type="text" name="description" value="{{ old('description') }}" placeholder="Brief summary of responsibilities for this role..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <h5 class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Module Access Privileges (RBAC)</h5>
                    <x-erp.permission-matrix :selected="old('permissions', [])" :compact="true" />
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="role_is_active" name="is_active" value="1" checked class="rounded border-zinc-300 text-primary-600 focus:ring-primary-500">
                    <label for="role_is_active" class="text-sm font-medium text-foreground">Active Role</label>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="createRoleDrawer = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="createRoleDrawerForm">
                    Save Role
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
