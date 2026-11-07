<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') — {{ config('app.name', 'Apeiron Systems') }}</title>
    <x-favicon />

    <!-- Self-Hosted Fonts Preload -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-between">
    {{-- Skip to main content (WCAG 2.4.1) --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-primary focus:text-primary-foreground focus:rounded-md focus:shadow-md">
        Skip to main content
    </a>

    {{-- Header with Apeiron Brand --}}
    <header class="w-full max-w-7xl mx-auto px-6 py-6 flex items-center justify-between">
        <a href="{{ url('/') }}" class="inline-flex items-center gap-2.5 focus:outline-hidden focus:ring-2 focus:ring-ring rounded-lg p-1">
            <x-apeiron-symbol class="h-7 w-auto text-foreground" />
            <span class="font-bold text-sm tracking-tight text-foreground">Apeiron Systems</span>
        </a>
        <div class="text-xs text-muted-foreground font-sans">
            ERP Platform
        </div>
    </header>

    {{-- Main Content --}}
    <main id="main-content" class="flex-1 flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-md text-center">
            @yield('content')
        </div>
    </main>

    {{-- Footer --}}
    <footer class="w-full max-w-7xl mx-auto px-6 py-6 border-t border-border flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-muted-foreground">
        <span>&copy; {{ date('Y') }} {{ config('app.name', 'Apeiron Systems') }}. All rights reserved.</span>
        <div class="flex items-center gap-4">
            <a href="{{ url('/') }}" class="hover:text-foreground transition-colors">Home</a>
            <span class="text-border">•</span>
            <a href="mailto:support@apeironsystems.com" class="hover:text-foreground transition-colors">Technical Support</a>
        </div>
    </footer>
</body>
</html>
