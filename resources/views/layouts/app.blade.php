<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="admin-ui {{ (request()->cookie('apeiron_theme') === 'dark') ? 'dark' : '' }}">

<head>
    <meta charset="utf-8">
    <script>
        (function() {
            var cookieMatch = document.cookie.match(/(?:^|;\s*)apeiron_theme=(dark|light)/);
            var saved = localStorage.getItem('apeiron-theme') || (cookieMatch ? cookieMatch[1] : null);
            if (!saved) {
                var old = localStorage.getItem('theme') || localStorage.getItem('color-theme');
                if (old === 'dark' || old === 'light') { saved = old; }
            }
            var isDark = saved ? (saved === 'dark') : window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = isDark ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.style.colorScheme = theme;
            try {
                localStorage.setItem('apeiron-theme', theme);
                document.cookie = 'apeiron_theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
            } catch(e) {}
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="tenant-id" content="{{ auth()->user()?->tenant_id }}">
        <meta name="user-id" content="{{ auth()->id() }}">
        <script>
            window.__APEIRON_BOOT__ = Object.assign(window.__APEIRON_BOOT__ || {}, {
                tenantId: @js(auth()->user()?->tenant_id),
                userId: @js(auth()->id())
            });
            window.ApeironTenantId = @js(auth()->user()?->tenant_id);
            window.ApeironUserId = @js(auth()->id());
        </script>
    @endauth

    <title>{{ $title ?? 'Apeiron System' }}</title>

    <x-favicon />
    <link rel="manifest" href="{{ asset('manifest.json') }}">

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
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            -moz-osx-font-smoothing: grayscale;
            font-feature-settings: 'liga';
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
            expanded: window.innerWidth >= 1024,
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
        x-init="expanded = (localStorage.getItem('sidebarExpanded') !== null) ? (localStorage.getItem('sidebarExpanded') === 'true') : (window.innerWidth >= 1024); $watch('expanded', val => localStorage.setItem('sidebarExpanded', val))"
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

        <!-- Main Content Wrapper (Unified Curved Shell Frame with Inset Workspace Canvas) -->
        <div class="flex-1 flex flex-col h-full overflow-hidden transition-all duration-300 bg-[var(--chrome-surface)]" id="main-wrapper">

            <!-- Sticky h-14 Top Navbar (Clean Executive Layout, 56px) -->
            <header class="h-14 shrink-0 bg-[var(--chrome-surface)] text-foreground flex items-center justify-between px-5 z-30 sticky top-0 select-none">
                
                <!-- Left: Mobile Drawer Trigger + Active Branch & Context Breadcrumbs -->
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" @click="sidebarMobileOpen = true" aria-label="Open navigation menu"
                        class="md:hidden min-h-[44px] min-w-[44px] rounded-lg bg-secondary hover:bg-muted text-foreground flex items-center justify-center border border-border shrink-0 cursor-pointer">
                        <span class="material-symbols-rounded text-[20px]">menu</span>
                    </button>

                    {{-- Branch Switcher (Top Navbar, ghost control) --}}
                    <x-branch-switcher :dark="true" align="left" />

                    {{-- Hairline divider --}}
                    <div class="hidden sm:block h-4 w-px bg-border shrink-0"></div>

                    {{-- Context-Aware Breadcrumbs (Tier 1 ERP Wayfinding) --}}
                    <x-erp.context-breadcrumbs />
                </div>

                <!-- Right: Utilities, Search & Notifications -->
                <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
                    {{-- Sleek Command Palette Search (Cmd+K) --}}
                    <x-ui.header-search />

                    {{-- Authorized Role Perspective Selector (Gated by AbilityEvaluator) --}}
                    @if(request()->routeIs('dashboard*'))
                        @php
                            /** @var \App\Platform\Authorization\AbilityEvaluator $abilityEvaluator */
                            $abilityEvaluator = app(\App\Platform\Authorization\AbilityEvaluator::class);
                            $currentUser = auth()->user();
                            $perspectives = [];
                            if ($currentUser) {
                                $isOwnerOrAdmin = in_array($currentUser->role?->name, ['Owner', 'Superadmin', 'Administrator'], true)
                                     || (bool) ($currentUser->role?->is_admin ?? false)
                                     || $currentUser->canViewConsolidatedStores();
                                if ($isOwnerOrAdmin || $abilityEvaluator->can($currentUser, 'dashboard.scope.all_stores') || $abilityEvaluator->can($currentUser, 'dashboard.view')) {
                                    $perspectives['executive'] = 'Executive / Owner';
                                }
                                if ($isOwnerOrAdmin || $abilityEvaluator->can($currentUser, 'dashboard.perspective.branch_ops') || in_array($currentUser->role?->name, ['Manager'], true)) {
                                    $perspectives['branch_ops'] = 'Branch Ops';
                                }
                                if ($isOwnerOrAdmin || $abilityEvaluator->can($currentUser, 'dashboard.perspective.cashier') || in_array($currentUser->role?->name, ['Cashier', 'Kasir'], true)) {
                                    $perspectives['cashier_workspace'] = 'Cashier / Shift';
                                }
                                if ($isOwnerOrAdmin || $abilityEvaluator->can($currentUser, 'dashboard.perspective.inventory') || (bool) $currentUser->hasPermission('inventory.view')) {
                                    $perspectives['inventory'] = 'Inventory Control';
                                }
                                if ($isOwnerOrAdmin || $abilityEvaluator->can($currentUser, 'dashboard.perspective.finance') || (bool) $currentUser->hasPermission('finance.view')) {
                                    $perspectives['finance'] = 'Finance & GL';
                                }
                            }
                            $currentView = request()->query('view', $activeArchetype ?? 'executive');
                        @endphp
                        @if(count($perspectives) > 1)
                            <div class="relative hidden sm:block">
                                <select aria-label="Switch dashboard role view"
                                    onchange="window.location.href = this.value"
                                    class="h-8 px-2.5 rounded-lg bg-secondary border border-border text-foreground text-xs font-medium cursor-pointer focus:outline-none focus:ring-1 focus:ring-ring">
                                    @foreach($perspectives as $key => $label)
                                        <option value="{{ route('dashboard', array_merge(request()->query(), ['view' => $key])) }}" {{ $currentView === $key ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @endif

                    {{-- Hairline divider --}}
                    <div class="h-4 w-px bg-border shrink-0"></div>

                    {{-- Interactive Tactile Theme Switch Button --}}
                    <button type="button"
                        x-data="{
                            isDark: document.documentElement.classList.contains('dark'),
                            init() {
                                window.addEventListener('apeiron:theme-changed', (e) => {
                                    this.isDark = e.detail.isDark;
                                });
                            },
                            toggle() {
                                if (window.ApeironTheme) {
                                    window.ApeironTheme.toggle();
                                } else {
                                    document.documentElement.classList.toggle('dark');
                                }
                                this.isDark = document.documentElement.classList.contains('dark');
                            }
                        }"
                        @click="toggle()"
                        role="switch"
                        :aria-checked="isDark.toString()"
                        aria-label="Toggle color theme"
                        title="Toggle Light / Dark Theme"
                        class="w-[46px] h-[26px] rounded-full p-[3px] border border-border bg-shell relative inline-flex items-center cursor-pointer transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-foreground/30 select-none shrink-0">
                        <span class="sr-only">Toggle theme</span>
                        <span class="w-5 h-5 rounded-full bg-card border border-border shadow-xs flex items-center justify-center transition-transform duration-200 ease-[cubic-bezier(0.2,0,0,1)] text-foreground"
                              :class="isDark ? 'translate-x-5' : 'translate-x-0'">
                            {{-- Sun Icon (12px, 1.75px stroke) --}}
                            <svg x-show="!isDark" aria-hidden="true" class="w-3 h-3 transition-all duration-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="4"></circle>
                                <path d="M12 2v2"></path>
                                <path d="M12 20v2"></path>
                                <path d="m4.93 4.93 1.41 1.41"></path>
                                <path d="m17.66 17.66 1.41 1.41"></path>
                                <path d="M2 12h2"></path>
                                <path d="M20 12h2"></path>
                                <path d="m6.34 17.66-1.41 1.41"></path>
                                <path d="m19.07 4.93-1.41 1.41"></path>
                            </svg>
                            {{-- Moon Icon (12px, 1.75px stroke) --}}
                            <svg x-show="isDark" x-cloak aria-hidden="true" class="w-3 h-3 transition-all duration-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
                            </svg>
                        </span>
                    </button>

                    {{-- Notification Center Dropdown --}}
                    <x-erp.notification-dropdown />
                </div>
            </header>

            <!-- Main Scrollable Area (Full-Bleed Fluid Workspace with Curved Inner Seam) -->
            @php
                $isFullBleedWorkspace = ($noPadding ?? false)
                    || request()->is('transaction*')
                    || request()->is('kds*')
                    || request()->routeIs('transaction*', 'kds*');
            @endphp
            <main
                class="flex-1 overflow-y-auto overflow-x-hidden w-full bg-shell flex flex-col lg:rounded-tl-[20px] lg:border-t lg:border-l border-border {{ $isFullBleedWorkspace ? '' : 'px-5 sm:px-6 lg:px-7 pt-4 pb-8' }}"
                :class="{ 'opacity-60 transition-opacity duration-150 pointer-events-none': navigating }"
                id="main-content"
                data-gsap="page-enter">
                <!-- Page Heading -->
                @isset($header)
                    <div class="mb-4 shrink-0">
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
    
    <script src="{{ asset('vendor/qz-tray.js') }}"></script>
    <x-qz-print />
    <x-ui.toast />
    <x-erp.confirm-dialog />
    @include('partials.sweetalert-confirm-delete')
    <x-erp.command-palette />

    {{-- Safe PWA Service Worker Registration --}}
    <script>
        if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function (err) {
                    console.warn('[PWA] Service Worker registration failed:', err);
                });
            });
        }
    </script>
</body>

</html>
