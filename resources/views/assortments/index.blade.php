@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="Asortimen & Override Harga Cabang"
            description="Atur ketersediaan produk per cabang dan harga jual khusus tingkat cabang tanpa duplikasi data master produk.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Katalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Asortimen Cabang</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <form method="GET" action="{{ route('assortments.index') }}" class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-zinc-500 whitespace-nowrap">Pilih Cabang:</span>
                    <select id="store_filter" name="store_id" onchange="this.form.submit()"
                        class="h-10 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 text-sm bg-white dark:bg-zinc-800 font-medium text-zinc-800 dark:text-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" {{ (string)$selectedStoreId === (string)$s->id ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
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

        <div class="card-surface overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200/80 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Produk</th>
                            <th class="px-6 py-4">Kategori</th>
                            <th class="px-6 py-4 text-right">Harga Dasar</th>
                            <th class="px-6 py-4 text-center">Tersedia di Cabang</th>
                            <th class="px-6 py-4 text-right">Harga Khusus Cabang</th>
                            @if($canManage)
                                <th class="px-6 py-4 text-right">Simpan</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                        @forelse($products as $prod)
                            @php
                                $assortment = $prod->storeAssortments->first();
                                $isAvailable = $assortment ? $assortment->is_available : true;
                                $overridePrice = $assortment ? $assortment->store_price_override : null;
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if($prod->product_photo)
                                            <img src="{{ asset('storage/' . $prod->product_photo) }}" alt="{{ $prod->product_name }}" class="w-10 h-10 rounded-xl object-cover border border-zinc-200 dark:border-zinc-700">
                                        @else
                                            <div class="w-10 h-10 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                                <span class="material-symbols-rounded">inventory_2</span>
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-semibold text-sm text-apeiron-ink dark:text-white">{{ $prod->product_name }}</div>
                                            <div class="font-mono text-xs text-zinc-400">{{ $prod->product_code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-400">{{ $prod->category?->category_name ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-right text-zinc-900 dark:text-white font-mono">
                                    Rp {{ number_format((float) $prod->product_price, 0, ',', '.') }}
                                </td>

                                <td colspan="{{ $canManage ? 3 : 2 }}" class="p-0">
                                    @if($canManage && $selectedStoreId)
                                        <form action="{{ route('assortments.update') }}" method="POST" class="flex items-center justify-between w-full h-full px-6 py-3">
                                            @csrf
                                            <input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
                                            <input type="hidden" name="product_id" value="{{ $prod->id }}">

                                            <!-- Switch Ketersediaan -->
                                            <div class="w-36 text-center">
                                                <input type="hidden" name="is_available" value="0">
                                                <label class="inline-flex items-center cursor-pointer gap-2">
                                                    <input type="checkbox" name="is_available" value="1" {{ $isAvailable ? 'checked' : '' }}
                                                        class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary h-4 w-4">
                                                    <x-ui.badge variant="{{ $isAvailable ? 'success' : 'danger' }}" size="xs">
                                                        {{ $isAvailable ? 'Tersedia' : 'Dinonaktifkan' }}
                                                    </x-ui.badge>
                                                </label>
                                            </div>

                                            <!-- Override Harga Cabang -->
                                            <div class="w-48 text-right">
                                                <input type="number" name="store_price_override" value="{{ $overridePrice }}" step="0.01" min="0"
                                                    placeholder="Gunakan Dasar"
                                                    class="w-full text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 text-right font-mono text-zinc-800 dark:text-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                                            </div>

                                            <!-- Tombol Simpan Baris -->
                                            <div class="w-20 text-right">
                                                <x-ui.button variant="secondary" size="xs" type="submit">
                                                    Simpan
                                                </x-ui.button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="flex items-center justify-between w-full px-6 py-4">
                                            <div class="w-36 text-center">
                                                <x-ui.badge variant="{{ $isAvailable ? 'success' : 'danger' }}" size="sm">
                                                    {{ $isAvailable ? 'Tersedia' : 'Dinonaktifkan' }}
                                                </x-ui.badge>
                                            </div>
                                            <div class="w-48 text-right text-sm font-semibold text-zinc-900 dark:text-white font-mono">
                                                {{ $overridePrice ? 'Rp ' . number_format((float)$overridePrice, 0, ',', '.') : 'Harga Dasar' }}
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">storefront</span>
                                    Tidak ada produk ditemukan untuk cabang ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-zinc-200/80 dark:border-zinc-800">
                {{ $products->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
