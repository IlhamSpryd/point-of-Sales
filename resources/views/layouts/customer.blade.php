<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    {{-- SENGAJA tanpa maximum-scale / user-scalable=no: pinch-zoom wajib aktif (WCAG 1.4.4). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? trim(strip_tags((string) $title)) : 'Pesan' }} — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
{{--
    Layout khusus pelanggan (mobile-first, satu kolom). Tanpa sidebar admin, tanpa login.
    Halaman anak:
      <x-customer-layout back-url="{{ $url }}">
          <x-slot:title>Keranjang</x-slot:title>
          ...konten...
      </x-customer-layout>
--}}
<body class="antialiased bg-gray-50 text-yovel-ink font-sans">
    <div class="mx-auto max-w-md min-h-screen bg-yovel-bg relative shadow-2xl sm:border-x sm:border-yovel-border pt-safe">
        <header class="sticky top-0 z-30 h-14 flex items-center gap-1 px-3 bg-yovel-bg/80 backdrop-blur-xl border-b border-yovel-border">
            @if ($backUrl = $attributes->get('back-url'))
                <a href="{{ $backUrl }}" aria-label="Kembali"
                   class="w-11 h-11 -ml-1 flex items-center justify-center rounded-full hover:bg-yovel-surface active:scale-95 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-yovel-ink">
                    <span class="material-symbols-rounded">arrow_back</span>
                </a>
            @endif
    
            <span class="font-brand font-semibold text-[17px] tracking-tight truncate {{ $backUrl ? '' : 'ml-1' }}">
                {{ $title ?? config('app.name') }}
            </span>
    
            @if (session('current_table_name'))
                <span class="ml-auto shrink-0 inline-flex items-center gap-1.5 text-[11px] font-semibold text-yovel-muted bg-white border border-yovel-border rounded-full px-3 py-1.5 shadow-sm">
                    <span class="material-symbols-rounded text-[16px]">table_restaurant</span>
                    {{ session('current_table_name') }}
                </span>
            @endif
        </header>
    
        <main class="px-4 pt-5 pb-8">
            {{ $slot }}
        </main>
    
        {{-- Toast global. Pemakaian dari Alpine mana pun: $dispatch('toast', { message: 'Teks' }) --}}
        <div x-data="{ show: false, message: '', timer: null }"
             x-on:toast.window="message = $event.detail.message; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 3200)"
             x-show="show" x-cloak role="status"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="fixed inset-x-4 bottom-32 z-50 max-w-sm mx-auto rounded-2xl bg-yovel-ink text-white text-sm font-medium px-4 py-3 shadow-xl text-center">
            <span x-text="message"></span>
        </div>
    </div>
</body>
</html>
