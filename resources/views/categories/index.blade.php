@php
    // Izin kelola katalog (tambah/ubah/hapus). Nama role harus sama persis dengan tabel roles.
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp
<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Kategori</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola kategori produk</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('categories.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[#9B9A97]">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kategori..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                
                @if($canManage)
                    <a href="{{ route('categories.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                        <x-button variant="primary" type="button" class="w-full h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Kategori
                        </x-button>
                    </a>
                @endif
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
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama Kategori</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Dibuat Pada</th>
                        @if($canManage)
                            <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($categories as $category)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4 font-medium text-[#37352F] text-sm">{{ $category->category_code }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-[#37352F] text-sm">{{ $category->category_name }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $category->created_at?->format('M d, Y') ?? '—' }}</td>
                            @if($canManage)
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('categories.edit', $category->id) }}" class="p-1.5 text-[#9B9A97] hover:text-[#37352F] transition-colors duration-200 inline-block" wire:navigate><span class="material-symbols-rounded">edit</span></a>
                                    <form id="delete-form-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" onclick="confirmDelete('delete-form-{{ $category->id }}', '{{ addslashes($category->category_name) }}')" class="p-1.5 text-[#9B9A97] hover:text-rose-600 transition-colors duration-200"><span class="material-symbols-rounded">delete</span></button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 4 : 3 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">category</span>
                                    </div>
                                    <p class="font-medium">Kategori tidak ditemukan.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">
            {{ $categories->links() }}
        </div>
    </div>
</x-app-layout>
