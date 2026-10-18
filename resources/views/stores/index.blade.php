<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Manajemen Cabang (Outlet)</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola cabang, zona waktu, dan status operasional bisnis Anda</p>
        </div>
        <x-list-toolbar search-action="{{ route('stores.index') }}" search-placeholder="Cari cabang...">
            <x-slot:actions>
                <a href="{{ route('stores.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add_business</span> Tambah Cabang
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

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Kode</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Info Cabang</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Zona Waktu</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Statistik</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Status</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
            </x-slot:head>
            @forelse($stores as $store)
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4 font-mono font-medium text-apeiron-ink text-sm whitespace-nowrap">
                        {{ $store->code }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-apeiron-ink text-sm">{{ $store->name }}</span>
                            @if($store->is_initial)
                                <x-badge type="info">Cabang Utama</x-badge>
                            @endif
                        </div>
                        <div class="text-xs text-[#787774] mt-1 flex flex-col gap-0.5">
                            @if($store->address)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[14px]">location_on</span> {{ $store->address }}
                                </span>
                            @endif
                            @if($store->phone)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[14px]">call</span> {{ $store->phone }}
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-apeiron-ink whitespace-nowrap">
                        <span class="inline-flex items-center gap-1 font-medium">
                            <span class="material-symbols-rounded text-[16px] text-[#787774]">schedule</span>
                            {{ $store->timezone }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                        <div class="flex items-center gap-3 text-xs text-[#787774]">
                            <span class="inline-flex items-center gap-1" title="Jumlah Meja">
                                <span class="material-symbols-rounded text-[16px]">table_restaurant</span> {{ $store->tables_count }} meja
                            </span>
                            <span>&bull;</span>
                            <span class="inline-flex items-center gap-1" title="Jumlah Perangkat">
                                <span class="material-symbols-rounded text-[16px]">devices</span> {{ $store->devices_count }} perangkat
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <x-badge :type="$store->is_active ? 'success' : 'secondary'">
                            {{ $store->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-badge>
                    </td>
                    <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                        <a href="{{ route('stores.edit', $store) }}" class="inline-flex items-center justify-center min-w-10 min-h-10 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Ubah cabang" wire:navigate>
                            <span class="material-symbols-rounded text-[20px]">edit</span>
                        </a>

                        @if(! $store->is_initial)
                            <form action="{{ route('stores.destroy', $store) }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus cabang {{ $store->name }}? Cabang hanya dapat dihapus jika tidak ada shift aktif atau perangkat yang terhubung.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center justify-center min-w-10 min-h-10 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors duration-200" aria-label="Hapus cabang">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-[#787774] text-sm">
                        <div class="flex flex-col items-center justify-center gap-2">
                            <span class="material-symbols-rounded text-4xl text-[#D3D3D0]">storefront</span>
                            <p class="font-medium">Belum ada cabang terdaftar.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        @if($stores->hasPages())
            <div class="p-4 border-t border-[#E9E9E7]">
                {{ $stores->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
