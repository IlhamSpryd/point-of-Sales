<x-customer-layout>
    <div class="mb-6">
        <h1 class="text-xl font-bold tracking-tight">Menu Kopi</h1>
        <p class="text-sm text-zinc-500 mt-1">Pilih menu favoritmu, atur sesuai selera.</p>
    </div>

    {{-- Alpine store global untuk state modal varian, dideklarasikan di root div --}}
    <div x-data="{ modalOpen: false, activeProduct: null }">

        <div class="grid grid-cols-2 gap-4">
            @foreach($products as $product)
                {{-- Kartu produk minimalis: foto, nama, harga. Klik → buka modal varian --}}
                <button type="button"
                        @click="activeProduct = {{ $product->id }}; modalOpen = true"
                        class="text-left bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 overflow-hidden hover:shadow-md transition-shadow">
                    <div class="aspect-square bg-zinc-50 dark:bg-zinc-800">
                        @if($product->product_photo)
                            <img src="{{ asset('storage/'.$product->product_photo) }}" class="w-full h-full object-cover" alt="{{ $product->product_name }}">
                        @endif
                    </div>
                    <div class="p-3">
                        <h3 class="text-sm font-semibold line-clamp-1">{{ $product->product_name }}</h3>
                        <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">Rp {{ number_format($product->product_price, 0, ',', '.') }}</p>
                    </div>
                </button>
            @endforeach
        </div>

        {{-- Modal varian: hanya di-render sekali, kontennya berubah sesuai activeProduct --}}
        @include('customer.menu.partials.variant-modal')
    </div>
</x-customer-layout>
