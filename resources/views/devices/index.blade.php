<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Perangkat Kasir & Terminal POS</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Registrasi dan otorisasi perangkat fisik kasir (POS), layar dapur (KDS), dan tablet pelayan</p>
        </div>
        <x-list-toolbar search-action="{{ route('devices.index') }}" search-placeholder="Cari perangkat...">
            <x-slot:actions>
                <a href="{{ route('devices.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add_to_queue</span> Daftarkan Perangkat
                    </x-button>
                </a>
            </x-slot:actions>
        </x-list-toolbar>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    {{-- Filter Bar --}}
    <div class="card-surface p-4 mb-6 flex flex-wrap items-center gap-4">
        <form method="GET" action="{{ route('devices.index') }}" class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="w-48">
                <select name="store_id" onchange="this.form.submit()" class="form-input w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-xs">
                    <option value="">Semua Cabang</option>
                    @foreach($stores as $st)
                        <option value="{{ $st->id }}" {{ request('store_id') == $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-40">
                <select name="device_type" onchange="this.form.submit()" class="form-input w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-xs">
                    <option value="">Semua Tipe</option>
                    <option value="pos_terminal" {{ request('device_type') === 'pos_terminal' ? 'selected' : '' }}>Terminal POS</option>
                    <option value="kds_display" {{ request('device_type') === 'kds_display' ? 'selected' : '' }}>Layar KDS</option>
                    <option value="waiter_tablet" {{ request('device_type') === 'waiter_tablet' ? 'selected' : '' }}>Tablet Waiter</option>
                </select>
            </div>
            <div class="w-36">
                <select name="status" onchange="this.form.submit()" class="form-input w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-xs">
                    <option value="">Semua Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Menunggu Pairing</option>
                    <option value="paired" {{ request('status') === 'paired' ? 'selected' : '' }}>Terhubung</option>
                    <option value="revoked" {{ request('status') === 'revoked' ? 'selected' : '' }}>Dicabut</option>
                </select>
            </div>
            @if(request()->hasAny(['store_id', 'device_type', 'status', 'search']))
                <a href="{{ route('devices.index') }}" class="text-xs text-[#787774] hover:text-apeiron-ink font-medium underline">Reset Filter</a>
            @endif
        </form>
    </div>

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Kode</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Perangkat & Cabang</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Tipe</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Status Sambungan</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Aktivitas Terakhir</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
            </x-slot:head>
            @forelse($devices as $device)
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4 font-mono font-medium text-apeiron-ink text-sm whitespace-nowrap">
                        {{ $device->device_code }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="font-bold text-apeiron-ink text-sm">{{ $device->device_name }}</div>
                        <div class="text-xs text-[#787774] mt-0.5 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-[14px]">store</span>
                            <span>{{ $device->store?->name ?? 'Cabang tidak diketahui' }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-xs text-apeiron-ink whitespace-nowrap">
                        @php
                            $typeLabel = match($device->device_type) {
                                'pos_terminal' => 'Terminal POS Kasir',
                                'kds_display' => 'Display Dapur (KDS)',
                                'waiter_tablet' => 'Tablet Pelayan',
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
                            <span class="material-symbols-rounded text-[16px] text-[#787774]">{{ $typeIcon }}</span>
                            {{ $typeLabel }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($device->status === 'paired')
                            <x-badge type="success">Terhubung (Paired)</x-badge>
                        @elseif($device->status === 'pending')
                            <x-badge type="warning">Menunggu Pairing</x-badge>
                        @else
                            <x-badge type="danger">Dicabut (Revoked)</x-badge>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-xs text-[#787774] whitespace-nowrap">
                        @if($device->last_seen_at)
                            <div>{{ $device->last_seen_at->diffForHumans() }}</div>
                            @if($device->ip_address)
                                <div class="font-mono text-[11px] text-[#9B9A97]">IP: {{ $device->ip_address }}</div>
                            @endif
                        @else
                            <span class="text-[#9B9A97] italic">Belum pernah aktif</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                        <a href="{{ route('devices.show', $device) }}" class="inline-flex items-center justify-center min-w-10 min-h-10 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Detail perangkat" title="Detail & Token Pairing" wire:navigate>
                            <span class="material-symbols-rounded text-[20px]">visibility</span>
                        </a>

                        <a href="{{ route('devices.edit', $device) }}" class="inline-flex items-center justify-center min-w-10 min-h-10 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Ubah perangkat" wire:navigate>
                            <span class="material-symbols-rounded text-[20px]">edit</span>
                        </a>

                        <form action="{{ route('devices.destroy', $device) }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus perangkat {{ $device->device_name }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center justify-center min-w-10 min-h-10 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors duration-200" aria-label="Hapus perangkat">
                                <span class="material-symbols-rounded text-[20px]">delete</span>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-[#787774] text-sm">
                        <div class="flex flex-col items-center justify-center gap-2">
                            <span class="material-symbols-rounded text-4xl text-[#D3D3D0]">devices</span>
                            <p class="font-medium">Belum ada perangkat kasir fisik yang terdaftar.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        @if($devices->hasPages())
            <div class="p-4 border-t border-[#E9E9E7]">
                {{ $devices->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
