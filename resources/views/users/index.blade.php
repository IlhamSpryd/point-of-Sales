<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Pengguna</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola pengguna dan akses sistem</p>
        </div>
        
        <x-list-toolbar search-action="{{ route('users.index') }}" search-placeholder="Cari pengguna...">
            <x-slot:actions>
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                <a href="{{ route('users.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Pengguna
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
        <div class="overflow-auto flex-1">
            <table class="data-table relative">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">ID Karyawan</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Karyawan</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kontak</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Peran & Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Bergabung Pada</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($users as $user)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4 font-medium text-[#37352F] text-sm">{{ $user->employee_id }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-[#F1F1EF] text-[#37352F] flex items-center justify-center font-bold text-xs border border-[#E9E9E7] shrink-0">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium text-[#37352F]">{{ $user->name }}</span>
                                        <span class="text-xs text-[#787774]">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#37352F]">{{ $user->phone_number ?: '—' }}</td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col items-start gap-1.5">
                                    @if($user->role)
                                        <x-badge type="info">{{ $user->role->name }}</x-badge>
                                    @else
                                        <x-badge type="secondary">Belum Ditentukan</x-badge>
                                    @endif
                                    
                                    <x-badge :type="$user->is_active ? 'success' : 'danger'">
                                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-badge>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $user->join_date ? $user->join_date->format('M d, Y') : ($user->created_at?->format('M d, Y') ?? '—') }}</td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('users.edit', $user->id) }}" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200 active:scale-90" aria-label="Ubah pengguna" wire:navigate>
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </a>
                                @if(auth()->id() !== $user->id)
                                <form id="delete-form-{{ $user->id }}" action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $user->id }}', '{{ addslashes($user->name) }}')" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90" aria-label="Hapus">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">group</span>
                                    </div>
                                    <p class="font-medium">Pengguna tidak ditemukan.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">
            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>
