<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Pengguna</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola pengguna dan akses sistem</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('users.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[#9B9A97]">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari pengguna..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                
                <a href="{{ route('users.create') }}" class="flex-1 sm:flex-none">
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Pengguna
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
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Email</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Peran</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Bergabung Pada</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($users as $user)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-[#F1F1EF] text-[#37352F] flex items-center justify-center font-bold text-xs border border-[#E9E9E7]">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <span class="text-sm font-medium text-[#37352F]">{{ $user->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $user->email }}</td>
                            <td class="px-6 py-4">
                                @if($user->role)
                                    <x-badge type="info">{{ $user->role->name }}</x-badge>
                                @else
                                    <x-badge type="secondary">Belum Ditentukan</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $user->created_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('users.edit', $user->id) }}" class="p-1.5 text-[#9B9A97] hover:text-[#37352F] transition-colors duration-200 inline-block"><span class="material-symbols-rounded">edit</span></a>
                                @if(auth()->id() !== $user->id)
                                <form id="delete-form-{{ $user->id }}" action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $user->id }}', '{{ addslashes($user->name) }}')" class="p-1.5 text-[#9B9A97] hover:text-rose-600 transition-colors duration-200"><span class="material-symbols-rounded">delete</span></button>
                                </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
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
