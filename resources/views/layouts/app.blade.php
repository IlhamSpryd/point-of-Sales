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
        .material-symbols-rounded {
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

    {{-- Indikator progress bar transisi navigasi Livewire SPA (wire:navigate) & Reset Scroll Top --}}
    <div x-data="{ navigating: false }"
        x-on:livewire:navigating.window="navigating = true"
        x-on:livewire:navigated.window="navigating = false; const main = document.getElementById('main-content'); if (main) main.scrollTop = 0;"
        x-show="navigating"
        x-cloak
        aria-hidden="true"
        class="fixed top-0 left-0 right-0 h-0.5 bg-[#202123] z-50 animate-pulse transition-all duration-150">
    </div>

    <div x-data="{ sidebarMobileOpen: false, expanded: localStorage.getItem('sidebarExpanded') !== 'false' }" x-init="$watch('expanded', val => localStorage.setItem('sidebarExpanded', val))" class="flex w-full h-full bg-[#171717]">
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper (Unified Dark Frame with Inset Canvas) -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300 bg-[#171717]" id="main-wrapper">

            <!-- Unified Dark Top Navbar (Flows flush from left sidebar) -->
            <header class="h-14 lg:h-16 shrink-0 bg-[#171717] text-[#D4D4D4] flex items-center justify-between px-4 lg:px-6 z-20 border-b border-[#262626] select-none">
                
                <!-- Left: Quick Switchers & Workstation Links (DEMURE style) -->
                <div class="flex items-center gap-3 lg:gap-4 pl-12 lg:pl-0 min-w-0">
                    {{-- Branch Switcher (Dark variant) --}}
                    <div class="shrink-0">
                        <x-branch-switcher :dark="true" />
                    </div>

                    {{-- DEMURE style Module Dropdown: Pusat Kerja --}}
                    <div x-data="{ open: false }" class="relative hidden sm:inline-block text-left" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open.toString()"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-[#A3A3A3] hover:text-white hover:bg-[#202020] border border-transparent hover:border-[#2E2E2E] transition-all duration-150 cursor-pointer">
                            <span class="material-symbols-rounded text-[17px] text-[#737373]">grid_view</span>
                            <span>Pusat Kerja</span>
                            <span class="material-symbols-rounded text-[16px] text-[#737373] transition-transform duration-150" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                            x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                            class="absolute left-0 mt-2 w-56 bg-[#1E1E1E] text-white rounded-xl shadow-2xl border border-[#333333] p-1.5 z-50">
                            <div class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-[#737373] border-b border-[#2E2E2E] mb-1">
                                Akses Cepat Workstation
                            </div>
                            <a href="{{ route('transaction.create') }}" wire:navigate class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] transition-colors">
                                <span class="material-symbols-rounded text-[17px] text-emerald-400">point_of_sale</span>
                                <div>
                                    <div class="font-medium">Kasir (POS)</div>
                                    <div class="text-[10px] text-[#737373]">Terminal transaksi</div>
                                </div>
                            </a>
                            <a href="{{ route('orders.index') }}" wire:navigate class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] transition-colors">
                                <span class="material-symbols-rounded text-[17px] text-blue-400">receipt_long</span>
                                <div>
                                    <div class="font-medium">Riwayat Pesanan</div>
                                    <div class="text-[10px] text-[#737373]">Pelacakan struk & status</div>
                                </div>
                            </a>
                            <a href="{{ route('inventory.ingredients') }}" wire:navigate class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] transition-colors">
                                <span class="material-symbols-rounded text-[17px] text-amber-400">warehouse</span>
                                <div>
                                    <div class="font-medium">Bahan Baku</div>
                                    <div class="text-[10px] text-[#737373]">Stok & resep</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    {{-- DEMURE style Link: Demo Investor & Roadmap --}}
                    @if (in_array(auth()->user()?->role?->name, ['Owner', 'Manager'], true))
                    <div x-data="{ open: false }" class="relative hidden md:inline-block text-left" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open.toString()"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-[#A3A3A3] hover:text-white hover:bg-[#202020] border border-transparent hover:border-[#2E2E2E] transition-all duration-150 cursor-pointer">
                            <span class="material-symbols-rounded text-[17px] text-amber-400">explore</span>
                            <span>Visi Produk</span>
                            <span class="material-symbols-rounded text-[16px] text-[#737373] transition-transform duration-150" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                            x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                            class="absolute left-0 mt-2 w-60 bg-[#1E1E1E] text-white rounded-xl shadow-2xl border border-[#333333] p-1.5 z-50">
                            <div class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-[#737373] border-b border-[#2E2E2E] mb-1">
                                Presentasi & Roadmap
                            </div>
                            <a href="{{ route('investor.demo') }}" wire:navigate class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] transition-colors">
                                <span class="material-symbols-rounded text-[17px] text-amber-400">slideshow</span>
                                <div>
                                    <div class="font-medium">Pusat Demonstrasi Investor</div>
                                    <div class="text-[10px] text-[#737373]">Live walkthrough arsitektur</div>
                                </div>
                            </a>
                        </div>
                    </div>
                    @endif
                </div>

                <!-- Right: Status, Clock, User Profile -->
                <div class="flex items-center gap-3 sm:gap-4 shrink-0">
                    {{-- Clock / Date Tag --}}
                    <div class="hidden lg:flex items-center gap-2 text-xs font-medium text-[#A3A3A3] bg-[#202020] border border-[#2E2E2E] px-3 py-1.5 rounded-lg select-none">
                        <span class="material-symbols-rounded text-[15px] text-[#737373]">schedule</span>
                        <span x-data="{ time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }" x-init="setInterval(() => { time = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }, 1000)" x-text="time"></span>
                        <span class="text-[#404040]">•</span>
                        <span>{{ now()->locale('id')->isoFormat('D MMM Y') }}</span>
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
                            <div class="text-xs font-semibold text-white truncate max-w-[130px]">{{ auth()->user()->name ?? 'Pengguna' }}</div>
                            <div class="text-[10px] text-[#737373] truncate">{{ auth()->user()->role?->name ?? 'Staff' }}</div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Scrollable Area (Curved Inset White Workspace) -->
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full bg-white lg:rounded-tl-[28px] border-t border-l border-white/10 shadow-2xl flex flex-col {{ ($noPadding ?? false) ? '' : 'p-4 sm:p-6 lg:p-8' }}"
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
