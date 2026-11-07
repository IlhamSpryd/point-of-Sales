{{--
    ROLE & SCOPE BASED DYNAMIC SIDEBAR — Apeiron System
    Design Inspiration: DEMURE Fashion & Lifestyle Trade ERP SaaS (Dribbble Tier-1 Benchmark)
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

    $menu = config('navigation.sections', []);
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
    $tenantName = auth()->user()?->tenant?->name ?? 'Apeiron System';

    // Pre-calculate which section contains the currently active route
    $activeSectionSlug = null;
    foreach ($visibleMenu as $sectionName => $secItems) {
        foreach ($secItems as $secItem) {
            if (\Illuminate\Support\Facades\Route::has($secItem['route']) && request()->routeIs(...(array) $secItem['active'])) {
                $activeSectionSlug = \Illuminate\Support\Str::slug($sectionName, '_');
                break 2;
            }
        }
    }
@endphp

{{-- Mobile drawer overlay --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false" x-transition.opacity.duration.200ms
    class="fixed inset-0 bg-black/60 backdrop-blur-[2px] z-40 md:hidden" x-cloak aria-hidden="true"></div>

{{-- Mobile drawer trigger button --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true; $nextTick(() => { const first = document.querySelector('#main-sidebar a, #main-sidebar button'); if (first) first.focus(); })"
    id="mobile-menu-btn"
    aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Open main navigation menu"
    class="md:hidden fixed top-2 left-2 z-40 w-[40px] h-[40px] flex items-center justify-center text-shell-foreground-active bg-shell hover:bg-shell-accent border border-shell-border active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 rounded-lg cursor-pointer shadow-xs">
    <span class="material-symbols-rounded text-[22px]">menu</span>
</button>

<aside id="main-sidebar"
    x-data="{
        openPopover: false,
        openSections: {
            @foreach ($visibleMenu as $sectionName => $secItems)
                @php
                    $sSlug = \Illuminate\Support\Str::slug($sectionName, '_');
                    // Open if it's the active section, or if the role has few sections (<= 2)
                    $defaultOpen = ($activeSectionSlug !== null)
                        ? ($activeSectionSlug === $sSlug)
                        : ($loop->first || count($visibleMenu) <= 2);
                @endphp
                '{{ $sSlug }}': {{ $defaultOpen ? 'true' : 'false' }},
            @endforeach
        },
        toggleSection(slug) {
            this.openSections[slug] = !this.openSections[slug];
        }
    }"
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
        isMobile
            ? (sidebarMobileOpen ? 'fixed inset-y-0 left-0 translate-x-0 shadow-2xl z-40' : 'fixed inset-y-0 left-0 -translate-x-full z-40')
            : 'relative translate-x-0 z-30',
        wide ? (isMobile ? 'w-72' : 'w-64') : 'w-16'
    ]"
    class="h-full flex flex-col bg-shell text-shell-foreground select-none overflow-x-hidden transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

    {{-- Header: DEMURE Luxury Brand Logo + Circular Collapse/Expand Toggle --}}
    <div class="h-16 flex items-center shrink-0 transition-all duration-200" :class="wide ? 'justify-between px-4' : 'justify-center px-0'">
        <div x-show="wide" class="flex items-center min-w-0">
            <a href="{{ $homeUrl }}" wire:navigate
                @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                class="group flex items-center min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring">
                <x-brand-logo variant="wordmark" class="h-5 w-auto text-shell-foreground-active transition-transform group-hover:scale-105" />
            </a>
        </div>

        {{-- In rail mode: Logo button transforms into arrow > icon on hover and triggers expand --}}
        <div x-show="!wide" x-cloak class="flex items-center justify-center w-full">
            <button type="button"
                @mouseenter="expanded = true"
                @click="expanded = true"
                title="Expand sidebar"
                aria-label="Expand sidebar"
                class="group relative w-9 h-9 rounded-xl flex items-center justify-center hover:bg-shell-accent active:scale-95 transition-all duration-150 cursor-pointer text-shell-foreground-active">
                {{-- Default: Logo Symbol (hides when hovered) --}}
                <span class="flex items-center justify-center transition-all duration-150 group-hover:hidden">
                    <img src="{{ asset('assets/brand/apeiron-symbol.svg') }}" alt="Apeiron Systems" class="h-4 w-auto object-contain dark:invert" />
                </span>
                {{-- Hover: Arrow > icon (replaces logo completely rather than overlaying) --}}
                <span class="hidden group-hover:flex items-center justify-center transition-all duration-150 text-shell-foreground-active">
                    <span class="material-symbols-rounded text-[20px]">chevron_right</span>
                </span>
            </button>
        </div>

        {{-- Circular Collapse Trigger Button (Desktop/Laptop/iPad - when wide) --}}
        <button type="button" @click="expanded = false" x-show="wide && !sidebarMobileOpen" x-cloak title="Collapse sidebar"
            aria-label="Collapse sidebar"
            class="hidden md:flex w-7 h-7 items-center justify-center rounded-full text-shell-foreground bg-white hover:bg-shell-accent hover:text-shell-foreground-active border border-shell-border active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-150 cursor-pointer shadow-2xs">
            <span class="material-symbols-rounded text-[16px]">chevron_left</span>
        </button>

        {{-- Close drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus())" x-show="sidebarMobileOpen" x-cloak
            aria-label="Close navigation menu"
            class="md:hidden w-[36px] h-[36px] flex items-center justify-center rounded-full text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
    </div>

    {{-- Main Navigation Container --}}
    <nav id="sidebar-nav-container" class="flex-1 py-3 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-3' : 'px-1.5 flex flex-col items-center'"
        aria-label="Main navigation">

        {{-- MODE A: Wide Accordion Navigation (Desktop Expanded & Mobile Drawer) --}}
        <div x-show="wide" class="w-full">
            @foreach ($visibleMenu as $section => $items)
                @php
                    $sectionSlug = \Illuminate\Support\Str::slug($section, '_');
                    $isWorkstation = ($section === 'Cashier & Operations' || $section === 'Workstations');
                    $sectionItemCount = count($items);
                @endphp
                <div class="{{ $loop->first ? '' : ($isWorkstation ? 'mt-3.5 pt-1' : 'mt-2.5') }} w-full">
                    {{-- Section Header Button (Accordion Trigger) --}}
                    <div class="px-1 mb-1">
                        <button type="button"
                            @click="toggleSection('{{ $sectionSlug }}')"
                            aria-expanded="openSections['{{ $sectionSlug }}']"
                            class="w-full flex items-center justify-between px-2 py-1 rounded-md text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent transition-colors group cursor-pointer select-none">
                            <span class="text-[10px] font-bold tracking-[0.08em] uppercase truncate"
                                  :class="openSections['{{ $sectionSlug }}'] ? 'text-shell-foreground-active font-bold' : 'text-shell-foreground font-semibold'">
                                {{ $section }}
                            </span>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="text-[9px] font-sans px-1.5 py-0.2 rounded-full bg-shell-accent text-shell-foreground group-hover:text-shell-foreground-active border border-shell-border">
                                    {{ $sectionItemCount }}
                                </span>
                                <span class="material-symbols-rounded text-[14px] text-shell-foreground transition-transform duration-200"
                                      :class="openSections['{{ $sectionSlug }}'] ? 'rotate-180 text-shell-foreground-active' : ''">
                                    expand_more
                                </span>
                            </div>
                        </button>
                    </div>

                    {{-- Accordion Content List --}}
                    <ul x-show="openSections['{{ $sectionSlug }}']"
                        x-collapse
                        class="space-y-1">
                        @foreach ($items as $item)
                            @php
                                $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                                $activePatterns = (array) $item['active'];
                                $isActive = $routeExists && request()->routeIs(...$activePatterns);
                                $scope = $item['scope'] ?? 'store';
                                $rowBase = 'group relative flex items-center rounded-xl text-[13px] whitespace-nowrap transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-shell-ring';
                            @endphp
                            <li class="flex justify-center w-full">
                                @if ($routeExists)
                                    <a href="{{ route($item['route']) }}" wire:navigate
                                        @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); $event.stopImmediatePropagation(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                                        @if ($isActive) aria-current="page" @endif
                                        title="{{ $item['label'] }} ({{ $scope === 'store' ? 'Active Branch' : 'Tenant' }})"
                                        class="{{ $rowBase }} w-full px-3 min-h-[38px] justify-start before:left-0 before:top-2 before:bottom-2 before:w-[3px] before:rounded-r {{ $isActive ? 'bg-shell-accent text-shell-foreground-active font-semibold border border-shell-border shadow-2xs before:absolute before:bg-shell-foreground-active' : 'text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent/60' }}">
                                        
                                        <span class="material-symbols-rounded text-[20px] shrink-0 text-center flex items-center justify-center {{ $isActive ? 'text-shell-foreground-active' : 'text-shell-foreground group-hover:text-shell-foreground-active transition-colors' }}">{{ $item['icon'] }}</span>
                                        
                                        <span class="ml-2.5 flex-1 truncate font-medium overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>

                                        @if (!empty($item['active']) && is_array($item['active']) && count($item['active']) > 1)
                                            <span class="material-symbols-rounded text-xs text-shell-foreground/60 ml-auto group-hover:text-shell-foreground-active">chevron_right</span>
                                        @endif
                                    </a>
                                @else
                                    <div aria-disabled="true" title="{{ $item['label'] }} — coming soon"
                                        class="{{ $rowBase }} w-full px-3 min-h-[38px] justify-start text-shell-border cursor-not-allowed">
                                        <span class="material-symbols-rounded text-[20px] shrink-0 text-center flex items-center justify-center text-shell-border">{{ $item['icon'] }}</span>
                                        <span class="ml-2.5 flex-1 truncate overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                        <span class="ml-auto text-[9px] font-medium uppercase tracking-wider text-shell-foreground bg-shell-accent border border-shell-border rounded px-1.5 py-0.5">Soon</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- MODE B: Micro-Rail Navigation (Desktop Collapsed — DEMURE Signature 1 Icon Per Module) --}}
        <ul x-show="!wide" x-cloak class="w-full flex flex-col items-center space-y-1.5 py-1.5">
            @foreach ($visibleMenu as $section => $items)
                @php
                    $sectionSlug = \Illuminate\Support\Str::slug($section, '_');
                    $isSectionActive = ($activeSectionSlug === $sectionSlug);
                    $firstItem = $items[0] ?? null;
                    if (!$firstItem) continue;

                    $sectionIcon = match($section) {
                        'Overview' => 'space_dashboard',
                        'Cashier & Operations' => 'point_of_sale',
                        'Catalog & Recipes' => 'inventory_2',
                        'Inventory & Supply' => 'warehouse',
                        'Finance & Accounting' => 'account_balance',
                        'Analytics & Customers' => 'query_stats',
                        'Human Resources & Payroll' => 'badge',
                        'Settings & Governance' => 'settings',
                        default => $firstItem['icon'] ?? 'circle',
                    };

                    // Find active item route if section is active, or fallback to first item
                    $targetItem = collect($items)->first(function ($it) {
                        return \Illuminate\Support\Facades\Route::has($it['route']) && request()->routeIs(...(array) $it['active']);
                    }) ?? $firstItem;

                    $targetRoute = \Illuminate\Support\Facades\Route::has($targetItem['route']) ? route($targetItem['route']) : '#';
                @endphp
                <li class="flex justify-center w-full">
                    <a href="{{ $targetRoute }}" wire:navigate
                        @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                        title="{{ $section }}"
                        aria-label="{{ $section }}"
                        @if ($isSectionActive) aria-current="page" @endif
                        class="w-9 h-9 rounded-xl flex items-center justify-center transition-all duration-150 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring {{ $isSectionActive ? 'bg-shell-foreground-active text-white shadow-xs font-bold' : 'text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent' }}">
                        <span class="material-symbols-rounded text-[19px] {{ $isSectionActive ? 'text-white' : 'text-shell-foreground group-hover:text-shell-foreground-active' }}">
                            {{ $sectionIcon }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- DEMURE Luxury Footer: User Card --}}
    <div class="shrink-0 mt-auto relative" :class="wide ? 'p-3' : 'py-3 px-1.5 flex flex-col items-center'">
        
        {{-- Luxury User Card (Catherine Kim Style) --}}
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            aria-haspopup="true"
            aria-label="User account menu"
            class="flex items-center rounded-xl transition-all duration-150 whitespace-nowrap min-h-[42px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring cursor-pointer group select-none"
            :class="[wide ? 'w-full p-2 hover:bg-shell-accent' : 'w-9 h-9 justify-center p-0 hover:bg-shell-accent rounded-xl', openPopover ? 'bg-shell-accent' : '']"
            title="{{ auth()->user()->name ?? 'Profile' }}">
            <div
                class="w-7 h-7 rounded-full bg-shell-foreground-active text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-2xs">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div x-show="wide" x-cloak class="ml-2.5 flex-1 text-left overflow-hidden transition-all duration-150">
                <p class="text-[12px] font-bold text-shell-foreground-active truncate leading-tight group-hover:text-shell-foreground-active">{{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-[10px] text-shell-foreground truncate leading-tight mt-0.5">{{ auth()->user()->email ?? $roleName }}</p>
            </div>
            <span x-show="wide" x-cloak
                class="material-symbols-rounded text-[16px] text-shell-foreground transition-transform duration-200 group-hover:text-shell-foreground-active"
                :class="openPopover ? 'rotate-90 text-shell-foreground-active' : ''">chevron_right</span>
        </button>

        {{-- Dropdown Profile Popover --}}
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
            class="absolute bottom-full mb-2 bg-white text-shell-foreground-active rounded-2xl shadow-xl border border-shell-border overflow-hidden z-50 divide-y divide-shell-border"
            :class="wide ? 'left-3 right-3' : 'left-2 w-56 md:left-full md:ml-2'">
            <div class="px-3.5 py-3 bg-white">
                <p class="text-xs font-bold text-shell-foreground-active truncate">{{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-[11px] text-shell-foreground truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                @if ($roleName)
                    <span
                        class="inline-flex items-center mt-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-shell-accent text-shell-foreground-active border border-shell-border">{{ $roleName }}</span>
                @endif
            </div>
            <div class="p-1.5 space-y-0.5 bg-white">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-xl text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">person</span> My Profile
                </a>
                @if(auth()->user()->role?->name === 'Owner')
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-xl text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">settings</span> Settings
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}"
                    @submit="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Signing out will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 px-2.5 min-h-[36px] rounded-xl text-xs font-semibold text-destructive hover:bg-destructive/10 hover:text-destructive active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive/40 transition-all duration-150 cursor-pointer">
                        <span class="material-symbols-rounded text-[17px]">logout</span> Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
