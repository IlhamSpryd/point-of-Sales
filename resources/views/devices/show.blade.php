<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="{{ $device->device_name }}"
            description="Code: {{ $device->device_code }} &bull; Branch: {{ $device->store?->name }}">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('devices.index') }}">Devices</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>{{ $device->device_name }}</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-3">
                    @if($device->status === 'paired')
                        <x-ui.badge variant="success">Connected (Paired)</x-ui.badge>
                    @elseif($device->status === 'pending')
                        <x-ui.badge variant="warning">Pending Pairing</x-ui.badge>
                    @else
                        <x-ui.badge variant="destructive">Revoked</x-ui.badge>
                    @endif

                    <x-ui.button variant="secondary" href="{{ route('devices.edit', $device) }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">edit</span> Edit
                    </x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('devices.index') }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">arrow_back</span> Back
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Pairing Status & Token Card --}}
            <div class="lg:col-span-2 space-y-6">
                @if($device->status === 'pending')
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-amber-200 dark:border-amber-800/40 bg-amber-50/40 dark:bg-amber-950/10">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 rounded-2xl">
                                <span class="material-symbols-rounded text-3xl">key</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">Physical Terminal Pairing Token</h5>
                                <p class="text-sm text-zinc-500 mt-1">
                                    Enter the following code on your physical cashier terminal to authenticate and securely bind this device to the store.
                                </p>

                                <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4">
                                    <div class="px-6 py-4 bg-white dark:bg-zinc-900 rounded-2xl border-2 border-dashed border-amber-300 dark:border-amber-700 font-mono text-2xl md:text-3xl font-extrabold tracking-widest text-apeiron-ink dark:text-zinc-100 text-center shadow-xs select-all">
                                        {{ $device->pairing_token ?? 'EXPIRED' }}
                                    </div>
                                    
                                    <form action="{{ route('devices.regenerate-token', $device) }}" method="POST" onsubmit="return confirm('Regenerate a new pairing token? The existing token will become invalid.');">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" class="h-12 px-4 whitespace-nowrap gap-2">
                                            <span class="material-symbols-rounded text-base">refresh</span> Regenerate
                                        </x-ui.button>
                                    </form>
                                </div>

                                @if($device->pairing_token_expires_at)
                                    <p class="text-xs text-zinc-500 mt-3 flex items-center gap-1.5 font-medium">
                                        <span class="material-symbols-rounded text-[16px] text-amber-600 dark:text-amber-400">timer</span>
                                        Valid until: {{ $device->pairing_token_expires_at->format('M d, Y H:i') }} ({{ $device->pairing_token_expires_at->diffForHumans() }})
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif($device->status === 'paired')
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-emerald-200 dark:border-emerald-800/40 bg-emerald-50/30 dark:bg-emerald-950/10">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 rounded-2xl">
                                <span class="material-symbols-rounded text-3xl">verified</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">Device Connected &amp; Authorized</h5>
                                <p class="text-sm text-zinc-500 mt-1">
                                    This physical cashier terminal has been securely bound with a unique cryptographic signature and device fingerprint.
                                </p>

                                <div class="mt-6 space-y-3 font-mono text-xs">
                                    <div class="p-3 bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800">
                                        <span class="text-zinc-400 block text-[11px] uppercase font-sans font-semibold mb-1">Device Fingerprint</span>
                                        <span class="text-apeiron-ink dark:text-zinc-200 break-all select-all">{{ $device->device_fingerprint }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div class="p-3 bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800">
                                            <span class="text-zinc-400 block text-[11px] uppercase font-sans font-semibold mb-1">Last IP Address</span>
                                            <span class="text-apeiron-ink dark:text-zinc-200">{{ $device->ip_address ?? '-' }}</span>
                                        </div>
                                        <div class="p-3 bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800">
                                            <span class="text-zinc-400 block text-[11px] uppercase font-sans font-semibold mb-1">Last Active</span>
                                            <span class="text-apeiron-ink dark:text-zinc-200">{{ $device->last_seen_at ? $device->last_seen_at->format('M d, Y H:i:s') : 'Not recorded yet' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 pt-4 border-t border-emerald-200 dark:border-emerald-800/40 flex justify-end">
                                    <form action="{{ route('devices.revoke', $device) }}" method="POST" onsubmit="return confirm('Revoking access will immediately disconnect this physical cashier terminal. Proceed?');">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-rose-600 bg-white dark:bg-zinc-900 hover:bg-rose-50 dark:hover:bg-rose-950/30 border border-rose-200 dark:border-rose-900 text-sm font-semibold transition-colors duration-200 shadow-xs active:scale-95">
                                            <span class="material-symbols-rounded text-[18px]">phonelink_erase</span> Disconnect (Revoke)
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-rose-200 dark:border-rose-800/40 bg-rose-50/30 dark:bg-rose-950/10">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-300 rounded-2xl">
                                <span class="material-symbols-rounded text-3xl">block</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">Device Access Revoked</h5>
                                <p class="text-sm text-zinc-500 mt-1">
                                    This physical device is currently disabled and cannot be used to process cashier transactions.
                                </p>

                                <div class="mt-6 pt-4 border-t border-rose-200 dark:border-rose-800/40 flex justify-end">
                                    <form action="{{ route('devices.regenerate-token', $device) }}" method="POST" onsubmit="return confirm('Generate a new pairing token to reactivate this device?');">
                                        @csrf
                                        <x-ui.button type="submit" variant="primary" class="gap-2">
                                            <span class="material-symbols-rounded text-base">replay</span> Reactivate &amp; Generate Token
                                        </x-ui.button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Cashier Setup Guide --}}
                <div class="card-surface p-6 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 space-y-4 shadow-xs">
                    <h5 class="text-sm font-bold text-apeiron-ink dark:text-zinc-100 uppercase tracking-wider">Physical Cashier Setup Guide</h5>
                    <ol class="list-decimal list-inside text-sm text-zinc-500 space-y-2">
                        <li>Open the Apeiron application on your physical cashier terminal.</li>
                        <li>On the terminal initialization screen, select <strong class="text-apeiron-ink dark:text-zinc-200">"Connect Terminal / Device Pairing"</strong>.</li>
                        <li>Enter the 12-character pairing token shown above.</li>
                        <li>The system will automatically detect the machine/browser hardware fingerprint and securely lock it to branch <strong class="text-apeiron-ink dark:text-zinc-200">{{ $device->store?->name }}</strong>.</li>
                    </ol>
                </div>
            </div>

            {{-- Specification Details Card --}}
            <div class="space-y-6">
                <div class="card-surface p-6 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 space-y-4 shadow-xs">
                    <h5 class="text-sm font-bold text-apeiron-ink dark:text-zinc-100 uppercase tracking-wider">Device Details</h5>
                    
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-xs text-zinc-500 block">Device Name</span>
                            <span class="font-medium text-apeiron-ink dark:text-zinc-200">{{ $device->device_name }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-500 block">Unique Code</span>
                            <span class="font-mono font-medium text-apeiron-ink dark:text-zinc-200">{{ $device->device_code }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-500 block">Device Type</span>
                            <span class="font-medium text-apeiron-ink dark:text-zinc-200">
                                {{ match($device->device_type) {
                                    'pos_terminal' => 'POS Cashier Terminal',
                                    'kds_display' => 'Kitchen Display (KDS)',
                                    'waiter_tablet' => 'Waiter Tablet',
                                    default => $device->device_type,
                                } }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-500 block">Assigned Branch</span>
                            <span class="font-medium text-apeiron-ink dark:text-zinc-200">{{ $device->store?->name }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-500 block mb-1">Operational Status</span>
                            <x-ui.badge :variant="$device->is_active ? 'success' : 'secondary'">
                                {{ $device->is_active ? 'Active' : 'Inactive' }}
                            </x-ui.badge>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-500 block">Registered On</span>
                            <span class="text-apeiron-ink dark:text-zinc-200">{{ $device->created_at?->format('M d, Y H:i') }}</span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800">
                        <form action="{{ route('devices.destroy', $device) }}" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this device?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-rose-600 bg-rose-50 dark:bg-rose-950/20 hover:bg-rose-100 dark:hover:bg-rose-950/40 text-xs font-semibold transition-colors duration-200">
                                <span class="material-symbols-rounded text-[18px]">delete</span> Delete Device
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
