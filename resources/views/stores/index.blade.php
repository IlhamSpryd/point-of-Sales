<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="Manajemen Cabang (Outlets)"
            description="Kelola cabang, zona waktu, dan status operasional outlet bisnis multi-tenant Anda.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Cabang</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="primary" href="{{ route('stores.create') }}" class="gap-2" wire:navigate>
                    <span class="material-symbols-rounded text-base">add_business</span>
                    Tambah Cabang
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
            <form method="GET" action="{{ route('stores.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari cabang..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <!-- Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200/80 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Kode</th>
                            <th class="px-6 py-4">Info Cabang</th>
                            <th class="px-6 py-4">Zona Waktu</th>
                            <th class="px-6 py-4">Statistik</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                        @forelse($stores as $store)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono font-medium text-apeiron-ink dark:text-white text-sm whitespace-nowrap">
                                    {{ $store->code }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-zinc-900 dark:text-white text-sm">{{ $store->name }}</span>
                                        @if($store->is_initial)
                                            <x-ui.badge variant="info" size="xs">Cabang Utama</x-ui.badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-400 mt-1 flex flex-col gap-0.5">
                                        @if($store->address)
                                            <span class="inline-flex items-center gap-1">
                                                <span class="material-symbols-rounded text-[14px]">location_on</span> {{ $store->address }}
                                            </span>
                                        @endif
                                        @if($store->phone)
                                            <span class="inline-flex items-center gap-1 font-mono">
                                                <span class="material-symbols-rounded text-[14px]">call</span> {{ $store->phone }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 font-medium text-xs">
                                        <span class="material-symbols-rounded text-[16px] text-zinc-400">schedule</span>
                                        {{ $store->timezone }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm whitespace-nowrap">
                                    <div class="flex items-center gap-3 text-xs text-zinc-500">
                                        <span class="inline-flex items-center gap-1" title="Jumlah Meja">
                                            <span class="material-symbols-rounded text-[16px]">table_restaurant</span> {{ $store->tables_count }} meja
                                        </span>
                                        <span>&bull;</span>
                                        <span class="inline-flex items-center gap-1" title="Jumlah Perangkat">
                                            <span class="material-symbols-rounded text-[16px]">devices</span> {{ $store->devices_count }} perangkat
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <x-ui.badge variant="{{ $store->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $store->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                                    <a href="{{ route('stores.edit', $store) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="Ubah cabang" wire:navigate>
                                        <span class="material-symbols-rounded text-lg">edit</span>
                                    </a>

                                    @if(! $store->is_initial)
                                        <form action="{{ route('stores.destroy', $store) }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus cabang {{ $store->name }}? Cabang hanya dapat dihapus jika tidak ada shift aktif atau perangkat yang terhubung.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="Hapus cabang">
                                                <span class="material-symbols-rounded text-lg">delete</span>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">
                                    <span class="material-symbols-rounded text-4xl block mb-2">store</span>
                                    Belum ada cabang terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($stores->hasPages())
                <div class="p-4 border-t border-zinc-200/80 dark:border-zinc-800">
                    {{ $stores->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
