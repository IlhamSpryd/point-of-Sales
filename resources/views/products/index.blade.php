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
        
        <x-list-toolbar search-action="{{ route('products.index') }}" search-placeholder="Cari produk...">
            <x-slot:actions>
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">download</span> Ekspor
                    </x-button>
                </a>
                @if($canManage)
                    <a href="{{ route('products.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                        <x-button variant="primary" type="button" class="w-full h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Produk
                        </x-button>
                    </a>
                @endif
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
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block overflow-auto flex-1 table-scroll-shadow">
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
                                        <img src="{{ asset('storage/' . $product->product_photo) }}" alt="{{ $product->product_name }}" loading="lazy" class="w-10 h-10 rounded-xl object-cover border border-[#E9E9E7]">
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
                                    <a href="{{ route('products.edit', $product->id) }}" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200 active:scale-90" aria-label="Ubah produk" wire:navigate>
                                        <span class="material-symbols-rounded text-[20px]">edit</span>
                                    </a>
                                    <form id="delete-form-{{ $product->id }}" action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" onclick="confirmDelete('delete-form-{{ $product->id }}', '{{ addslashes($product->product_name) }}')" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90" aria-label="Hapus">
                                            <span class="material-symbols-rounded text-[20px]">delete</span>
                                        </button>
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

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($products as $product)
                <div class="card-surface p-4">
                    <div class="flex items-start gap-3">
                        @if($product->product_photo)
                            <img src="{{ asset('storage/' . $product->product_photo) }}" alt="{{ $product->product_name }}" loading="lazy" class="w-16 h-16 rounded-xl object-cover border border-[#E9E9E7] shrink-0">
                        @else
                            <div class="w-16 h-16 rounded-xl bg-[#F1F1EF] flex items-center justify-center text-[#C4C3C0] shrink-0">
                                <span class="material-symbols-rounded">image</span>
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <span class="font-bold text-[#37352F] text-sm truncate pr-2">{{ $product->product_name }}</span>
                                @if($product->is_active)
                                    <x-badge type="success">Aktif</x-badge>
                                @else
                                    <x-badge type="secondary">Nonaktif</x-badge>
                                @endif
                            </div>
                            <span class="text-xs text-[#787774] block mb-1">{{ $product->category ? $product->category->category_name : '-' }}</span>
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-semibold text-[#37352F]">Rp {{ number_format($product->product_price, 0, ',', '.') }}</span>
                                <span class="text-xs text-[#787774]">Stok: <span class="font-bold text-[#37352F]">{{ $product->stock }}</span></span>
                            </div>
                        </div>
                    </div>
                    @if($canManage)
                        <div class="flex items-center justify-end gap-1 mt-3 pt-3 border-t border-[#E9E9E7]">
                            <a href="{{ route('products.edit', $product->id) }}" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Ubah produk" wire:navigate>
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </a>
                            <form id="delete-form-mobile-{{ $product->id }}" action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $product->id }}', '{{ addslashes($product->product_name) }}')" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200" aria-label="Hapus">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-[#9B9A97] text-sm flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                        <span class="material-symbols-rounded text-[24px] text-[#C4C3C0]">inventory_2</span>
                    </div>
                    <p class="font-medium">Produk tidak ditemukan.</p>
                </div>
            @endforelse
        </div>
        <div class="p-4 border-t border-yovel-border shrink-0">
            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>
