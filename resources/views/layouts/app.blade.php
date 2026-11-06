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

<body class="font-sans antialiased bg-background text-foreground flex h-dvh overflow-hidden pos-layout-locked">

    {{-- Accessibility: Skip to main content (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-shell focus:text-shell-foreground-active focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-shell-ring">
        Skip to main content
    </a>

    <div x-data="{
            sidebarMobileOpen: false,
            expanded: true,
            isMobile: window.innerWidth < 768,
            get wide() {
                return this.isMobile ? true : Boolean(this.expanded);
            },
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
        x-init="expanded = (localStorage.getItem('sidebarExpanded') !== null) ? (localStorage.getItem('sidebarExpanded') === 'true') : true; $watch('expanded', val => localStorage.setItem('sidebarExpanded', val))"
        x-effect="if (sidebarMobileOpen) { document.body.classList.add('overflow-hidden'); } else { document.body.classList.remove('overflow-hidden'); }"
        @resize.window.debounce.100ms="isMobile = window.innerWidth < 768"
        x-on:livewire:navigating.window="startNav()"
        x-on:livewire:navigated.window="endNav()"
        class="flex w-full h-full bg-shell">

        {{-- Minimal Enterprise SaaS Navigation Progress Indicator --}}
        <div x-show="navigating"
            x-cloak
            aria-hidden="true"
            class="fixed top-0 left-0 right-0 h-[2px] z-50 pointer-events-none overflow-hidden bg-transparent">
            <div class="h-full bg-shell-foreground-active/90 shadow-[0_0_8px_var(--shell-foreground-active)] transition-all duration-150 ease-out"
                 :style="'width: ' + navProgress + '%'"></div>
        </div>
        <!-- Sidebar Navigation -->
        @include('layouts.navigation')

        <!-- Main Content Wrapper (Unified White Frame with Inset Workspace Canvas) -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300 bg-shell" id="main-wrapper">

            <!-- Unified White Top Navbar (Flows flush from left sidebar) -->
            <header class="h-14 md:h-16 shrink-0 bg-shell text-shell-foreground flex items-center justify-between px-4 md:px-6 z-20 border-b-0 select-none">
                
                <!-- Left: Active Branch & Context Breadcrumbs -->
                <div class="flex items-center gap-2 md:gap-3 pl-12 md:pl-0 min-w-0">
                    {{-- Branch Switcher (Top Navbar) --}}
                    <x-branch-switcher :dark="true" align="left" />

                    {{-- Context-Aware Breadcrumbs (Tier 1 ERP Wayfinding) --}}
                    <x-erp.context-breadcrumbs />
                </div>

                <!-- Right: Utilities, Search & Notifications (Ultra-Minimal Tier-1 Layout) -->
                <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
                    {{-- Sleek Command Palette Search (Cmd+K) --}}
                    <x-ui.header-search />

                    {{-- Micro Sync Indicator (Clean dot replaces bulky text badge) --}}
                    <div class="hidden sm:flex items-center justify-center w-8 h-8 rounded-lg text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active transition-colors cursor-default"
                         title="System Status: Online & Cloud Synchronized">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
                        </span>
                    </div>

                    {{-- Notification Center Dropdown --}}
                    <x-erp.notification-dropdown />
                </div>
            </header>

            <!-- Main Scrollable Area (Curved Inset Workspace Canvas with Unified Border) -->
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full bg-zinc-50/70 dark:bg-background md:rounded-tl-[28px] border-t border-l border-shell-border shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)] flex flex-col {{ ($noPadding ?? false) ? '' : 'p-4 sm:p-6 lg:p-8' }}"
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
    <x-fullscreen-toggle />
    
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
    <x-qz-print />
    <x-ui.toast />
    <x-erp.confirm-dialog />
    @include('partials.sweetalert-confirm-delete')
    <x-erp.command-palette />
</body>

</html>
