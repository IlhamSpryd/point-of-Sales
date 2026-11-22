<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    {{-- Scalable viewport for accessibility (WCAG 1.4.4) --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? trim(strip_tags((string) $title)) : 'Order' }} — {{ config('app.name') }}</title>
    <x-favicon />

    <!-- Self-Hosted Fonts Preload -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/material-symbols-rounded.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    Customer layout (mobile-first, single column). No admin sidebar, no login required.
    Child pages:
      <x-customer-layout back-url="{{ $url }}">
          <x-slot:title>Cart</x-slot:title>
          ...content...
      </x-customer-layout>
--}}

<body class="antialiased bg-background text-foreground font-sans">
    <div
        class="mx-auto max-w-md min-h-screen bg-card relative shadow-2xl sm:border-x sm:border-border pt-safe">
        <header
            class="sticky top-0 z-30 h-14 flex items-center gap-1 px-3 bg-card/80 backdrop-blur-xl border-b border-border">
            @if ($backUrl = $attributes->get('back-url'))
                <a href="{{ $backUrl }}" aria-label="Back"
                    class="w-11 h-11 -ml-1 flex items-center justify-center rounded-full hover:bg-accent active:scale-95 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring">
                    <span class="material-symbols-rounded">arrow_back</span>
                </a>
            @endif

            <span class="font-brand font-semibold text-[17px] tracking-tight truncate {{ $backUrl ? '' : 'ml-1' }}">
                {{ $title ?? config('app.name') }}
            </span>

            <div class="ml-auto flex items-center gap-2">
                {{-- Tombol Panggil Waiter — hadir di Cart & Checkout juga, bukan hanya Menu (§5.2 audit) --}}
                <x-waiter-call-button />
                @if (session('current_table_name'))
                    <span
                        class="shrink-0 inline-flex items-center gap-1.5 text-xs font-medium text-foreground bg-card border border-border rounded-lg px-2.5 py-1 shadow-2xs">
                        <span class="material-symbols-rounded text-[16px] text-muted-foreground">table_restaurant</span>
                        {{ session('current_table_name') }}
                    </span>
                @endif
            </div>
        </header>

        <main class="px-4 pt-5 pb-8">
            {{ $slot }}
        </main>

        <x-ui.toast />
    </div>
</body>

</html>
