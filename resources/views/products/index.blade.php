@php
    // Izin kelola katalog (tambah/ubah/hapus). Nama role harus sama persis dengan tabel roles.
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp
<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Produk</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola inventaris produk Anda</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('products.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[#9B9A97]">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari produk..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                
                @if($canManage)
                    <a href="{{ route('products.create') }}" class="flex-1 sm:flex-none">
                        <x-button variant="primary" type="button" class="w-full h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Produk
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
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Produk</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kategori</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Harga</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Stok</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                        @if($canManage)
                            <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($products as $product)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if($product->product_photo)
                                        <img src="{{ asset('storage/' . $product->product_photo) }}" alt="{{ $product->product_name }}" class="w-10 h-10 rounded-xl object-cover border border-[#E9E9E7]">
                                    @else
                                        <div class="w-10 h-10 rounded-xl bg-[#F1F1EF] flex items-center justify-center text-[#C4C3C0]">
                                            <span class="material-symbols-rounded">image</span>
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-[#37352F] text-sm">{{ $product->product_name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $product->category ? $product->category->category_name : '-' }}</td>
                            <td class="px-6 py-4 text-sm font-semibold text-[#37352F]">Rp {{ number_format($product->product_price, 0, ',', '.') }}</td>
                            <td class="px-6 py-4 text-sm text-[#787774]">{{ $product->stock }}</td>
                            <td class="px-6 py-4">
                                @if($product->is_active)
                                    <x-badge type="success">Aktif</x-badge>
                                @else
                                    <x-badge type="secondary">Nonaktif</x-badge>
                                @endif
                            </td>
                            @if($canManage)
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('products.edit', $product->id) }}" class="p-1.5 text-[#9B9A97] hover:text-[#37352F] transition-colors duration-200 inline-block"><span class="material-symbols-rounded">edit</span></a>
                                    <form id="delete-form-{{ $product->id }}" action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" onclick="confirmDelete('delete-form-{{ $product->id }}', '{{ addslashes($product->product_name) }}')" class="p-1.5 text-[#9B9A97] hover:text-rose-600 transition-colors duration-200"><span class="material-symbols-rounded">delete</span></button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">inventory_2</span>
                                    </div>
                                    <p class="font-medium">Produk tidak ditemukan.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">
            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>
