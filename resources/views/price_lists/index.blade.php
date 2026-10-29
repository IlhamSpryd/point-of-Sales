@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6" data-gsap="page-enter">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Daftar Harga & Grosir (Price Lists)</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola aturan harga grosir bertingkat, harga tier loyalitas, dan kontrak pelanggan</p>
        </div>

        <x-list-toolbar search-action="{{ route('price-lists.index') }}" search-placeholder="Cari daftar harga...">
            <x-slot:actions>
                @if($canManage)
                    <a href="{{ route('price-lists.create') }}" class="w-full sm:w-auto" wire:navigate>
                        <x-ui.button variant="primary" type="button" class="w-full sm:w-auto h-10" x-on:click="window.erpMotion?.pulseHighlight($el)">
                            <span class="material-symbols-rounded text-[18px]">add</span> Buat Daftar Harga
                        </x-ui.button>
                    </a>
                @endif
            </x-slot:actions>
        </x-list-toolbar>
    </div>

    @if(session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif
    @if(session('error'))
        <x-ui.alert variant="destructive" class="mb-4">{{ session('error') }}</x-ui.alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Prioritas</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kode & Nama</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Tipe Aturan</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Cakupan / Target</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Jumlah Item</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Masa Berlaku</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                @endif
            </x-slot:head>

            @forelse($priceLists as $pl)
                @php
                    $typeLabels = [
                        'standard' => 'Standar',
                        'branch' => 'Cabang',
                        'wholesale' => 'Grosir Bertingkat',
                        'customer_tier' => 'Tier Loyalitas',
                        'customer_specific' => 'Kontrak Khusus',
                        'channel' => 'Kanal Penjualan',
                    ];
                    $typeBadgeTypes = [
                        'standard' => 'secondary',
                        'branch' => 'info',
                        'wholesale' => 'warning',
                        'customer_tier' => 'info',
                        'customer_specific' => 'success',
                        'channel' => 'secondary',
                    ];
                @endphp
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4 text-center font-bold text-sm text-apeiron-ink tabular-nums">#{{ $pl->priority }}</td>
                    <td class="px-6 py-4">
                        <div class="font-semibold text-sm text-apeiron-ink">{{ $pl->name }}</div>
                        <div class="font-mono text-xs text-[#787774]">{{ $pl->code }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.badge :variant="$typeBadgeTypes[$pl->type] ?? 'secondary'">
                            {{ $typeLabels[$pl->type] ?? $pl->type }}
                        </x-ui.badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-[#787774]">
                        @if($pl->customer)
                            <div class="font-medium text-apeiron-ink">{{ $pl->customer->name }}</div>
                            <div class="text-xs">Pelanggan Khusus</div>
                        @elseif($pl->loyaltyTier)
                            <div class="font-medium text-apeiron-ink">{{ $pl->loyaltyTier->name }}</div>
                            <div class="text-xs">Tier Member</div>
                        @elseif($pl->store)
                            <div class="font-medium text-apeiron-ink">{{ $pl->store->name }}</div>
                            <div class="text-xs">Cabang Spesifik</div>
                        @elseif($pl->channel)
                            <div class="font-medium text-apeiron-ink">{{ strtoupper($pl->channel) }}</div>
                            <div class="text-xs">Kanal</div>
                        @else
                            <span class="text-xs italic text-[#9B9A97]">Semua Pelanggan / Global</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm font-semibold text-right text-apeiron-ink tabular-nums">{{ $pl->items_count }} item</td>
                    <td class="px-6 py-4 text-xs text-[#787774]">
                        @if($pl->valid_from || $pl->valid_until)
                            {{ $pl->valid_from?->format('d/m/Y') ?? 'Awal' }} s/d {{ $pl->valid_until?->format('d/m/Y') ?? 'Selamanya' }}
                        @else
                            <span class="text-xs text-[#9B9A97]">Permanen</span>
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        @if($pl->is_active)
                            <x-ui.badge variant="success" :dot="true">Aktif</x-ui.badge>
                        @else
                            <x-ui.badge variant="secondary" :dot="true">Nonaktif</x-ui.badge>
                        @endif
                    </td>
                    @if($canManage)
                        <td class="px-6 py-4 text-right space-x-1">
                            <a href="{{ route('price-lists.edit', $pl->id) }}" wire:navigate
                                class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors"
                                aria-label="Ubah daftar harga">
                                <span class="material-symbols-rounded text-[20px]">edit</span>
                            </a>
                            <form id="delete-pl-{{ $pl->id }}" action="{{ route('price-lists.destroy', $pl->id) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-pl-{{ $pl->id }}', '{{ addslashes($pl->name) }}')"
                                    class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors"
                                    aria-label="Hapus daftar harga">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canManage ? 8 : 7 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                        <div class="flex flex-col items-center justify-center">
                            <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">price_change</span>
                            <p class="font-medium">Belum ada daftar harga khusus atau grosir terdaftar.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        <div class="p-4 border-t border-[#E9E9E7]">
            {{ $priceLists->links() }}
        </div>
    </div>
</x-app-layout>
