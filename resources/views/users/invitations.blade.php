<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="Staff Invitations"
            description="Manage and monitor expiration status of digital branch staff invitations.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('users.index') }}">Users</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Invitations</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-ui.button variant="secondary" href="{{ route('users.index') }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">group</span> User Directory
                    </x-ui.button>
                    <x-ui.button variant="primary" href="{{ route('users.create') }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">person_add</span> New Invitation
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

        <div class="card-surface flex flex-col flex-1 min-h-0 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Candidate Name</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Email</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Role & Branches</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Expires At</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>

                @forelse($invitations as $invitation)
                    <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                        <td class="px-6 py-4 font-semibold text-apeiron-ink dark:text-zinc-100 text-sm">{{ $invitation->name }}</td>
                        <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-300">{{ $invitation->email }}</td>
                        <td class="px-6 py-4 text-sm">
                            <div class="flex flex-col gap-1 items-start">
                                <x-ui.badge variant="primary">{{ $invitation->role->name ?? 'Staff' }}</x-ui.badge>
                                <span class="text-xs text-zinc-500">
                                    {{ $invitation->stores->pluck('name')->join(', ') ?: 'All Branches' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            @if($invitation->status === 'pending')
                                <x-ui.badge variant="warning">Pending Confirmation</x-ui.badge>
                            @elseif($invitation->status === 'accepted')
                                <x-ui.badge variant="success">Accepted</x-ui.badge>
                            @elseif($invitation->status === 'revoked')
                                <x-ui.badge variant="destructive">Revoked</x-ui.badge>
                            @elseif($invitation->status === 'superseded')
                                <x-ui.badge variant="secondary">Superseded</x-ui.badge>
                            @else
                                <x-ui.badge variant="secondary">{{ ucfirst($invitation->status) }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-500">
                            {{ $invitation->expires_at ? $invitation->expires_at->format('M d, Y H:i') : '—' }}
                        </td>
                        <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                            @if($invitation->status === 'pending' && $invitation->expires_at->isFuture())
                                <form action="{{ route('users.invitations.resend', $invitation->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-apeiron-primary hover:text-apeiron-primary-dark p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors" title="Resend">
                                        <span class="material-symbols-rounded text-sm align-middle">forward_to_inbox</span> Resend
                                    </button>
                                </form>
                                <form action="{{ route('users.invitations.revoke', $invitation->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to revoke this invitation?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-rose-600 hover:text-rose-800 p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors" title="Revoke Invitation">
                                        <span class="material-symbols-rounded text-sm align-middle">cancel</span> Revoke
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-zinc-400">Completed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">
                            No staff invitations have been issued yet.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800 shrink-0">
                {{ $invitations->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
