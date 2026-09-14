<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Produk <!-- Standarisasi bahasa UjiKom --></h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Kelola inventaris produk Anda <!-- Standarisasi bahasa UjiKom --></p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('products.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Cari produk..." class="pl-10 h-10 w-full" /> <!-- Standarisasi bahasa UjiKom -->
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded">download</span> Ekspor <!-- Standarisasi bahasa UjiKom -->
                    </x-button>
                </a>
                
                {{-- Tombol tambah/edit/hapus hanya untuk Administrator. Kasir & Pimpinan hanya boleh MELIHAT data (read-only), sesuai role:Administrator,Kasir,Pimpinan pada route ini — jika tombol tetap tampil, klik akan berujung error 403. --}}
                @if(auth()->user()->role?->name === 'Administrator')
                    <a href="{{ route('products.create') }}" class="flex-1 sm:flex-none">
                        <x-button variant="primary" type="button" class="w-full h-10">
                            <span class="material-symbols-rounded">add</span> Tambah Produk <!-- Standarisasi bahasa UjiKom -->
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

    <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/50">
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Produk <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Kategori <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Harga <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Stok <!-- Standarisasi bahasa UjiKom --></th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Status <!-- Standarisasi bahasa UjiKom --></th>
                        @if(auth()->user()->role?->name === 'Administrator')
                            <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Aksi <!-- Standarisasi bahasa UjiKom --></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($products as $product)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if($product->product_photo)
                                        <img src="{{ asset('storage/' . $product->product_photo) }}" alt="{{ $product->product_name }}" class="w-10 h-10 rounded-lg object-cover border border-zinc-100 dark:border-zinc-700">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                            <span class="material-symbols-rounded">image</span>
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white text-sm">{{ $product->product_name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $product->category ? $product->category->category_name : '-' }}</td>
                            <td class="px-6 py-4 text-sm font-semibold text-zinc-900 dark:text-white">Rp {{ number_format($product->product_price, 0, ',', '.') }}</td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $product->stock }}</td>
                            <td class="px-6 py-4">
                                @if($product->is_active)
                                    <x-badge type="success">Aktif <!-- Standarisasi bahasa UjiKom --></x-badge>
                                @else
                                    <x-badge type="secondary">Nonaktif <!-- Standarisasi bahasa UjiKom --></x-badge>
                                @endif
                            </td>
                            @if(auth()->user()->role?->name === 'Administrator')
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('products.edit', $product->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 transition-colors inline-block"><span class="material-symbols-rounded">edit</span></a>
                                    <form id="delete-form-{{ $product->id }}" action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        {{-- Menggunakan type="button" dan onclick untuk memanggil Swal.fire (tidak menggunakan confirm() bawaan) --}}
                                        <button type="button" onclick="confirmDelete('delete-form-{{ $product->id }}', '{{ addslashes($product->product_name) }}')" class="p-1.5 text-zinc-400 hover:text-rose-600 transition-colors"><span class="material-symbols-rounded">delete</span></button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()->role?->name === 'Administrator' ? 6 : 5 }}" class="px-6 py-8 text-center text-zinc-500 text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <span class="material-symbols-rounded text-4xl mb-3 text-zinc-400">inventory_2</span>
                                    <p>Produk tidak ditemukan. <!-- Standarisasi bahasa UjiKom --></p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>
