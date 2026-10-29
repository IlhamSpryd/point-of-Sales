<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="Peran & Hak Akses"
            description="Definisikan wewenang, batasan peran pengguna, dan izin fitur sistem multi-tenant.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Peran</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button variant="secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="gap-2">
                        <span class="material-symbols-rounded text-base">download</span> Ekspor
                    </x-ui.button>
                    <x-ui.button variant="primary" href="{{ route('roles.create') }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">add_moderator</span> Tambah Peran
                    </x-ui.button>
                </div>
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
            <form method="GET" action="{{ route('roles.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari nama atau kode peran..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <div class="card-surface flex flex-col flex-1 min-h-0 rounded-2xl border border-zinc-200/80 dark:border-zinc-800 overflow-hidden shadow-xs">
            {{-- Desktop/tablet-landscape: existing table --}}
            <x-data-table :hideOnMobile="true">
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">ID / Kode</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Peran</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Hak Akses & Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Pengguna</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Aksi</th>
                </x-slot:head>
                
                @forelse($roles as $role)
                    <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                        <td class="px-6 py-4 font-mono font-medium text-apeiron-ink text-sm">{{ $role->role_code }}</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-semibold text-apeiron-ink dark:text-zinc-100">{{ $role->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $role->description ?: '—' }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col items-start gap-1.5">
                                @php
                                    $permsCount = count(array_filter($role->permissions ?? []));
                                @endphp
                                <x-ui.badge variant="primary">{{ $permsCount }} Akses</x-ui.badge>
                                
                                <x-ui.badge :variant="$role->is_active ? 'success' : 'destructive'">
                                    {{ $role->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-ui.badge>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $role->users_count ?? 0 }} Pengguna
                        </td>
                        <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                            <a href="{{ route('roles.edit', $role->id) }}" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-600 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors active:scale-90" aria-label="Ubah peran" wire:navigate>
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </a>
                            <form id="delete-form-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-{{ $role->id }}', '{{ addslashes($role->name) }}')" class="inline-flex items-center justify-center size-9 rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors active:scale-90" aria-label="Hapus">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-14 h-14 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                    <span class="material-symbols-rounded text-[28px] text-zinc-400">admin_panel_settings</span>
                                </div>
                                <p class="font-medium text-zinc-600 dark:text-zinc-300">Peran tidak ditemukan.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            {{-- Mobile/tablet-portrait: card list --}}
            <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
                @forelse($roles as $role)
                    <div class="card-surface p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex flex-col">
                                <span class="font-bold text-apeiron-ink dark:text-zinc-100 text-sm">{{ $role->name }}</span>
                                <span class="text-xs text-zinc-500 font-mono">{{ $role->role_code }}</span>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                @php
                                    $permsCount = count(array_filter($role->permissions ?? []));
                                @endphp
                                <x-ui.badge variant="primary">{{ $permsCount }} Akses</x-ui.badge>
                                <x-ui.badge :variant="$role->is_active ? 'success' : 'destructive'">
                                    {{ $role->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-ui.badge>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                            <div class="flex-1">
                                <span class="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider block mb-0.5">Pengguna</span>
                                <span class="text-xs text-apeiron-ink dark:text-zinc-200">{{ $role->users_count ?? 0 }} Terdaftar</span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <a href="{{ route('roles.edit', $role->id) }}" class="size-8 flex items-center justify-center rounded-lg text-zinc-600 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors" aria-label="Ubah peran" wire:navigate>
                                    <span class="material-symbols-rounded text-[18px]">edit</span>
                                </a>
                                <form id="delete-form-mobile-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $role->id }}', '{{ addslashes($role->name) }}')" class="size-8 flex items-center justify-center rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 transition-colors" aria-label="Hapus">
                                        <span class="material-symbols-rounded text-[18px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-zinc-400 text-sm flex flex-col items-center justify-center">
                        <div class="w-12 h-12 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-[24px] text-zinc-400">admin_panel_settings</span>
                        </div>
                        <p class="font-medium text-zinc-600 dark:text-zinc-300">Peran tidak ditemukan.</p>
                    </div>
                @endforelse
            </div>
            <div class="p-4 border-t border-zinc-200/80 dark:border-zinc-800 shrink-0">
                {{ $roles->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
