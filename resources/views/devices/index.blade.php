<x-app-layout>
    <div x-data="{ createDeviceDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="organization" />

        <!-- Header -->
        <x-erp.page-header
            title="POS Terminals &amp; Cashier Devices"
            description="Registration and authorization for physical POS terminals, kitchen display systems (KDS), and server tablets.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Devices</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="primary" type="button" @click="createDeviceDrawer = true" class="gap-2">
                    <span class="material-symbols-rounded text-base">add_to_queue</span>
                    Register Device
                </x-ui.button>
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

        {{-- Filter Bar --}}
        <x-erp.filter-bar search-action="{{ route('devices.index') }}" search-placeholder="Search devices...">
            <x-slot:filters>
                <form id="devices-filter-form" method="GET" action="{{ route('devices.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="search" value="{{ request('search') }}">
                    <select name="store_id" onchange="this.form.submit()" class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs focus:ring-1 focus:ring-ring outline-none">
                        <option value="">All Branches</option>
                        @foreach($stores as $st)
                            <option value="{{ $st->id }}" {{ request('store_id') == $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                        @endforeach
                    </select>
                    <select name="device_type" onchange="this.form.submit()" class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs focus:ring-1 focus:ring-ring outline-none">
                        <option value="">All Types</option>
                        <option value="pos_terminal" {{ request('device_type') === 'pos_terminal' ? 'selected' : '' }}>POS Terminal</option>
                        <option value="kds_display" {{ request('device_type') === 'kds_display' ? 'selected' : '' }}>KDS Screen</option>
                        <option value="waiter_tablet" {{ request('device_type') === 'waiter_tablet' ? 'selected' : '' }}>Waiter Tablet</option>
                    </select>
                    <select name="status" onchange="this.form.submit()" class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs focus:ring-1 focus:ring-ring outline-none">
                        <option value="">All Statuses</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending Pairing</option>
                        <option value="paired" {{ request('status') === 'paired' ? 'selected' : '' }}>Paired</option>
                        <option value="revoked" {{ request('status') === 'revoked' ? 'selected' : '' }}>Revoked</option>
                    </select>
                </form>
            </x-slot:filters>

            @if(request()->hasAny(['store_id', 'device_type', 'status', 'search']))
                <x-slot:actions>
                    <a href="{{ route('devices.index') }}">
                        <x-ui.button variant="ghost" size="sm" icon="restart_alt">Reset</x-ui.button>
                    </a>
                </x-slot:actions>
            @endif
        </x-erp.filter-bar>

        <x-erp.data-table
            :is-empty="$devices->isEmpty()"
            empty-title="No cashier devices registered yet."
            empty-description="Register POS terminals, KDS screens, and tablets for branch operations."
            empty-icon="devices"
            :colspan="6"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Device &amp; Branch</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Type</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Connection Status</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Last Activity</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
            </x-slot:head>

            @foreach($devices as $device)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4 font-sans font-medium text-foreground text-sm whitespace-nowrap">
                        {{ $device->device_code }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="font-semibold text-foreground text-sm">{{ $device->device_name }}</div>
                        <div class="text-xs text-muted-foreground mt-0.5">
                            {{ $device->store?->name ?? 'Unknown branch' }}
                        </div>
                    </td>
                    <td class="px-6 py-4 text-xs text-foreground whitespace-nowrap">
                        @php
                            $typeLabel = match($device->device_type) {
                                'pos_terminal' => 'POS Cashier Terminal',
                                'kds_display' => 'Kitchen Display (KDS)',
                                'waiter_tablet' => 'Waiter Tablet',
                                default => $device->device_type,
                            };
                        @endphp
                        <span class="font-medium text-foreground">
                            {{ $typeLabel }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $statusDot = match($device->pairing_status) {
                                'paired' => 'bg-success',
                                'pending' => 'bg-warning',
                                'revoked' => 'bg-destructive',
                                default => 'bg-neutral',
                            };
                            $statusLabel = match($device->pairing_status) {
                                'paired' => 'Paired',
                                'pending' => 'Pending Pairing',
                                'revoked' => 'Revoked',
                                default => $device->pairing_status,
                            };
                        @endphp
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $statusDot }}"></span>
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-xs text-muted-foreground whitespace-nowrap">
                        {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Never active' }}
                    </td>
                    <td class="px-6 py-4 text-right whitespace-nowrap">
                        <x-ui.table-actions>
                            <x-ui.table-action-item icon="visibility" href="{{ route('devices.show', $device) }}" wire:navigate>
                                View Details
                            </x-ui.table-action-item>
                            <x-ui.table-action-item icon="edit" href="{{ route('devices.edit', $device) }}" wire:navigate>
                                Edit Device
                            </x-ui.table-action-item>
                        </x-ui.table-actions>
                    </td>
                </tr>
            @endforeach

            @if($devices->hasPages())
                <x-slot:pagination>
                    {{ $devices->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        {{-- Contextual Drawer for PAGE-065 (Register Device) --}}
        <x-ui.drawer x-model="createDeviceDrawer" maxWidth="lg">
            <x-slot:header>
                <span>Register New Device</span>
            </x-slot:header>

            <form action="{{ route('devices.store') }}" method="POST" id="createDeviceDrawerForm" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Device Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="device_name" value="{{ old('device_name') }}" required placeholder="e.g. Main POS Floor 1 / Bar iPad" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Assigned Branch <span class="text-rose-500">*</span></label>
                    <select name="store_id" required class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                        <option value="">Select Branch</option>
                        @foreach($stores as $st)
                            <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Physical Device Type <span class="text-rose-500">*</span></label>
                    <select name="device_type" required class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                        <option value="pos_terminal">POS Cashier Terminal</option>
                        <option value="kds_display">Kitchen Display System (KDS)</option>
                        <option value="waiter_tablet">Server / Order-Taking Tablet</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Location / Identification Notes (Optional)</label>
                    <textarea name="notes" rows="3" placeholder="Additional physical device location details..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">{{ old('notes') }}</textarea>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="createDeviceDrawer = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="createDeviceDrawerForm">
                    Register &amp; Generate Token
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
