@php
    $visibleCategories = $categories->filter(fn($c) => $products->contains('category_id', $c->id));
    $tableName = session('current_table_name');
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    {{-- Tanpa maximum-scale / user-scalable=no: pinch-zoom wajib aktif (WCAG 1.4.4). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Menu — {{ config('app.name', 'Yovel Coffee & Cafe') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            -webkit-tap-highlight-color: transparent;
        }
    </style>
</head>

<body class="antialiased bg-yovel-bg text-yovel-ink font-sans pt-safe">
    <div class="min-h-screen pb-32" x-data="{
        activeCategory: 'all',
        toastMsg: '',
        toastOn: false,
        toastTimer: null,
        notify(message) {
            this.toastMsg = message;
            this.toastOn = true;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toastOn = false, 3200);
        }
    }" x-on:toast.window="notify($event.detail.message)">

        {{-- Header --}}
        <header
            class="sticky top-0 z-40 h-14 flex items-center justify-between gap-3 px-4 bg-yovel-bg/80 backdrop-blur-xl border-b border-yovel-border">
            <h1 class="font-brand font-bold text-lg tracking-tight truncate">Yovel Coffee</h1>
            @if ($tableName)
                <span
                    class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-yovel-border bg-white px-3 py-1.5 text-xs font-semibold text-yovel-muted shadow-sm">
                    <span class="material-symbols-rounded text-[16px]">table_restaurant</span>
                    {{ $tableName }}
                </span>
            @endif
        </header>

        {{-- Kategori --}}
        <nav aria-label="Kategori menu" class="sticky top-14 z-30 bg-yovel-bg/90 backdrop-blur-md">
            <div class="flex gap-2 overflow-x-auto scrollbar-hide px-4 py-3">
                <button type="button" @click="activeCategory = 'all'"
                    :aria-pressed="(activeCategory === 'all').toString()"
                    :class="activeCategory === 'all' ? 'bg-yovel-ink text-white border-yovel-ink shadow-sm' :
                        'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-surface'"
                    class="shrink-0 min-h-11 px-5 rounded-full border text-sm font-semibold whitespace-nowrap transition-all duration-200 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                    Semua
                </button>
                @foreach ($visibleCategories as $cat)
                    <button type="button" @click="activeCategory = '{{ $cat->id }}'"
                        :aria-pressed="(activeCategory === '{{ $cat->id }}').toString()"
                        :class="activeCategory === '{{ $cat->id }}' ?
                            'bg-yovel-ink text-white border-yovel-ink shadow-sm' :
                            'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-surface'"
                        class="shrink-0 min-h-11 px-5 rounded-full border text-sm font-semibold whitespace-nowrap transition-all duration-200 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                        {{ $cat->category_name }}
                    </button>
                @endforeach
            </div>
        </nav>

        {{-- Grid produk --}}
        <main class="px-4 pt-1">
            @if ($products->isEmpty())
                <div class="card-surface flex flex-col items-center px-6 py-16 text-center">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-3xl bg-yovel-surface">
                        <span class="material-symbols-rounded text-[32px] text-primary-300">coffee</span>
                    </div>
                    <h2 class="text-lg font-bold">Menu belum tersedia</h2>
                    <p class="mt-1 text-sm text-yovel-muted">Silakan panggil staf kami untuk bantuan.</p>
                </div>
            @else
                <ul class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach ($products as $product)
                        @php $soldOut = $product->stock <= 0; @endphp
                        <li x-show="activeCategory === 'all' || activeCategory === '{{ $product->category_id }}'"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100">
                            <button type="button"
                                @if ($soldOut) disabled @else @click="$dispatch('open-variant', { id: {{ $product->id }} })" @endif
                                class="group flex h-full w-full flex-col rounded-3xl border border-yovel-border bg-white p-3 text-left shadow-sm transition-all duration-200 hover:shadow-md active:scale-[0.98] disabled:cursor-not-allowed disabled:shadow-none disabled:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                                <span class="relative mb-3 block aspect-square overflow-hidden rounded-2xl bg-yovel-bg">
                                    @if ($product->product_photo)
                                        <img src="{{ asset('storage/' . $product->product_photo) }}" alt=""
                                            loading="lazy"
                                            class="h-full w-full object-cover transition-transform duration-500 ease-out {{ $soldOut ? 'grayscale opacity-60' : 'group-hover:scale-105' }}">
                                    @else
                                        <span class="flex h-full w-full items-center justify-center">
                                            <span
                                                class="material-symbols-rounded text-[48px] text-primary-300">coffee</span>
                                        </span>
                                    @endif
                                    @if ($soldOut)
                                        <span
                                            class="absolute left-2 top-2 rounded-lg bg-yovel-ink px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-white">Habis</span>
                                    @endif
                                </span>
                                <span
                                    class="mb-2 line-clamp-2 text-sm font-bold leading-tight {{ $soldOut ? 'text-yovel-muted' : '' }}">{{ $product->product_name }}</span>
                                <span class="mt-auto flex items-center justify-between pt-1">
                                    <span
                                        class="text-sm font-bold tracking-tight tabular-nums {{ $soldOut ? 'text-yovel-muted' : '' }}">Rp
                                        {{ number_format($product->product_price, 0, ',', '.') }}</span>
                                    @unless ($soldOut)
                                        <span
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-yovel-ink text-white shadow-sm transition-colors group-hover:bg-black">
                                            <span class="material-symbols-rounded text-[18px]">add</span>
                                        </span>
                                    @endunless
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </main>

        {{-- Tombol keranjang mengambang --}}
        <div x-data="{
            count: window.customerCartCount ?? {{ app(\App\Services\CartService::class)->getTotalQty() }},
            subtotal: {{ app(\App\Services\CartService::class)->getSubtotal() }}
        }"
            x-on:cart-updated.window="count = $event.detail.total_qty; subtotal = $event.detail.subtotal;"
            x-show="count > 0" x-cloak x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-400"
            x-transition:enter-start="translate-y-full opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-full opacity-0"
            class="fixed inset-x-4 bottom-[calc(env(safe-area-inset-bottom,0px)+1rem)] z-40 mx-auto max-w-2xl">
            <a href="{{ route('customer.cart.index') }}"
                class="flex min-h-14 w-full items-center justify-between rounded-2xl bg-yovel-ink px-5 font-semibold text-white shadow-xl transition-all hover:bg-black active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink focus-visible:ring-offset-2">
                <span class="rounded-lg bg-white/20 px-3 py-1 text-sm" x-text="count + ' Item'"></span>
                <span>Lihat Keranjang</span>
                <span class="tabular-nums" x-text="'Rp ' + Number(subtotal).toLocaleString('id-ID')"></span>
            </a>
        </div>

        {{-- Toast --}}
        <div x-show="toastOn" x-cloak role="status" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-4"
            class="fixed inset-x-4 bottom-28 z-[60] mx-auto max-w-sm rounded-2xl bg-yovel-ink px-4 py-3 text-center text-sm font-medium text-white shadow-xl">
            <span x-text="toastMsg"></span>
        </div>

        @include('customer.menu.partials.variant-modal')
    </div>
</body>

</html>
