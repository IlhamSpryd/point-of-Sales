<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="admin-ui">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Yovel Coffee & Cafe' }}</title>

    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">

    <!-- Google Material Symbols (Rounded) -->
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />



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

<body class="font-sans antialiased bg-yovel-bg text-yovel-ink flex h-dvh overflow-hidden pos-layout-locked">

    <div x-data="{ sidebarMobileOpen: false, expanded: localStorage.getItem('sidebarExpanded') !== 'false' }" x-init="$watch('expanded', val => localStorage.setItem('sidebarExpanded', val))" class="flex w-full h-full">
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300" id="main-wrapper">

            <!-- Main Scrollable Area -->
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full {{ ($noPadding ?? false) ? '' : 'px-4 pb-4 pt-16 sm:px-6 sm:pb-6 sm:pt-16 lg:p-8' }} animate-fade-in flex flex-col"
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
