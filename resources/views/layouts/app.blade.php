<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="admin-ui">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Apeiron System' }}</title>

    <x-favicon />

    <!-- Self-Hosted Fonts Preload (Offline First & Zero External Latency) -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/material-symbols-rounded.woff2') }}" as="font" type="font/woff2" crossorigin>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- WAJIB dimuat di SEMUA halaman (bukan kondisional) agar wire:navigate mencegat semua link --}}
    {{-- CATATAN: Alpine.js sekarang dipasok bundel oleh Livewire core (@livewireScripts), --}}
    {{-- jangan load Alpine CDN terpisah agar tidak terjadi instance ganda / konflik x-data. --}}
    @livewireStyles

    <style>
        [x-cloak] {
            display: none !important;
        }

        /* Material Symbols Consistency */
        .material-symbols-rounded,
        .material-symbols-outlined {
            font-family: 'Material Symbols Rounded', sans-serif !important;
            font-size: 1.25em;
            line-height: 1;
            vertical-align: middle;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
</head>

<body class="font-sans antialiased bg-[#171717] text-[#202123] flex h-dvh overflow-hidden pos-layout-locked">

    {{-- Aksesibilitas: Lewati ke konten utama (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-[#202123] focus:text-white focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-[#374151]">
        Lewati ke konten utama
    </a>

    <div x-data="{
            sidebarMobileOpen: false,
            expanded: localStorage.getItem('sidebarExpanded') !== 'false',
            navigating: false,
            navProgress: 0,
            navTimer: null,
            startNav() {
                this.navigating = true;
                this.navProgress = 20;
                clearInterval(this.navTimer);
                this.navTimer = setInterval(() => {
                    if (this.navProgress < 85) this.navProgress += 12;
                }, 100);
            },
            endNav() {
                this.navProgress = 100;
                clearInterval(this.navTimer);
                setTimeout(() => {
                    this.navigating = false;
                    this.navProgress = 0;
                }, 160);
                const main = document.getElementById('main-content');
                if (main) main.scrollTop = 0;
            }
        }"
        x-init="$watch('expanded', val => localStorage.setItem('sidebarExpanded', val))"
        x-effect="if (sidebarMobileOpen) { document.body.classList.add('overflow-hidden'); } else { document.body.classList.remove('overflow-hidden'); }"
        x-on:livewire:navigating.window="startNav()"
        x-on:livewire:navigated.window="endNav()"
        class="flex w-full h-full bg-[#171717]">

        {{-- Minimal Enterprise SaaS Navigation Progress Indicator --}}
        <div x-show="navigating"
            x-cloak
            aria-hidden="true"
            class="fixed top-0 left-0 right-0 h-[2px] z-50 pointer-events-none overflow-hidden bg-transparent">
            <div class="h-full bg-white/90 shadow-[0_0_8px_rgba(255,255,255,0.7)] transition-all duration-150 ease-out"
                 :style="'width: ' + navProgress + '%'"></div>
        </div>
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper (Unified Dark Frame with Inset Canvas) -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300 bg-[#171717]" id="main-wrapper">

            <!-- Unified Dark Top Navbar (Flows flush from left sidebar) -->
            <header class="h-14 lg:h-16 shrink-0 bg-[#171717] text-[#D4D4D4] flex items-center justify-between px-4 lg:px-6 z-20 border-b border-[#262626] select-none">
                
                <!-- Left: Quick Navigation & Workstations (F-09) -->
                <div class="flex items-center gap-2 lg:gap-3 pl-12 lg:pl-0 min-w-0" x-data="{ openWorkstations: false }">
                    <div class="relative">
                        <button type="button"
                                @click="openWorkstations = !openWorkstations"
                                @click.outside="openWorkstations = false"
                                class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-[#D4D4D4] bg-[#202020] hover:bg-[#262626] border border-[#2E2E2E] rounded-lg transition-colors focus:outline-none focus:ring-1 focus:ring-zinc-600">
                            <span class="material-symbols-rounded text-sm text-zinc-400">apps</span>
                            <span class="hidden sm:inline">Workstations</span>
                            <span class="material-symbols-rounded text-xs text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': openWorkstations }">expand_more</span>
                        </button>

                        <div x-show="openWorkstations"
                             x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute left-0 mt-2 w-56 rounded-xl bg-[#1E1E1E] border border-[#2E2E2E] shadow-2xl py-1.5 z-50 text-xs divide-y divide-[#2A2A2A]">
                            <div class="px-3 py-1.5 text-[11px] font-semibold text-zinc-400 uppercase tracking-wider">
                                Quick Launch
                            </div>
                            <div class="py-1">
                                <a href="{{ route('transaction.create') }}" class="flex items-center gap-2.5 px-3 py-2 text-[#D4D4D4] hover:bg-[#282828] hover:text-white transition-colors">
                                    <span class="material-symbols-rounded text-base text-emerald-400">point_of_sale</span>
                                    <span>POS Kasir Terminal</span>
                                </a>
                                <a href="{{ route('orders.index') }}" class="flex items-center gap-2.5 px-3 py-2 text-[#D4D4D4] hover:bg-[#282828] hover:text-white transition-colors">
                                    <span class="material-symbols-rounded text-base text-blue-400">receipt_long</span>
                                    <span>Riwayat Order</span>
                                </a>
                                <a href="{{ route('kds.index') }}" class="flex items-center gap-2.5 px-3 py-2 text-[#D4D4D4] hover:bg-[#282828] hover:text-white transition-colors">
                                    <span class="material-symbols-rounded text-base text-amber-400">countertops</span>
                                    <span>Kitchen Display (KDS)</span>
                                </a>
                                <a href="{{ route('inventory.ingredients') }}" class="flex items-center gap-2.5 px-3 py-2 text-[#D4D4D4] hover:bg-[#282828] hover:text-white transition-colors">
                                    <span class="material-symbols-rounded text-base text-purple-400">inventory_2</span>
                                    <span>Bahan Baku & Stok</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Status, Clock, User Profile -->
                <div class="flex items-center gap-3 sm:gap-4 shrink-0">
                    {{-- Clock / Date Tag --}}
                    <div class="hidden lg:flex items-center gap-2 text-xs font-medium text-[#A3A3A3] bg-[#202020] border border-[#2E2E2E] px-3 py-1.5 rounded-lg select-none">
                        <span class="material-symbols-rounded text-[15px] text-[#737373]">schedule</span>
                        <span x-data="{ time: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) }" x-init="setInterval(() => { time = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) }, 1000)" x-text="time"></span>
                        <span class="text-[#404040]">•</span>
                        <span>{{ now()->isoFormat('D MMM Y') }}</span>
                    </div>

                    {{-- System Sync Pill --}}
                    <div class="hidden sm:flex items-center gap-1.5 text-[11px] font-medium text-emerald-400 bg-emerald-950/40 border border-emerald-800/40 px-2.5 py-1 rounded-full select-none">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Online • Cloud Sync</span>
                    </div>

                    {{-- User Profile Pill (DEMURE style) --}}
                    <div class="flex items-center gap-2.5 pl-2 border-l border-[#2E2E2E]">
                        <div class="w-8 h-8 rounded-full bg-[#262626] text-white border border-[#383838] flex items-center justify-center font-bold text-xs select-none">
                            {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                        </div>
                        <div class="hidden sm:block text-left leading-tight">
                            <div class="text-xs font-semibold text-white truncate max-w-[130px]">{{ auth()->user()->name ?? 'User' }}</div>
                            <div class="text-[10px] text-[#737373] truncate">{{ auth()->user()->role?->name ?? 'Staff' }}</div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Scrollable Area (Curved Inset White Workspace) -->
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full bg-white lg:rounded-tl-[28px] border-t border-l border-white/10 shadow-2xl flex flex-col {{ ($noPadding ?? false) ? '' : 'p-4 sm:p-6 lg:p-8' }}"
                :class="{ 'opacity-60 transition-opacity duration-150 pointer-events-none': navigating }"
                id="main-content"
                data-gsap="page-enter">
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
    <x-ui.toast />
    <x-erp.confirm-dialog />
</body>

</html>
