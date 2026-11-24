<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="governance" />

        <!-- Header -->
        <x-erp.page-header
            title="Staff Invitations"
            description="Manage and monitor expiration status of digital branch staff invitations.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('users.index') }}">Dashboard</x-ui.breadcrumbs.item>
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

        <x-erp.data-table
                :is-empty="$invitations->isEmpty()"
                empty-title="No staff invitations have been issued yet."
                empty-description="Send candidate invitations to add team members to your store."
                empty-icon="forward_to_inbox"
                :colspan="6"
            >
                <x-slot:head>
                    <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Candidate Name</th>
                    <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Email</th>
                    <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Role & Branches</th>
                    <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                    <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Expires At</th>
                    <th scope="col" class="h-8 px-3 py-2 text-right text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
                </x-slot:head>

                @foreach($invitations as $invitation)
                    <tr class="hover:bg-secondary/70 transition-colors duration-150">
                        <td class="px-3 py-2 font-semibold text-foreground text-[12px]">{{ $invitation->name }}</td>
                        <td class="px-3 py-2 text-[12px] text-muted-foreground">{{ $invitation->email }}</td>
                        <td class="px-3 py-2 text-[12px]">
                            <div class="flex flex-col gap-0.5 items-start">
                                <span class="text-[11px] text-foreground font-medium">{{ $invitation->role->name ?? 'Staff' }}</span>
                                <span class="text-[11px] text-muted-foreground">
                                    {{ $invitation->stores->pluck('name')->join(', ') ?: 'All Branches' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            @php
                                $dotColor = match($invitation->status) {
                                    'pending' => 'bg-warning',
                                    'accepted' => 'bg-success',
                                    'revoked' => 'bg-destructive',
                                    default => 'bg-neutral',
                                };
                                $statusLabel = match($invitation->status) {
                                    'pending' => 'Pending Confirmation',
                                    'accepted' => 'Accepted',
                                    'revoked' => 'Revoked',
                                    'superseded' => 'Superseded',
                                    default => ucfirst($invitation->status),
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-[11px] text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                {{ $statusLabel }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-[12px] text-muted-foreground">
                            {{ $invitation->expires_at ? $invitation->expires_at->format('M d, Y H:i') : '—' }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            @if($invitation->status === 'pending' && $invitation->expires_at->isFuture())
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="forward_to_inbox" onclick="document.getElementById('resend-invite-{{ $invitation->id }}').submit()" aria-label="Resend Invitation">
                                        Resend Invitation
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="cancel" variant="destructive" onclick="confirmSubmit('revoke-invite-{{ $invitation->id }}', '', { title: 'Cabut Undangan', message: 'Apakah Anda yakin ingin mencabut undangan ini?', confirmText: 'Cabut Undangan' })" aria-label="Revoke Invitation">
                                        Revoke Invitation
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                                <form id="resend-invite-{{ $invitation->id }}" action="{{ route('users.invitations.resend', $invitation->id) }}" method="POST" class="hidden">
                                    @csrf
                                </form>
                                <form id="revoke-invite-{{ $invitation->id }}" action="{{ route('users.invitations.revoke', $invitation->id) }}" method="POST" class="hidden">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @else
                                <span class="text-xs text-muted-foreground">Completed</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if($invitations->hasPages())
                    <x-slot:pagination>
                        {{ $invitations->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>
    </div>
</x-app-layout>
