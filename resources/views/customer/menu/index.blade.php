<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Yovel Coffee & Cafe') }} - Menu</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400..900;1,400..900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            -webkit-tap-highlight-color: transparent;
        }
    </style>
</head>

<body class="antialiased selection:bg-gray-200 pt-safe bg-[#F7F7F5]">
<div class="min-h-screen bg-[#F7F7F5] text-[#37352F] pb-28 font-sans" 
     x-data="{ modalOpen: false, activeProduct: null, activeCategory: 'all' }">
    
    <!-- Sticky Header -->
    <header class="sticky top-0 z-40 bg-[#F7F7F5]/80 backdrop-blur-md border-b border-gray-200 px-4 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold tracking-tight text-center flex-1 font-brand">Yovel Coffee</h1>
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 bg-white border border-gray-200 px-3 py-1.5 rounded-lg shadow-sm">
            <span class="material-symbols-rounded text-[16px]">table_restaurant</span>
            Meja {{ \App\Models\Table::find(session('current_table_id'))?->table_name ?? '-' }}
        </span>
    </header>

    <!-- Horizontal Category Pills -->
    <div class="overflow-x-auto hide-scrollbar px-4 py-4 flex space-x-2 sticky top-[61px] z-30 bg-[#F7F7F5]/90 backdrop-blur-sm">
        <button type="button" @click="activeCategory = 'all'" 
                :class="activeCategory === 'all' ? 'border-gray-300 bg-white text-gray-900 shadow-sm' : 'border-transparent text-gray-500 bg-transparent hover:bg-gray-200'"
                class="px-5 py-2 rounded-full border text-sm font-semibold whitespace-nowrap active:scale-95 transition-all duration-200">
            Semua Menu
        </button>
        @foreach($categories as $cat)
        <button type="button" @click="activeCategory = '{{ $cat->id }}'"
                :class="activeCategory === '{{ $cat->id }}' ? 'border-gray-300 bg-white text-gray-900 shadow-sm' : 'border-transparent text-gray-500 bg-transparent hover:bg-gray-200'"
                class="px-5 py-2 rounded-full border text-sm font-medium whitespace-nowrap active:scale-95 transition-all duration-200">
            {{ $cat->category_name }}
        </button>
        @endforeach
    </div>

    <!-- Product Grid (2 Kolom Mobile, 4 Kolom Desktop) -->
    <div class="px-4 py-2 grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-5">
        @foreach($products as $product)
        <!-- Card Produk -->
        <div x-show="activeCategory === 'all' || activeCategory === '{{ $product->category_id }}'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-3xl p-3 border border-gray-100 shadow-sm flex flex-col active:scale-[0.98] transition cursor-pointer group hover:shadow-md" 
             @click="modalOpen = true; activeProduct = '{{ $product->id }}'">
            
            <div class="aspect-square bg-gray-50 rounded-2xl mb-3 flex items-center justify-center text-gray-300 overflow-hidden relative">
                @if($product->product_photo)
                    <img src="{{ asset('storage/'.$product->product_photo) }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out" alt="{{ $product->product_name }}" loading="lazy" />
                @else
                    <span class="material-symbols-rounded text-[48px] text-gray-300">coffee</span>
                @endif
            </div>
            <div class="flex flex-col flex-grow justify-between">
                <h3 class="font-bold text-sm leading-tight mb-2 line-clamp-2 text-gray-900">{{ $product->product_name }}</h3>
                <div class="mt-auto flex justify-between items-center pt-1">
                    <span class="font-bold text-sm tracking-tight">Rp {{ number_format($product->product_price, 0, ',', '.') }}</span>
                    <div class="bg-[#37352F] text-white rounded-full w-7 h-7 flex items-center justify-center shrink-0 shadow-sm group-hover:bg-black transition-colors">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <!-- Floating Cart Button -->
    <div x-data="{
                count: window.customerCartCount ?? {{ app(\App\Services\CartService::class)->getTotalQty() }},
                subtotal: {{ app(\App\Services\CartService::class)->getSubtotal() }}
         }"
         x-on:cart-updated.window="count = $event.detail.total_qty; subtotal = $event.detail.subtotal;"
         x-show="count > 0"
         x-cloak
         x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-400"
         x-transition:enter-start="translate-y-full opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-full opacity-0"
         class="fixed bottom-6 inset-x-4 z-40 max-w-2xl mx-auto">
         <a href="{{ route('customer.cart.index') }}" class="w-full bg-[#37352F] hover:bg-black text-white rounded-2xl py-4 font-semibold shadow-xl active:scale-[0.98] transition-all flex justify-between px-5 items-center">
             <span class="bg-white/20 px-3 py-1 rounded-lg text-sm" x-text="count + ' Item'"></span>
             <span>Lihat Keranjang</span>
             <span x-text="'Rp ' + subtotal.toLocaleString('id-ID')"></span>
         </a>
    </div>

    @include('customer.menu.partials.variant-modal')

</div>
</body>
</html>
