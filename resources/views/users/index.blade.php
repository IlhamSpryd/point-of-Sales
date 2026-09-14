<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Pengguna <!-- Standarisasi bahasa UjiKom --></h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Kelola pengguna dan akses sistem <!-- Standarisasi bahasa UjiKom --></p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('users.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari pengguna..." class="pl-10 h-10 w-full" /> <!-- Standarisasi bahasa UjiKom -->
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded">download</span> Ekspor <!-- Standarisasi bahasa UjiKom -->
                    </x-button>
                </a>
                
                <a href="{{ route('users.create') }}" class="flex-1 sm:flex-none">
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded">add</span> Tambah Pengguna <!-- Standarisasi bahasa UjiKom -->
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

    <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/50">
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Nama <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Email <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Peran <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Bergabung Pada <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Aksi <!-- Standarisasi bahasa UjiKom --></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($users as $user)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $user->email }}</td>
                            <td class="px-6 py-4">
                                @if($user->role)
                                    <x-badge type="info">{{ $user->role->name }}</x-badge>
                                @else
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-zinc-50/50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 border border-zinc-100 dark:border-zinc-700">
                                        Belum Ditentukan <!-- Standarisasi bahasa UjiKom -->
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $user->created_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('users.edit', $user->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 transition-colors inline-block"><span class="material-symbols-rounded">edit</span></a>
                                @if(auth()->id() !== $user->id)
                                <form id="delete-form-{{ $user->id }}" action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    {{-- Menggunakan type="button" dan onclick untuk memanggil Swal.fire (tidak menggunakan confirm() bawaan) --}}
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $user->id }}', '{{ addslashes($user->name) }}')" class="p-1.5 text-zinc-400 hover:text-rose-600 transition-colors"><span class="material-symbols-rounded">delete</span></button>
                                </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-zinc-500 text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <span class="material-symbols-rounded text-4xl mb-3 text-zinc-400">group</span>
                                    <p>Pengguna tidak ditemukan. <!-- Standarisasi bahasa UjiKom --></p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>
