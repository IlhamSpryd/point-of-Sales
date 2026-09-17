<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }} - Menu</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
{{--
    Layout khusus pelanggan: TIDAK ada sidebar admin, TIDAK ada menu login.
    Sengaja dibuat minimalis (mono-column, mobile-first) karena target
    device utama adalah HP pelanggan yang scan QR code di meja.
--}}
<body class="antialiased bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 font-sans min-h-screen">

    {{-- Top bar minimalis, sticky, gaya Apple/Notion: tipis, tanpa shadow tebal --}}
    <header class="sticky top-0 z-30 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-xl border-b border-zinc-100 dark:border-zinc-800 h-14 flex items-center justify-between px-4">
        <span class="font-bold text-sm tracking-tight">{{ config('app.name') }}</span>

        {{-- Tombol keranjang mengambang dengan badge jumlah item --}}
        <a href="{{ route('customer.cart.index') }}" class="relative p-2 rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
            <span class="material-symbols-rounded">shopping_bag</span>
            <span x-data="{ count: 0 }"
                  x-init="count = window.customerCartCount ?? 0"
                  x-show="count > 0"
                  x-text="count"
                  x-cloak
                  class="absolute -top-1 -right-1 bg-zinc-900 text-white text-[10px] font-bold w-5 h-5 rounded-full flex items-center justify-center"></span>
        </a>
    </header>

    <main class="max-w-2xl mx-auto p-4">
        {{ $slot }}
    </main>

</body>
</html>
