<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Peran</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola peran sistem</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('roles.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[#9B9A97]">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari peran..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                
                <a href="{{ route('roles.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Peran
                    </x-button>
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <div class="overflow-auto flex-1">
            <table class="data-table relative">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">ID</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Peran</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Hak Akses & Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Pengguna</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($roles as $role)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4 font-medium text-[#37352F] text-sm">{{ $role->role_code }}</td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-[#37352F]">{{ $role->name }}</span>
                                    <span class="text-xs text-[#787774]">{{ $role->description ?: '—' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col items-start gap-1.5">
                                    @php
                                        $permsCount = count(array_filter($role->permissions ?? []));
                                    @endphp
                                    <x-badge type="info">{{ $permsCount }} Akses</x-badge>
                                    
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium {{ $role->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                        {{ $role->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">
                                {{ $role->users_count ?? 0 }} Pengguna
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('roles.edit', $role->id) }}" class="p-1.5 text-[#9B9A97] hover:text-[#37352F] transition-colors duration-200 inline-block" wire:navigate><span class="material-symbols-rounded">edit</span></a>
                                <form id="delete-form-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $role->id }}', '{{ addslashes($role->name) }}')" class="p-1.5 text-[#9B9A97] hover:text-rose-600 transition-colors duration-200"><span class="material-symbols-rounded">delete</span></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">admin_panel_settings</span>
                                    </div>
                                    <p class="font-medium">Peran tidak ditemukan.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">
            {{ $roles->links() }}
        </div>
    </div>
</x-app-layout>
