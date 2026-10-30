<x-app-layout>
    <div x-data="{ activeTab: 'users', createUserDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="User & Staff Management"
            description="Manage staff accounts, employee role permissions, and branch assignments across your multi-tenant organization.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Users</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button variant="secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="gap-2">
                        <span class="material-symbols-rounded text-base">download</span> Export
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="createUserDrawer = true" class="gap-2">
                        <span class="material-symbols-rounded text-base">person_add</span> Add User
                    </x-ui.button>
                </div>
            </x-slot:actions>
        </x-erp.page-header>

        <!-- Navigation Tabs: Active Staff vs Invitations (PAGE-076 Tab Consolidation) -->
        <div class="flex items-center gap-6 border-b border-[#E5E7EB] dark:border-zinc-800 text-sm font-semibold">
            <button type="button" @click="activeTab = 'users'"
                :class="activeTab === 'users' ? 'border-b-2 border-[#202123] text-[#202123] dark:text-white font-bold py-3' : 'text-zinc-500 hover:text-zinc-700 py-3'">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">badge</span>
                    Active Staff ({{ $users->total() }})
                </span>
            </button>
            <button type="button" @click="activeTab = 'invitations'"
                :class="activeTab === 'invitations' ? 'border-b-2 border-[#202123] text-[#202123] dark:text-white font-bold py-3' : 'text-zinc-500 hover:text-zinc-700 py-3'">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">mail</span>
                    Staff Invitations ({{ $invitations->count() }})
                </span>
            </button>
        </div>

        @if(session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Error" class="rounded-xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Users Tab Content -->
        <div x-show="activeTab === 'users'" class="space-y-6">
            <!-- Filter & Search Toolbar -->
            <div class="card-surface p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <form method="GET" action="{{ route('users.index') }}" class="relative w-full sm:w-80">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, email, employee ID..." class="w-full pl-10 pr-4 py-2 text-sm rounded-lg border border-[#E5E7EB] dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
                </form>
            </div>

        <div class="card-surface flex flex-col flex-1 min-h-0 overflow-hidden">
            {{-- Desktop/tablet-landscape: existing table --}}
            <x-data-table :hideOnMobile="true">
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Employee ID</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Employee</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Contact</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Role & Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Joined Date</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>
                
                @forelse($users as $user)
                    <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                        <td class="px-6 py-4 font-mono font-medium text-apeiron-ink text-sm">{{ $user->employee_id }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-zinc-100 dark:bg-zinc-800 text-apeiron-ink dark:text-zinc-100 flex items-center justify-center font-bold text-xs border border-zinc-200 dark:border-zinc-700 shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-apeiron-ink dark:text-zinc-100">{{ $user->name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $user->email }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-300">{{ $user->phone_number ?: '—' }}</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col items-start gap-1.5">
                                @if($user->role)
                                    <x-ui.badge variant="primary">{{ $user->role->name }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">Unassigned</x-ui.badge>
                                @endif
                                
                                <x-ui.badge :variant="$user->is_active ? 'success' : 'destructive'">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-500">{{ $user->join_date ? $user->join_date->format('M d, Y') : ($user->created_at?->format('M d, Y') ?? '—') }}</td>
                        <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                            <a href="{{ route('users.edit', $user->id) }}" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-600 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors active:scale-90" aria-label="Edit user" wire:navigate>
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </a>
                            @if(auth()->id() !== $user->id)
                            <form id="delete-form-{{ $user->id }}" action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-{{ $user->id }}', '{{ addslashes($user->name) }}')" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors active:scale-90" aria-label="Delete user">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-14 h-14 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                    <span class="material-symbols-rounded text-[28px] text-zinc-400">group</span>
                                </div>
                                <p class="font-medium text-zinc-600 dark:text-zinc-300">No users found.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            {{-- Mobile/tablet-portrait: card list --}}
            <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
                @forelse($users as $user)
                    <div class="card-surface p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 text-apeiron-ink dark:text-zinc-100 flex items-center justify-center font-bold text-sm border border-zinc-200 dark:border-zinc-700 shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div class="flex flex-col">
                                    <span class="font-bold text-apeiron-ink dark:text-zinc-100 text-sm">{{ $user->name }}</span>
                                    <span class="text-xs text-zinc-500 font-mono">{{ $user->employee_id }}</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                @if($user->role)
                                    <x-ui.badge variant="primary">{{ $user->role->name }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">Unassigned</x-ui.badge>
                                @endif
                                <x-ui.badge :variant="$user->is_active ? 'success' : 'destructive'">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                            <div class="flex-1">
                                <span class="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider block mb-0.5">Contact</span>
                                <span class="text-xs text-apeiron-ink dark:text-zinc-200 block truncate">{{ $user->email }}</span>
                                <span class="text-xs text-zinc-500">{{ $user->phone_number ?: '—' }}</span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <a href="{{ route('users.edit', $user->id) }}" class="size-8 flex items-center justify-center rounded-lg text-zinc-600 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors" aria-label="Edit user" wire:navigate>
                                    <span class="material-symbols-rounded text-[18px]">edit</span>
                                </a>
                                @if(auth()->id() !== $user->id)
                                    <form id="delete-form-mobile-{{ $user->id }}" action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $user->id }}', '{{ addslashes($user->name) }}')" class="size-8 flex items-center justify-center rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 transition-colors" aria-label="Delete user">
                                            <span class="material-symbols-rounded text-[18px]">delete</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-zinc-400 text-sm flex flex-col items-center justify-center">
                        <div class="w-12 h-12 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-[24px] text-zinc-400">group</span>
                        </div>
                        <p class="font-medium text-zinc-600 dark:text-zinc-300">No users found.</p>
                    </div>
                @endforelse
            </div>
            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800 shrink-0">
                {{ $users->links() }}
            </div>
        </div>
        </div>

        <!-- Invitations Tab Content (PAGE-076 Consolidation) -->
        <div x-show="activeTab === 'invitations'" class="space-y-6">
            <div class="card-surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-[#F9FAFB] dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                                <th class="px-6 py-4">Candidate Email</th>
                                <th class="px-6 py-4">Role</th>
                                <th class="px-6 py-4">Invited By</th>
                                <th class="px-6 py-4">Status & Expiration</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                            @forelse($invitations as $invitation)
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                    <td class="px-6 py-4 font-medium text-apeiron-ink dark:text-zinc-100">
                                        {{ $invitation->email }}
                                    </td>
                                    <td class="px-6 py-4 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $invitation->role?->name ?? 'Custom' }}
                                    </td>
                                    <td class="px-6 py-4 text-xs text-zinc-500">
                                        {{ $invitation->invitedBy?->name ?? 'System' }}
                                    </td>
                                    <td class="px-6 py-4 text-xs">
                                        @if($invitation->isExpired())
                                            <span class="text-rose-600 font-medium">Expired</span>
                                        @elseif($invitation->accepted_at)
                                            <span class="text-emerald-600 font-medium">Accepted ({{ $invitation->accepted_at->format('M d') }})</span>
                                        @else
                                            <span class="text-amber-600 font-medium">Pending (exp. {{ $invitation->expires_at->diffForHumans() }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right space-x-2">
                                        @if(!$invitation->accepted_at)
                                            <form action="{{ route('users.invitations.resend', $invitation->id) }}" method="POST" class="inline-block">
                                                @csrf
                                                <button type="submit" class="text-xs text-primary-600 hover:text-primary-800 font-semibold" title="Resend Invitation">
                                                    Resend
                                                </button>
                                            </form>
                                            <form action="{{ route('users.invitations.revoke', $invitation->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to cancel this invitation?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-semibold" title="Cancel Invitation">
                                                    Cancel
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-xs text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">
                                        <span class="material-symbols-rounded text-4xl block mb-2">mail</span>
                                        No pending staff invitations.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Contextual Drawer for PAGE-074 (Create Employee) --}}
        <x-ui.drawer x-model="createUserDrawer" maxWidth="xl">
            <x-slot:header>
                <span>Add New User & Employee</span>
            </x-slot:header>

            <form action="{{ route('users.store') }}" method="POST" id="createUserDrawerForm" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Full Name <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g. Budi Santoso" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Email Address <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" value="{{ old('email') }}" required placeholder="budi@apeiron.id" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Phone Number / WhatsApp</label>
                        <input type="tel" name="phone_number" value="{{ old('phone_number') }}" placeholder="+62 812 3456 7890" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Role <span class="text-rose-500">*</span></label>
                        <select name="role_id" required class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                            <option value="">Select Role</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Cashier PIN (4-6 Digits)</label>
                        <input type="password" name="pin_code" placeholder="e.g. 123456" maxlength="6" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Web Password <span class="text-rose-500">*</span></label>
                        <input type="password" name="password" required placeholder="••••••••" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Confirm Password <span class="text-rose-500">*</span></label>
                        <input type="password" name="password_confirmation" required placeholder="••••••••" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="user_is_active" name="is_active" value="1" checked class="rounded border-zinc-300 text-primary-600 focus:ring-primary-500">
                    <label for="user_is_active" class="text-sm font-medium text-apeiron-ink">Active Employee Account</label>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="createUserDrawer = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="createUserDrawerForm">
                    Save User
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
