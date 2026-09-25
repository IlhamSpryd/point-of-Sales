@php
    $visibleCategories = $categories->filter(fn($c) => $products->contains('category_id', $c->id));
    $tableName = session('current_table_name');
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
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
    <div class="mx-auto min-h-screen max-w-md pb-32 sm:border-x sm:border-yovel-border" x-data="{
        activeCategory: 'all',
        searchQuery: '',
        toastMsg: '',
        toastOn: false,
        toastTimer: null,
        notify(message) {
            this.toastMsg = message;
            this.toastOn = true;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toastOn = false, 3200);
        }
    }"
        x-on:toast.window="notify($event.detail.message)">

        {{-- Header (Menambahkan Tombol Panggil Waiter & Riwayat) --}}
        <header
            class="sticky top-0 z-40 h-14 flex items-center justify-between gap-3 px-4 bg-yovel-bg/90 backdrop-blur-xl border-b border-yovel-border">
            <h1 class="font-brand font-bold text-lg tracking-tight truncate">Yovel Coffee</h1>

            <div class="flex items-center gap-2">
                {{-- PATCH FOR S-08/P-09: panggil waiter yang nyata, XSS-safe via @js() --}}
                @if ($tableName)
                    <button type="button" x-data="{ busy: false }" :disabled="busy" aria-label="Panggil Waiter"
                        @click="busy = true;
                          fetch(@js(route('customer.waiter.call')), { method: 'POST', headers: { 'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                            .then(r => r.json()).then(d => notify(d.message))
                            .catch(() => notify('Koneksi bermasalah. Silakan lambaikan tangan ke staf.'))
                            .finally(() => busy = false)"
                        class="flex items-center justify-center w-11 h-11 rounded-full bg-white border border-yovel-border text-yovel-muted hover:text-yovel-ink active:scale-90 transition-all shadow-sm">
                        <span class="material-symbols-rounded text-[18px]">room_service</span>
                    </button>
                @endif

                {{-- PATCH FOR P-09: Status Pesanan — link ke pesanan aktif jika ada --}}
                @php $activeOrders = session('customer_orders', []); @endphp
                @if (!empty($activeOrders))
                    <a href="{{ route('customer.checkout.success', end($activeOrders)) }}"
                        class="flex items-center justify-center w-11 h-11 rounded-full bg-white border border-yovel-border text-yovel-muted hover:text-yovel-ink active:scale-90 transition-all shadow-sm"
                        aria-label="Status Pesanan">
                        <span class="material-symbols-rounded text-[18px]">receipt_long</span>
                    </a>
                @endif

                @if ($tableName)
                    <span
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-yovel-border bg-white px-3 py-1.5 text-xs font-semibold text-yovel-muted shadow-sm">
                        <span class="material-symbols-rounded text-[14px]">table_restaurant</span>
                        {{ $tableName }}
                    </span>
                @endif
            </div>
        </header>

        <div class="px-4 pt-5 pb-2 space-y-4">
            {{-- Promo Banner --}}
            <div class="relative overflow-hidden rounded-3xl bg-yovel-ink text-white p-6 shadow-md mb-4">
                <div class="relative z-10">
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/20 backdrop-blur-md text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm border border-white/10">
                        <span class="material-symbols-rounded text-[14px] text-amber-300">stars</span> Spesial Hari Ini
                    </span>
                    <h2 class="font-bold text-xl leading-tight mb-1.5 text-white">Nikmati Promo Terbaik Kami</h2>
                    <p class="text-xs text-white/70 font-medium">Cek opsi diskon saat proses pembayaran.</p>
                </div>

                {{-- Elemen Dekoratif Premium --}}
                <div
                    class="absolute -bottom-10 -right-10 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none">
                </div>
                <div
                    class="absolute -top-10 -right-5 w-24 h-24 bg-amber-500/20 rounded-full blur-xl pointer-events-none">
                </div>
            </div>

            {{-- Search Bar (Realtime dengan Alpine) --}}
            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-yovel-muted pointer-events-none">
                    <span class="material-symbols-rounded text-[20px]">search</span>
                </span>
                <input type="text" x-model="searchQuery" placeholder="Cari minuman atau hidangan..."
                    class="w-full pl-12 pr-10 py-3.5 bg-white border border-yovel-border rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-yovel-ink transition-all shadow-sm placeholder:text-gray-400 font-medium">
                <button type="button" x-show="searchQuery.length > 0" @click="searchQuery = ''" x-cloak
                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-yovel-muted hover:text-yovel-ink">
                    <span class="material-symbols-rounded text-[18px]">close</span>
                </button>
            </div>
        </div>

        {{-- Kategori Dinamis --}}
        <nav aria-label="Kategori menu"
            class="sticky top-14 z-30 bg-yovel-bg/95 backdrop-blur-md pb-2 pt-1 border-b border-yovel-border/60 shadow-sm">
            <div class="flex gap-2.5 overflow-x-auto scrollbar-hide px-4 py-2 items-center">
                <button type="button" @click="activeCategory = 'all'"
                    :aria-pressed="(activeCategory === 'all').toString()"
                    :class="activeCategory === 'all' ? 'bg-[#37352F] text-white border-[#37352F] shadow-md' :
                        'bg-white text-gray-500 border-[#E9E9E7] hover:bg-gray-50'"
                    class="shrink-0 flex items-center justify-center h-11 px-5 rounded-full border text-sm font-semibold whitespace-nowrap transition-all duration-200 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]">
                    Semua Menu
                </button>
                @foreach ($visibleCategories as $cat)
                    <button type="button" @click="activeCategory = '{{ $cat->id }}'"
                        :aria-pressed="(activeCategory === '{{ $cat->id }}').toString()"
                        :class="activeCategory === '{{ $cat->id }}' ?
                            'bg-[#37352F] text-white border-[#37352F] shadow-md' :
                            'bg-white text-gray-500 border-[#E9E9E7] hover:bg-gray-50'"
                        class="shrink-0 flex items-center justify-center h-11 px-5 rounded-full border text-sm font-semibold whitespace-nowrap transition-all duration-200 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]">
                        {{ $cat->category_name }}
                    </button>
                @endforeach
            </div>
        </nav>

        {{-- Grid Produk --}}
        <main class="px-4 pt-4">
            @if ($products->isEmpty())
                <div class="card-surface flex flex-col items-center px-6 py-16 text-center">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-3xl bg-yovel-surface">
                        <span class="material-symbols-rounded text-[32px] text-primary-300">coffee</span>
                    </div>
                    <h2 class="text-lg font-bold">Menu belum tersedia</h2>
                    <p class="mt-1 text-sm text-yovel-muted">Silakan panggil staf kami untuk bantuan.</p>
                </div>
            @else
                <ul class="grid grid-cols-2 gap-3">
                    @foreach ($products as $product)
                        @php
                            // PATCH FOR S-18: gunakan sellableQuantity() jika tersedia, fallback ke stock.
                            $soldOut = method_exists($product, 'sellableQuantity')
                                ? $product->sellableQuantity() <= 0
                                : $product->stock <= 0;
                            // PATCH FOR U-08: gunakan kolom yang benar (product_description, bukan description).
                            $desc = $product->product_description ?: 'Racikan pilihan terbaik khas Yovel Coffee.';
                        @endphp

                        {{-- Logic Alpine: Tampilkan jika kategori cocok ATAU sedang mencari nama produk --}}
                        <li x-show="(activeCategory === 'all' || activeCategory === '{{ $product->category_id }}') && @js(mb_strtolower($product->product_name)).includes(searchQuery.toLowerCase())"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100">
                            <button type="button"
                                @if ($soldOut) disabled @else @click="$dispatch('open-variant', { id: {{ $product->id }} })" @endif
                                class="group flex h-full w-full flex-col rounded-3xl border border-yovel-border bg-white p-3 text-left shadow-sm transition-all duration-200 hover:shadow-md active:scale-[0.98] disabled:cursor-not-allowed disabled:shadow-none disabled:active:scale-100">
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
                                            class="absolute left-2 top-2 rounded-lg bg-yovel-ink px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-white backdrop-blur-md">Habis</span>
                                    @endif
                                </span>

                                {{-- Nama Produk --}}
                                <span
                                    class="mb-1 line-clamp-2 text-sm font-bold leading-tight {{ $soldOut ? 'text-yovel-muted' : '' }}">{{ $product->product_name }}</span>

                                {{-- Deskripsi Singkat --}}
                                <span
                                    class="mb-3 line-clamp-2 text-[10px] leading-snug text-gray-500 font-medium">{{ $desc }}</span>

                                {{-- Harga & Tombol --}}
                                <span class="mt-auto flex items-center justify-between pt-1 w-full">
                                    <span
                                        class="text-sm font-bold tracking-tight tabular-nums {{ $soldOut ? 'text-yovel-muted' : '' }}">Rp
                                        {{ number_format($product->product_price, 0, ',', '.') }}</span>
                                    @unless ($soldOut)
                                        <span
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#37352F] text-white shadow-sm transition-colors group-hover:bg-black">
                                            <span class="material-symbols-rounded text-[18px]">add</span>
                                        </span>
                                    @endunless
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                {{-- Pesan jika pencarian tidak ditemukan --}}
                <div x-show="searchQuery.length > 0 && !$el.previousElementSibling.innerText.toLowerCase().includes(searchQuery.toLowerCase())"
                    x-cloak class="col-span-full py-12 text-center text-yovel-muted text-sm mt-8">
                    Menu tidak ditemukan. Coba kata kunci lain.
                </div>
            @endif
        </main>

        {{-- Tombol Keranjang Mengambang --}}
        <div x-data="{
            count: window.customerCartCount ?? {{ app(\App\Services\CartService::class)->getTotalQty() }},
            subtotal: {{ app(\App\Services\CartService::class)->getSubtotal() }}
        }"
            x-on:cart-updated.window="count = $event.detail.total_qty; subtotal = $event.detail.subtotal;"
            x-show="count > 0" x-cloak x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-400"
            x-transition:enter-start="translate-y-full opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-full opacity-0"
            class="fixed inset-x-4 bottom-[calc(env(safe-area-inset-bottom,0px)+1rem)] z-40 mx-auto max-w-md">
            <a href="{{ route('customer.cart.index') }}"
                class="flex min-h-14 w-full items-center justify-between rounded-2xl bg-yovel-ink px-5 font-semibold text-white shadow-xl transition-all hover:bg-black active:scale-[0.98]"
                wire:navigate>
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
