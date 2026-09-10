<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'SparkPOS') }}</title>

    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">

    <!-- Google Material Symbols (Rounded) -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />

    <!-- Bootstrap Icons (Temporary fallback) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Plus Jakarta Sans Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">

    <!-- ApexCharts (Used in Spark) -->
    <link href="https://cdn.jsdelivr.net/npm/apexcharts@3.42.0/dist/apexcharts.css" rel="stylesheet">

    <!-- Scripts (Tailwind + Alpine + Native JS) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.42.0/dist/apexcharts.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }

        /* Global Font Enforcement */
        body { font-family: 'Plus Jakarta Sans', sans-serif !important; }

        /* Custom scrollbar for sidebar — ultra-thin, only on hover */
        .scrollbar-none::-webkit-scrollbar { width: 0; }
        .scrollbar-none { scrollbar-width: none; }

        /* Material Symbols Consistency */
        .material-symbols-rounded {
            font-size: 1.25em; /* 20px relative scaling */
            line-height: 1;
            vertical-align: middle;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .animate-fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }
    </style>
</head>
<body class="antialiased bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex h-screen overflow-hidden">
    
    <div x-data="{ sidebarMobileOpen: false, expanded: localStorage.getItem('sidebarExpanded') !== 'false' }" 
         x-init="$watch('expanded', val => localStorage.setItem('sidebarExpanded', val))" 
         class="flex w-full h-full">
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300" id="main-wrapper">
            
            <!-- Topbar Header (Mobile Only) -->
            <header class="bg-white/80 dark:bg-zinc-900/80 backdrop-blur-xl border-b border-zinc-100 dark:border-zinc-800/40 h-14 flex items-center justify-between px-4 sm:px-6 shrink-0 lg:hidden">
                <div class="flex items-center ml-10">
                    <div class="w-7 h-7 rounded-lg bg-zinc-900 text-white flex items-center justify-center shrink-0 shadow-sm mr-2">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </div>
                    <span class="font-bold text-sm text-zinc-900 dark:text-white tracking-tight whitespace-nowrap flex items-center">
                        Spark<span class="text-zinc-900">POS</span>
                    </span>
                </div>
            </header>

            <!-- Main Scrollable Area -->
            <main class="flex-1 overflow-y-auto w-full p-4 sm:p-6 lg:p-8 animate-fade-in" id="main-content">
                <!-- Page Heading -->
                @isset($header)
                    <div class="mb-6">
                        {{ $header }}
                    </div>
                @endisset

                <!-- Page Content -->
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
