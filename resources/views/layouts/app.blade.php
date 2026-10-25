<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="admin-ui">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Apeiron System' }}</title>

    <link rel="icon" href="{{ asset('assets/images/logo.png') }}" type="image/png">

    <!-- Self-Hosted Fonts Preload (Offline First & Zero External Latency) -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/material-symbols-rounded.woff2') }}" as="font" type="font/woff2" crossorigin>



    <!-- ApexCharts -->
    <link href="https://cdn.jsdelivr.net/npm/apexcharts@3.42.0/dist/apexcharts.css" rel="stylesheet">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- WAJIB dimuat di SEMUA halaman (bukan kondisional) agar wire:navigate mencegat semua link --}}
    {{-- CATATAN: Alpine.js sekarang dipasok bundel oleh Livewire core (@livewireScripts), --}}
    {{-- jangan load Alpine CDN terpisah agar tidak terjadi instance ganda / konflik x-data. --}}
    @livewireStyles
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.42.0/dist/apexcharts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        [x-cloak] {
            display: none !important;
        }

        /* Material Symbols Consistency */
        .material-symbols-rounded {
            font-family: 'Material Symbols Rounded', sans-serif !important;
            font-size: 1.25em;
            line-height: 1;
            vertical-align: middle;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
</head>

<body class="font-sans antialiased bg-white text-apeiron-ink flex h-dvh overflow-hidden pos-layout-locked">

    {{-- Aksesibilitas: Lewati ke konten utama (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-[#202020] focus:text-white focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-[#37352F]">
        Lewati ke konten utama
    </a>

    {{-- Indikator progress bar transisi navigasi Livewire SPA (wire:navigate) & Reset Scroll Top --}}
    <div x-data="{ navigating: false }"
        x-on:livewire:navigating.window="navigating = true"
        x-on:livewire:navigated.window="navigating = false; const main = document.getElementById('main-content'); if (main) main.scrollTop = 0;"
        x-show="navigating"
        x-cloak
        aria-hidden="true"
        class="fixed top-0 left-0 right-0 h-0.5 bg-[#202020] z-50 animate-pulse transition-all duration-150">
    </div>

    <div x-data="{ sidebarMobileOpen: false, expanded: localStorage.getItem('sidebarExpanded') !== 'false' }" x-init="$watch('expanded', val => localStorage.setItem('sidebarExpanded', val))" class="flex w-full h-full bg-white">
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300 bg-white" id="main-wrapper">

            <!-- Main Scrollable Area -->
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full {{ ($noPadding ?? false) ? '' : 'px-4 pb-4 pt-16 sm:px-6 sm:pb-6 sm:pt-16 lg:p-8' }} animate-fade-in flex flex-col bg-white"
                id="main-content">
                <!-- Page Heading -->
                @isset($header)
                    <div class="mb-6 shrink-0">
                        {{ $header }}
                    </div>
                @endisset

                <!-- Page Content -->
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
    @include('partials.sweetalert-confirm-delete')
    <x-fullscreen-toggle />
    
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
    <x-qz-print />
    <x-toast />
</body>

</html>
