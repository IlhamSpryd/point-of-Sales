{{--
    ROLE & SCOPE BASED DYNAMIC SIDEBAR — Apeiron System
    Single source of truth for menu: config/navigation.php.
    Inactive items render with a "Coming Soon" badge.
--}}
@php
    $roleName = auth()->user()?->role?->name;
    $tenantId = app(\App\Services\Context\TenantContext::class)->getTenantId();
    $storeId = app(\App\Services\Context\TenantContext::class)->getStoreId();
    $tablesEnabled = ($tenantId && $storeId)
        ? app(\App\Services\EntitlementService::class)->isTablesEnabled((int) $tenantId, (int) $storeId)
        : true;

    $menu = config('navigation.sections');
    $visibleMenu = collect($menu)
        ->map(fn($items) => array_values(array_filter($items, function ($i) use ($roleName, $tablesEnabled) {
            if (! in_array($roleName, $i['roles'], true)) {
                return false;
            }
            if (($i['route'] ?? '') === 'tables.index' && ! $tablesEnabled) {
                return false;
            }
            return true;
        })))
        ->filter(fn($items) => count($items) > 0);

    $homeUrl = route('home');
@endphp

{{-- Mobile drawer overlay --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false" x-transition.opacity.duration.200ms
    class="fixed inset-0 bg-black/60 backdrop-blur-[2px] z-40 lg:hidden" x-cloak aria-hidden="true"></div>

{{-- Mobile drawer trigger button --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true; $nextTick(() => { const first = document.querySelector('#main-sidebar a, #main-sidebar button'); if (first) first.focus(); })"
    id="mobile-menu-btn"
    aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Open main navigation menu"
    class="lg:hidden fixed top-2 left-2 z-40 w-[40px] h-[40px] flex items-center justify-center text-shell-foreground-active bg-shell hover:bg-shell-accent border border-shell-border active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 rounded-lg cursor-pointer shadow-xs">
    <span class="material-symbols-rounded text-[22px]">menu</span>
</button>

<aside id="main-sidebar" x-data="{ openPopover: false, wide: true }" x-effect="wide = expanded || sidebarMobileOpen"
    role="region" aria-label="Main Navigation Sidebar"
    @keydown.escape.window="if (sidebarMobileOpen) { sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus()); }"
    @keydown.tab="
        if (sidebarMobileOpen) {
            const focusables = Array.from($el.querySelectorAll('a[href], button:not([disabled])'));
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if ($event.shiftKey && document.activeElement === first) {
                $event.preventDefault();
                last.focus();
            } else if (!$event.shiftKey && document.activeElement === last) {
                $event.preventDefault();
                first.focus();
            }
        }
    "
    :class="[
        sidebarMobileOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0',
        wide ? 'w-72 lg:w-64' : 'w-18'
    ]"
    class="fixed lg:relative z-40 h-full flex flex-col bg-shell text-shell-foreground select-none transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

    {{-- Logo + collapse trigger --}}
    <div class="h-14 flex items-center shrink-0 transition-all duration-200" :class="wide ? 'justify-between px-4 gap-3' : 'justify-center px-0'">
        <a href="{{ $homeUrl }}" wire:navigate
            @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; } if (!wide) { $event.preventDefault(); expanded = true; }"
            :title="!wide ? 'Expand sidebar' : ''"
            class="group flex items-center justify-center min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring">
            <span
                class="flex items-center justify-center shrink-0 w-9 h-9 rounded-lg group-hover:bg-shell-accent transition-colors">
                <img src="{{ asset('assets/images/logo-white.png') }}" alt="Apeiron System Logo" :class="!wide ? 'group-hover:hidden' : ''" class="h-7 w-auto object-contain transition-transform duration-300 group-hover:scale-105">
                <svg :class="!wide ? 'hidden group-hover:block' : 'hidden'" width="18" height="18"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="text-shell-foreground" aria-hidden="true">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect>
                    <path d="M9 3v18"></path>
                    <path d="m14 9 3 3-3 3"></path>
                </svg>
            </span>
            <span :class="wide ? 'opacity-100 ml-2.5' : 'hidden'"
                class="font-sans text-base tracking-tight whitespace-nowrap overflow-hidden transition-all duration-200">
                <span class="font-bold text-shell-foreground-active">Apeiron</span><span class="font-medium text-shell-foreground ml-1">System</span>
            </span>
        </a>

        {{-- Collapse sidebar (desktop) --}}
        <button type="button" @click="expanded = false" x-show="expanded" x-cloak title="Collapse sidebar"
            aria-label="Collapse sidebar"
            class="hidden lg:flex w-8 h-8 items-center justify-center rounded-lg text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[19px]">left_panel_close</span>
        </button>

        {{-- Close drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus())" x-show="sidebarMobileOpen" x-cloak
            aria-label="Close navigation menu"
            class="lg:hidden w-[38px] h-[38px] flex items-center justify-center rounded-lg text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
    </div>



    {{-- Main Navigation --}}
    <nav id="sidebar-nav-container" class="flex-1 py-2 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-3' : 'px-0'"
        aria-label="Main navigation">
        @foreach ($visibleMenu as $section => $items)
            @php
                $isWorkstation = ($section === 'Cashier & Operations' || $section === 'Workstations');
            @endphp
            <div class="{{ $loop->first ? '' : ($isWorkstation ? 'mt-4 pt-2.5 border-t border-shell-border' : 'mt-3.5') }}">
                <div x-show="wide" class="px-2.5 pb-1">
                    <p class="text-[11px] font-semibold text-shell-foreground tracking-[0.04em] whitespace-nowrap uppercase">
                        {{ $section }}
                    </p>
                </div>
                @unless ($loop->first)
                    <div x-show="!wide" x-cloak class="w-6 mx-auto my-1.5 border-t border-shell-border"></div>
                @endunless

                <ul class="space-y-0.5">
                    @foreach ($items as $item)
                        @php
                            $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                            $activePatterns = (array) $item['active'];
                            $isActive = $routeExists && request()->routeIs(...$activePatterns);
                            $scope = $item['scope'] ?? 'store';
                            $rowBase =
                                'group relative flex items-center rounded-lg text-[13px] whitespace-nowrap transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-shell-ring';
                        @endphp
                        <li class="flex justify-center w-full">
                            @if ($routeExists)
                                <a href="{{ route($item['route']) }}" wire:navigate
                                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); $event.stopImmediatePropagation(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['label'] }} ({{ $scope === 'store' ? 'Active Branch' : 'Tenant' }})"
                                    :class="wide ? 'w-full px-2.5 min-h-[36px] justify-start before:left-0 before:top-2 before:bottom-2 before:w-1.5 before:rounded-r' : 'w-9 h-9 justify-center px-0 before:hidden'"
                                    class="{{ $rowBase }} {{ $isActive ? 'bg-shell-accent text-shell-foreground-active font-semibold border border-shell-border shadow-xs before:absolute before:w-[2px] before:bg-shell-foreground-active' : 'text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent' }}">
                                    <span
                                        class="material-symbols-rounded text-[19px] shrink-0 text-center flex items-center justify-center {{ $isActive ? 'text-shell-foreground-active' : 'text-shell-foreground group-hover:text-shell-foreground-active transition-colors' }}">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                </a>
                            @else
                                <div aria-disabled="true" title="{{ $item['label'] }} — coming soon"
                                    :class="wide ? 'w-full px-2.5 min-h-[36px] justify-start' : 'w-9 h-9 justify-center px-0'"
                                    class="{{ $rowBase }} text-shell-border cursor-not-allowed">
                                    <span
                                        class="material-symbols-rounded text-[19px] shrink-0 text-center flex items-center justify-center text-shell-border">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                    <span x-show="wide"
                                        class="ml-auto text-[9px] font-medium uppercase tracking-wider text-shell-foreground bg-shell-accent border border-shell-border rounded px-1.5 py-0.5">Coming Soon</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- User Profile & Popover --}}
    <div class="shrink-0 mt-auto relative border-t border-shell-border" :class="wide ? 'p-2.5' : 'py-2.5 px-0 flex justify-center'">
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            aria-haspopup="true"
            aria-label="User account menu"
            class="flex items-center rounded-lg transition-all duration-150 whitespace-nowrap min-h-[38px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring cursor-pointer"
            :class="[wide ? 'w-full p-2 hover:bg-shell-accent' : 'w-9 h-9 justify-center p-0 hover:bg-shell-accent', openPopover ? 'bg-shell-accent' : '']"
            title="{{ auth()->user()->name ?? 'Profile' }}">
            <div
                class="w-7 h-7 rounded-full bg-shell-accent text-shell-foreground-active border border-shell-border flex items-center justify-center font-semibold text-[11px] shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div :class="wide ? 'opacity-100 ml-2.5 flex-1 text-left' : 'hidden'"
                class="overflow-hidden transition-all duration-150">
                <p class="text-[13px] font-medium text-shell-foreground-active truncate leading-tight">{{ auth()->user()->name ?? 'User' }}
                </p>
                <p class="text-[11px] text-shell-foreground truncate leading-tight mt-0.5">{{ $roleName ?? 'No role' }}
                </p>
            </div>
            <span x-show="wide"
                class="material-symbols-rounded text-[18px] text-shell-foreground transition-transform duration-200"
                :class="openPopover ? 'rotate-180' : ''">expand_less</span>
        </button>

        <div x-show="openPopover" x-cloak @click.outside="openPopover = false"
            @keydown.escape.window="openPopover = false"
            role="menu"
            aria-label="User profile and settings menu"
            x-transition:enter="transition-all duration-150 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 scale-95 translate-y-1"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition-all duration-100 ease-in"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-1"
            class="absolute bottom-full mb-2 bg-shell text-shell-foreground rounded-xl shadow-xl border border-shell-border overflow-hidden z-50"
            :class="wide ? 'left-2.5 right-2.5' : 'left-2 w-56 md:left-full md:ml-2'">
            <div class="px-3.5 py-2.5 border-b border-shell-border">
                <p class="text-xs font-semibold text-shell-foreground-active truncate">{{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-[11px] text-shell-foreground truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                @if ($roleName)
                    <span
                        class="inline-flex items-center mt-1.5 px-2 py-0.5 rounded text-[10px] font-medium bg-shell-accent text-shell-foreground-active border border-shell-border">{{ $roleName }}</span>
                @endif
            </div>
            <div class="p-1">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">person</span> My Profile
                </a>
                @if(auth()->user()->role?->name === 'Owner')
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">settings</span> Settings
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}"
                    @submit="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Signing out will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-destructive hover:bg-destructive-muted hover:text-destructive-foreground active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive/40 transition-all duration-150 cursor-pointer">
                        <span class="material-symbols-rounded text-[17px]">logout</span> Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
