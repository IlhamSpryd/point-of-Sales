@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Asortimen & Override Harga Cabang</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Atur ketersediaan produk per cabang dan harga jual khusus tingkat cabang tanpa duplikasi data master</p>
        </div>

        <form method="GET" action="{{ route('assortments.index') }}" class="flex items-center gap-2">
            <label for="store_filter" class="text-xs font-semibold text-[#787774] whitespace-nowrap">Pilih Cabang:</label>
            <select id="store_filter" name="store_id" onchange="this.form.submit()"
                class="h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm bg-white font-medium text-apeiron-ink focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}" {{ (string)$selectedStoreId === (string)$s->id ? 'selected' : '' }}>
                        {{ $s->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    @if(session('success'))
        <x-alert type="success" class="mb-4">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error" class="mb-4">{{ session('error') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Produk</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kategori</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Harga Dasar</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Tersedia di Cabang</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Harga Khusus Cabang</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Simpan</th>
                @endif
            </x-slot:head>

            @forelse($products as $prod)
                @php
                    $assortment = $prod->storeAssortments->first();
                    $isAvailable = $assortment ? $assortment->is_available : true; // Default backward-compatible: available
                    $overridePrice = $assortment ? $assortment->store_price_override : null;
                @endphp
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            @if($prod->product_photo)
                                <img src="{{ asset('storage/' . $prod->product_photo) }}" alt="{{ $prod->product_name }}" class="w-10 h-10 rounded-xl object-cover border border-[#E9E9E7]">
                            @else
                                <div class="w-10 h-10 rounded-xl bg-[#F1F1EF] flex items-center justify-center text-[#C4C3C0]">
                                    <span class="material-symbols-rounded">inventory_2</span>
                                </div>
                            @endif
                            <div>
                                <div class="font-semibold text-sm text-apeiron-ink">{{ $prod->product_name }}</div>
                                <div class="font-mono text-xs text-[#787774]">{{ $prod->product_code }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-[#787774]">{{ $prod->category?->category_name ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm font-medium text-right text-apeiron-ink tabular-nums">
                        Rp {{ number_format($prod->product_price, 0, ',', '.') }}
                    </td>

                    <td colspan="{{ $canManage ? 3 : 2 }}" class="p-0">
                        @if($canManage && $selectedStoreId)
                            <form action="{{ route('assortments.update') }}" method="POST" class="flex items-center justify-between w-full h-full px-6 py-3">
                                @csrf
                                <input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
                                <input type="hidden" name="product_id" value="{{ $prod->id }}">

                                {{-- Switch Ketersediaan --}}
                                <div class="w-36 text-center">
                                    <input type="hidden" name="is_available" value="0">
                                    <label class="inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="is_available" value="1" {{ $isAvailable ? 'checked' : '' }}
                                            class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink h-4 w-4">
                                        <span class="ml-2 text-xs font-semibold {{ $isAvailable ? 'text-success-700' : 'text-danger-600' }}">
                                            {{ $isAvailable ? 'Tersedia' : 'Dinonaktifkan' }}
                                        </span>
                                    </label>
                                </div>

                                {{-- Override Harga Cabang --}}
                                <div class="w-48 text-right">
                                    <input type="number" name="store_price_override" value="{{ $overridePrice }}" step="0.01" min="0"
                                        placeholder="Gunakan Dasar"
                                        class="w-full h-8 px-2.5 rounded-lg border border-[#E9E9E7] text-xs text-right tabular-nums focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                </div>

                                {{-- Tombol Simpan Baris --}}
                                <div class="w-20 text-right">
                                    <button type="submit"
                                        class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg bg-[#37352F] text-white hover:bg-black text-xs font-medium transition-colors">
                                        Simpan
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="flex items-center justify-between w-full px-6 py-4">
                                <div class="w-36 text-center">
                                    @if($isAvailable)
                                        <x-badge type="success">Tersedia</x-badge>
                                    @else
                                        <x-badge type="danger">Dinonaktifkan</x-badge>
                                    @endif
                                </div>
                                <div class="w-48 text-right text-sm font-semibold text-apeiron-ink tabular-nums">
                                    {{ $overridePrice ? 'Rp ' . number_format((float)$overridePrice, 0, ',', '.') : 'Harga Dasar' }}
                                </div>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                        <div class="flex flex-col items-center justify-center">
                            <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">storefront</span>
                            <p class="font-medium">Tidak ada produk ditemukan untuk cabang ini.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        <div class="p-4 border-t border-[#E9E9E7]">
            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>
