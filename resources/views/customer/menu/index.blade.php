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

<body class="antialiased bg-yovel-bg text-yovel-ink selection:bg-yovel-ink selection:text-white pt-safe">
    <div x-data="{ modalOpen: false, activeProduct: null, activeCategory: 'all' }" class="pb-32 max-w-2xl mx-auto">

        <!-- Header -->
        <div class="px-5 pt-8 pb-6">
            <div class="flex items-center gap-2 mb-1">
                <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" class="text-yovel-ink">
                    <path d="M8 0a1 1 0 0 1 1 1v5.268l4.562-2.634a1 1 0 1 1 1 1.732L10 8l4.562 2.634a1 1 0 1 1-1 1.732L9 9.732V15a1 1 0 1 1-2 0V9.732l-4.562 2.634a1 1 0 1 1-1-1.732L6 8 1.438 5.366a1 1 0 0 1 1-1.732L7 6.268V1a1 1 0 0 1 1-1z"/>
                </svg>
                <h1 class="text-2xl tracking-tight leading-tight font-brand">
                    <span class="font-bold">Yovel Coffee</span><span class="font-normal text-yovel-muted ml-0.5"> & Cafe</span>
                </h1>
            </div>
            <div class="flex items-center gap-2 mt-2">
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-yovel-muted bg-white border border-yovel-border px-3 py-1.5 rounded-lg shadow-sm">
                    <span class="material-symbols-rounded text-[16px]">table_restaurant</span>
                    Meja {{ \App\Models\Table::find(session('current_table_id'))?->table_number ?? '-' }}
                </span>
            </div>
        </div>

        <!-- Categories Horizontal Scroll -->
        <div class="mb-6">
            <div class="flex overflow-x-auto scrollbar-hide gap-2 pb-2 px-5">
                <button type="button"
                        @click="activeCategory = 'all'"
                        :class="activeCategory === 'all' ? 'bg-primary-700 text-white border-transparent shadow-md' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg hover:text-yovel-ink'"
                        class="px-5 py-2.5 rounded-full text-sm font-medium whitespace-nowrap transition-all duration-200 border active:scale-95">
                    Semua
                </button>
                @foreach($categories as $cat)
                    <button type="button"
                            @click="activeCategory = '{{ $cat->id }}'"
                            :class="activeCategory === '{{ $cat->id }}' ? 'bg-primary-700 text-white border-transparent shadow-md' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg hover:text-yovel-ink'"
                            class="px-5 py-2.5 rounded-full text-sm font-medium whitespace-nowrap transition-all duration-200 border active:scale-95">
                        {{ $cat->category_name }}
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Products Grid -->
        <div class="px-5">
            <div class="grid grid-cols-2 gap-3.5">
                @foreach($products as $product)
                    <div x-show="activeCategory === 'all' || activeCategory === '{{ $product->category_id }}'"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        @click="activeProduct = {{ $product->id }}; modalOpen = true"
                        class="bg-white border border-yovel-border rounded-2xl overflow-hidden flex flex-col shadow-sm transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 active:scale-95 cursor-pointer group">

                        <div class="aspect-square bg-yovel-surface w-full relative overflow-hidden">
                            @if($product->product_photo)
                                <img src="{{ asset('storage/'.$product->product_photo) }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out" alt="{{ $product->product_name }}" loading="lazy" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <span class="material-symbols-rounded text-[48px] text-yovel-border">coffee</span>
                                </div>
                            @endif
                        </div>

                        <div class="p-3.5 flex flex-col flex-grow justify-between">
                            <div>
                                <h3 class="font-semibold text-yovel-ink text-[13px] leading-tight mb-1.5 line-clamp-2">{{ $product->product_name }}</h3>
                            </div>
                            <div class="flex items-center justify-between mt-2">
                                <span class="font-bold text-yovel-ink text-sm tracking-tight">Rp {{ number_format($product->product_price, 0, ',', '.') }}</span>
                                <button type="button" class="w-8 h-8 rounded-xl bg-primary-700 text-white flex items-center justify-center group-hover:bg-primary-900 transition-all duration-200 active:scale-90 shadow-sm shrink-0">
                                    <span class="material-symbols-rounded" style="font-size: 18px;">add</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @include('customer.menu.partials.variant-modal')

        <!-- Floating Bottom Cart Bar -->
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
             class="fixed bottom-0 left-0 right-0 p-5 bg-white/90 backdrop-blur-xl border-t border-yovel-border shadow-[0_-10px_40px_-10px_rgba(0,0,0,0.08)] pb-safe z-40 max-w-2xl mx-auto">

            <a href="{{ route('customer.cart.index') }}"
                class="w-full bg-primary-700 hover:bg-primary-900 text-white py-3.5 rounded-2xl font-medium shadow-lg transition-all duration-200 flex items-center justify-between px-5 active:scale-[0.98]">

                <div class="flex items-center gap-2">
                    <span class="bg-white/20 px-2.5 py-0.5 rounded-lg text-sm font-bold" x-text="count + ' Item'"></span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="font-bold" x-text="'Rp ' + subtotal.toLocaleString('id-ID')"></span>
                    <span class="material-symbols-rounded" style="font-size: 20px;">chevron_right</span>
                </div>
            </a>
        </div>
    </div>
</body>
</html>
