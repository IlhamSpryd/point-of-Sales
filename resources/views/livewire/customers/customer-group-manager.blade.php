<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Customer Groups & Segmentation"
        description="Manage customer segments, default price lists, and automated discount rates per customer group.">
        <x-slot:actions>
            @can('manageGroups', \App\Modules\Customers\Models\Customer::class)
            <x-ui.button wire:click="create" icon="add" variant="primary">
                Add Group
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert variant="success" icon="check_circle">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger" icon="error">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-border">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search group name or code..."
                icon="search" />
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-border shadow-sm">
        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left text-sm text-muted-foreground">
                <thead class="bg-secondary /50 text-xs font-semibold uppercase text-muted-foreground border-b border-border">
                    <tr>
                        <th scope="col" class="px-6 py-4">Group Code & Name</th>
                        <th scope="col" class="px-6 py-4 text-right">Default Discount</th>
                        <th scope="col" class="px-6 py-4 text-right">Customer Count</th>
                        <th scope="col" class="px-6 py-4 text-center">Status</th>
                        <th scope="col" class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($groups as $grp)
                    <tr class="hover:bg-secondary/50 transition">
                        <td class="px-6 py-4">
                            <div class="font-semibold text-foreground flex items-center gap-2">
                                {{ $grp->name }}
                                @if($grp->is_b2b_default)
                                    <span class="text-xs text-muted-foreground font-medium">(Default B2B)</span>
                                @endif
                            </div>
                            <div class="text-xs font-sans text-muted-foreground mt-0.5">{{ $grp->code }}</div>
                            @if($grp->description)
                                <p class="text-xs text-muted-foreground mt-1 line-clamp-1">{{ $grp->description }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="font-sans font-semibold text-positive tabular-nums">{{ $grp->default_discount_rate }}%</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-xs text-muted-foreground font-sans tabular-nums">{{ $grp->customers_count }} customers</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $grp->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ $grp->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            @can('manageGroups', \App\Modules\Customers\Models\Customer::class)
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="edit" wire:click="edit({{ $grp->id }})">
                                    Edit Group
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="delete" variant="destructive" wire:click="delete({{ $grp->id }})" wire:confirm="Delete this customer group?">
                                    Delete Group
                                </x-ui.table-action-item>
                            </x-ui.table-actions>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                            <span class="material-symbols-rounded text-4xl block mb-2">folder_off</span>
                            No customer groups found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($groups->hasPages())
            <div class="px-6 py-4 border-t border-border">
                {{ $groups->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Form Grup -->
    <x-ui.modal wire:model="showModal" maxWidth="lg">
        <x-slot:title>
            {{ $isEditing ? 'Edit Customer Group' : 'Add Customer Group' }}
        </x-slot:title>

        <x-slot:content>
            <form id="groupForm" wire:submit="save" class="space-y-4">
                <div>
                    <x-ui.label for="code">Group Code *</x-ui.label>
                    <x-ui.input type="text" id="code" wire:model="code" placeholder="e.g. WHOLESALE-TIER1" class="uppercase" />
                    @error('code') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="name">Group Name *</x-ui.label>
                    <x-ui.input type="text" id="name" wire:model="name" placeholder="e.g. Wholesale Tier 1 / VIP" />
                    @error('name') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="default_discount_rate">Default Discount (%)</x-ui.label>
                    <x-ui.input type="number" step="0.01" min="0" max="100" id="default_discount_rate" wire:model="default_discount_rate" placeholder="0.00" />
                    @error('default_discount_rate') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="description">Description</x-ui.label>
                    <textarea id="description" wire:model="description" rows="2" placeholder="Group membership criteria and terms" class="w-full text-sm rounded-xl border border-border bg-card px-3 py-2 text-foreground focus:outline-none focus:ring-2 focus:ring-ring/20"></textarea>
                </div>

                <div class="pt-2 border-t border-border space-y-2">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_b2b_default" class="rounded border-border text-foreground shadow-xs focus:ring-2 focus:ring-ring">
                        <span class="text-xs font-medium text-foreground">Set as default group for new B2B registrations</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer block">
                        <input type="checkbox" wire:model="is_active" class="rounded border-border text-foreground shadow-xs focus:ring-2 focus:ring-ring">
                        <span class="text-xs font-medium text-foreground">Active Status</span>
                    </label>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit" form="groupForm" variant="primary" wire:loading.attr="disabled">
                    {{ $isEditing ? 'Save Changes' : 'Create Group' }}
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
