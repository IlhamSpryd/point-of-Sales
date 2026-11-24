<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="{{ $device->device_name }}"
            description="Code: {{ $device->device_code }} &bull; Branch: {{ $device->store?->name }}">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('stores.index') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('devices.index') }}">Devices</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>{{ $device->device_name }}</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-3">
                    @if($device->status === 'paired')
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
                            Connected (Paired)
                        </span>
                    @elseif($device->status === 'pending')
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-warning"></span>
                            Pending Pairing
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-destructive"></span>
                            Revoked
                        </span>
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
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-warning bg-warning-muted/40">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-warning-muted text-warning rounded-2xl">
                                <span class="material-symbols-rounded text-[28px]">key</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-foreground">Physical Terminal Pairing Token</h5>
                                <p class="text-sm text-muted-foreground mt-1">
                                    Enter the following code on your physical cashier terminal to authenticate and securely bind this device to the store.
                                </p>

                                <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4">
                                    <div class="px-6 py-4 bg-card rounded-2xl border-2 border-dashed border-warning font-sans text-xl md:text-2xl font-extrabold tracking-widest text-foreground text-center shadow-xs select-all">
                                        {{ $device->pairing_token ?? 'EXPIRED' }}
                                    </div>
                                    
                                    <form id="regen-token-form" action="{{ route('devices.regenerate-token', $device) }}" method="POST">
                                        @csrf
                                        <x-ui.button type="button" onclick="confirmSubmit('regen-token-form', '', { title: 'Buat Token Baru', message: 'Token baru akan membatalkan token yang ada. Lanjutkan?', confirmText: 'Buat Token', variant: 'primary' })" variant="secondary" class="h-12 px-4 whitespace-nowrap gap-2">
                                            <span class="material-symbols-rounded text-base">refresh</span> Regenerate
                                        </x-ui.button>
                                    </form>
                                </div>

                                @if($device->pairing_token_expires_at)
                                    <p class="text-xs text-muted-foreground mt-3 flex items-center gap-1.5 font-medium">
                                        <span class="material-symbols-rounded text-[16px] text-warning">timer</span>
                                        Valid until: {{ $device->pairing_token_expires_at->format('M d, Y H:i') }} ({{ $device->pairing_token_expires_at->diffForHumans() }})
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif($device->status === 'paired')
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-positive bg-positive-muted/30">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-positive-muted text-positive rounded-2xl">
                                <span class="material-symbols-rounded text-[28px]">verified</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-foreground">Device Connected &amp; Authorized</h5>
                                <p class="text-sm text-muted-foreground mt-1">
                                    This physical cashier terminal has been securely bound with a unique cryptographic signature and device fingerprint.
                                </p>

                                <div class="mt-6 space-y-3 font-sans text-xs">
                                    <div class="p-3 bg-card rounded-xl border border-border">
                                        <span class="text-muted-foreground block text-[11px] uppercase font-sans font-semibold mb-1">Device Fingerprint</span>
                                        <span class="text-foreground break-all select-all">{{ $device->device_fingerprint }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div class="p-3 bg-card rounded-xl border border-border">
                                            <span class="text-muted-foreground block text-[11px] uppercase font-sans font-semibold mb-1">Last IP Address</span>
                                            <span class="text-foreground">{{ $device->ip_address ?? '-' }}</span>
                                        </div>
                                        <div class="p-3 bg-card rounded-xl border border-border">
                                            <span class="text-muted-foreground block text-[11px] uppercase font-sans font-semibold mb-1">Last Active</span>
                                            <span class="text-foreground">{{ $device->last_seen_at ? $device->last_seen_at->format('M d, Y H:i:s') : 'Not recorded yet' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 pt-4 border-t border-positive flex justify-end">
                                    <form id="revoke-device-form" action="{{ route('devices.revoke', $device) }}" method="POST">
                                        @csrf
                                        <button type="button" onclick="confirmSubmit('revoke-device-form', '', { title: 'Putus Akses Perangkat', message: 'Pemutusan akses akan langsung memutuskan terminal kasir fisik ini. Lanjutkan?', confirmText: 'Putus Akses' })" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-destructive bg-card hover:bg-destructive-muted border border-destructive text-sm font-semibold transition-colors duration-200 shadow-xs active:scale-95">
                                            <span class="material-symbols-rounded text-[20px]">phonelink_erase</span> Disconnect (Revoke)
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="card-surface p-6 md:p-8 rounded-2xl border-2 border-destructive bg-destructive-muted/30">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-destructive-muted text-destructive rounded-2xl">
                                <span class="material-symbols-rounded text-[28px]">block</span>
                            </div>
                            <div class="flex-1">
                                <h5 class="text-lg font-bold text-foreground">Device Access Revoked</h5>
                                <p class="text-sm text-muted-foreground mt-1">
                                    This physical device is currently disabled and cannot be used to process cashier transactions.
                                </p>

                                <div class="mt-6 pt-4 border-t border-destructive flex justify-end">
                                    <form id="reactivate-device-form" action="{{ route('devices.regenerate-token', $device) }}" method="POST">
                                        @csrf
                                        <x-ui.button type="button" onclick="confirmSubmit('reactivate-device-form', '', { title: 'Aktifkan Kembali Perangkat', message: 'Buat token pairing baru untuk mengaktifkan kembali perangkat ini?', confirmText: 'Aktifkan' })" variant="primary" class="gap-2">
                                            <span class="material-symbols-rounded text-base">replay</span> Reactivate &amp; Generate Token
                                        </x-ui.button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Cashier Setup Guide --}}
                <div class="card-surface p-6 rounded-2xl border border-border space-y-4 shadow-xs">
                    <h5 class="text-sm font-bold text-foreground uppercase tracking-wider">Physical Cashier Setup Guide</h5>
                    <ol class="list-decimal list-inside text-sm text-muted-foreground space-y-2">
                        <li>Open the Apeiron application on your physical cashier terminal.</li>
                        <li>On the terminal initialization screen, select <strong class="text-foreground">"Connect Terminal / Device Pairing"</strong>.</li>
                        <li>Enter the 12-character pairing token shown above.</li>
                        <li>The system will automatically detect the machine/browser hardware fingerprint and securely lock it to branch <strong class="text-foreground">{{ $device->store?->name }}</strong>.</li>
                    </ol>
                </div>
            </div>

            {{-- Specification Details Card --}}
            <div class="space-y-6">
                <div class="card-surface p-6 rounded-2xl border border-border space-y-4 shadow-xs">
                    <h5 class="text-sm font-bold text-foreground uppercase tracking-wider">Device Details</h5>
                    
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-xs text-muted-foreground block">Device Name</span>
                            <span class="font-medium text-foreground">{{ $device->device_name }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground block">Unique Code</span>
                            <span class="font-sans font-medium text-foreground">{{ $device->device_code }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground block">Device Type</span>
                            <span class="font-medium text-foreground">
                                {{ match($device->device_type) {
                                    'pos_terminal' => 'POS Cashier Terminal',
                                    'kds_display' => 'Kitchen Display (KDS)',
                                    'waiter_tablet' => 'Waiter Tablet',
                                    default => $device->device_type,
                                } }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground block">Assigned Branch</span>
                            <span class="font-medium text-foreground">{{ $device->store?->name }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground block mb-1">Operational Status</span>
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $device->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ $device->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground block">Registered On</span>
                            <span class="text-foreground">{{ $device->created_at?->format('M d, Y H:i') }}</span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-border">
                        <form id="delete-device-form" action="{{ route('devices.destroy', $device) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="button" onclick="confirmDelete('delete-device-form', '{{ addslashes($device->device_name) }}')" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-destructive bg-destructive-muted hover:bg-destructive-muted text-xs font-semibold transition-colors duration-200">
                                <span class="material-symbols-rounded text-[20px]">delete</span> Delete Device
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
