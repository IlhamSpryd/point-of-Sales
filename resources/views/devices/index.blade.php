<x-app-layout>
    <div x-data="{ createDeviceDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
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
        <div class="card-surface p-4 rounded-2xl flex flex-wrap items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <form method="GET" action="{{ route('devices.index') }}" class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
                <div class="w-48">
                    <select name="store_id" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 text-xs focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        <option value="">All Branches</option>
                        @foreach($stores as $st)
                            <option value="{{ $st->id }}" {{ request('store_id') == $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-44">
                    <select name="device_type" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 text-xs focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        <option value="">All Types</option>
                        <option value="pos_terminal" {{ request('device_type') === 'pos_terminal' ? 'selected' : '' }}>POS Terminal</option>
                        <option value="kds_display" {{ request('device_type') === 'kds_display' ? 'selected' : '' }}>KDS Screen</option>
                        <option value="waiter_tablet" {{ request('device_type') === 'waiter_tablet' ? 'selected' : '' }}>Waiter Tablet</option>
                    </select>
                </div>
                <div class="w-40">
                    <select name="status" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 text-xs focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        <option value="">All Statuses</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending Pairing</option>
                        <option value="paired" {{ request('status') === 'paired' ? 'selected' : '' }}>Paired</option>
                        <option value="revoked" {{ request('status') === 'revoked' ? 'selected' : '' }}>Revoked</option>
                    </select>
                </div>
                @if(request()->hasAny(['store_id', 'device_type', 'status', 'search']))
                    <a href="{{ route('devices.index') }}" class="text-xs text-zinc-500 hover:text-apeiron-primary font-medium underline">Reset Filter</a>
                @endif
            </form>

            <form method="GET" action="{{ route('devices.index') }}" class="relative w-full sm:w-64">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search devices..." class="w-full pl-10 pr-4 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Code</th>
                            <th class="px-6 py-4">Device &amp; Branch</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4 text-center">Connection Status</th>
                            <th class="px-6 py-4">Last Activity</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($devices as $device)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono font-medium text-apeiron-ink dark:text-white text-sm whitespace-nowrap">
                                    {{ $device->device_code }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-bold text-zinc-900 dark:text-white text-sm">{{ $device->device_name }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5 flex items-center gap-1.5">
                                        <span class="material-symbols-rounded text-[14px]">store</span>
                                        <span>{{ $device->store?->name ?? 'Unknown branch' }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-800 dark:text-zinc-200 whitespace-nowrap">
                                    @php
                                        $typeLabel = match($device->device_type) {
                                            'pos_terminal' => 'POS Cashier Terminal',
                                            'kds_display' => 'Kitchen Display (KDS)',
                                            'waiter_tablet' => 'Waiter Tablet',
                                            default => $device->device_type,
                                        };
                                        $typeIcon = match($device->device_type) {
                                            'pos_terminal' => 'point_of_sale',
                                            'kds_display' => 'restaurant_menu',
                                            'waiter_tablet' => 'tablet_mac',
                                            default => 'devices',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 font-medium">
                                        <span class="material-symbols-rounded text-[16px] text-zinc-400">{{ $typeIcon }}</span>
                                        {{ $typeLabel }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    @php
                                        $statusVariant = match($device->pairing_status) {
                                            'paired' => 'success',
                                            'pending' => 'warning',
                                            'revoked' => 'danger',
                                            default => 'neutral',
                                        };
                                        $statusLabel = match($device->pairing_status) {
                                            'paired' => 'Paired',
                                            'pending' => 'Pending Pairing',
                                            'revoked' => 'Revoked',
                                            default => $device->pairing_status,
                                        };
                                    @endphp
                                    <x-ui.badge variant="{{ $statusVariant }}" size="sm">
                                        {{ $statusLabel }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500 whitespace-nowrap">
                                    {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Never active' }}
                                </td>
                                <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                                    <a href="{{ route('devices.show', $device) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="View details" wire:navigate>
                                        <span class="material-symbols-rounded text-lg">visibility</span>
                                    </a>
                                    <a href="{{ route('devices.edit', $device) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="Edit device" wire:navigate>
                                        <span class="material-symbols-rounded text-lg">edit</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">
                                    <span class="material-symbols-rounded text-4xl block mb-2">devices</span>
                                    No cashier devices registered yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($devices->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $devices->links() }}
                </div>
            @endif
        </div>

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
