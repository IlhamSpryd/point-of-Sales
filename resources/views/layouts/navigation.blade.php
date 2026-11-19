{{--
    APEIRON ENTERPRISE SIDEBAR — registry driven (ADR-NAV-001 / ADR-NAV-003 / ADR-NAV-004)

    Single source of truth: config/navigation/domains/*.php through
    App\Platform\Navigation\NavigationVisibilityResolver.

    Structure: 9 non-clickable domain labels -> 19 clickable hub rows.
    Zero accordions, zero item-count badges, zero "SOON" rows: a menu row that
    cannot resolve is a promise the product must keep, so it is never rendered.
    L2 module navigation lives in the canvas as a pill bar, not nested here
    (ADR-NAV-013).
--}}
@php
    $authUser = auth()->user();
    $roleName = $authUser?->role?->name;

    /** @var \App\Platform\Navigation\NavigationVisibilityResolver $navResolver */
    $navResolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);
    /** @var \App\Platform\Navigation\NavigationRegistry $navRegistry */
    $navRegistry = app(\App\Platform\Navigation\NavigationRegistry::class);

    $navSections = $navResolver->sidebar($authUser);

    // Resolve the active hub exactly once, from the registry (never from a
    // hand-maintained pattern list). This is the G-6 contract: one hub active.
    $activeHubId = null;
    $currentRouteName = request()->route()?->getName();
    if ($currentRouteName !== null) {
        $activeNode = $navRegistry->primaryForRoute($currentRouteName);
        $activeHubId = $navRegistry->hubFor($activeNode)?->id;
    }

    $homeUrl = route('home');
@endphp

{{-- Mobile drawer overlay --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false" x-transition.opacity.duration.200ms
    class="fixed inset-0 bg-overlay-scrim backdrop-blur-[2px] z-40 md:hidden" x-cloak aria-hidden="true"></div>

{{-- Mobile drawer trigger button --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true; $nextTick(() => { const first = document.querySelector('#main-sidebar a, #main-sidebar button'); if (first) first.focus(); })"
    id="mobile-menu-btn"
    aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Open main navigation menu"
    class="md:hidden fixed top-2 left-2 z-40 w-11 h-11 flex items-center justify-center text-shell-foreground-active bg-shell hover:bg-shell-accent border border-shell-border active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 rounded-lg cursor-pointer shadow-xs motion-reduce:transition-none">
    <span class="material-symbols-rounded text-[24px]" aria-hidden="true">menu</span>
</button>

<aside id="main-sidebar"
    x-data="{
        openPopover: false
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

        {{-- MODE A: Expanded — 9 domain labels -> 19 hub rows (max depth 3, ADR-NAV-003) --}}
        <div x-show="wide" class="w-full">
            @foreach ($navSections as $section)
                @php
                    /** @var \App\Platform\Navigation\NavigationNode $domain */
                    $domain = $section['domain'];
                @endphp

                {{-- Domain label: a visual separator, never interactive (ADR-NAV-003 R-7) --}}
                <p class="px-3 {{ $loop->first ? 'pt-1' : 'pt-4' }} pb-1 text-[10px] font-bold uppercase tracking-[0.08em] text-shell-foreground/70 select-none">
                    {{ $domain->label }}
                </p>

                <ul class="space-y-0.5">
                    @foreach ($section['hubs'] as $hub)
                        @php
                            /** @var \App\Platform\Navigation\NavigationNode $hub */
                            $isActive = $activeHubId === $hub->id;
                            $hubUrl = \Illuminate\Support\Facades\Route::has((string) $hub->route)
                                ? route((string) $hub->route)
                                : '#';
                            $pageCount = count($navResolver->visiblePagesFor($hub, $authUser));
                        @endphp
                        <li>
                            <x-erp.nav-link
                                :href="$hubUrl"
                                :active="$isActive"
                                :label="$hub->label.' ('.($hub->scope === 'store' ? 'Active Branch' : 'Tenant-level').')'"
                                class="w-full px-3 min-h-[44px] md:min-h-[38px] justify-start text-[13px] {{ $isActive ? 'before:absolute before:left-0 before:top-2 before:bottom-2 before:w-[3px] before:rounded-r before:bg-shell-foreground-active' : '' }}">
                                <span class="material-symbols-rounded text-[20px] shrink-0 text-center flex items-center justify-center transition-colors motion-reduce:transition-none {{ $isActive ? 'text-shell-foreground-active' : 'text-shell-foreground group-hover:text-shell-foreground-active' }}" aria-hidden="true">{{ $hub->icon }}</span>

                                <span class="ml-2.5 flex-1 truncate overflow-hidden">{{ $hub->label }}</span>

                                <span class="sr-only">{{ $pageCount }} halaman</span>
                            </x-erp.nav-link>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>

        {{-- MODE B: Micro-rail — one icon per hub, never per section (ADR-NAV-003) --}}
        <ul x-show="!wide" x-cloak class="w-full flex flex-col items-center space-y-1.5 py-1.5">
            @foreach ($navSections as $section)
                @foreach ($section['hubs'] as $hub)
                    @php
                        /** @var \App\Platform\Navigation\NavigationNode $hub */
                        $isActive = $activeHubId === $hub->id;
                        $hubUrl = \Illuminate\Support\Facades\Route::has((string) $hub->route)
                            ? route((string) $hub->route)
                            : '#';
                    @endphp
                    <li class="flex justify-center w-full">
                        <x-erp.nav-link
                            :href="$hubUrl"
                            :active="$isActive"
                            :label="$hub->label"
                            class="w-9 h-9 justify-center rounded-[var(--radius-card)] active:scale-95 focus-visible:ring-inset-0 motion-reduce:transition-none {{ $isActive ? 'bg-shell-foreground-active text-white shadow-xs' : '' }}">
                            <span class="material-symbols-rounded text-[20px] {{ $isActive ? 'text-white' : 'text-shell-foreground group-hover:text-shell-foreground-active' }}" aria-hidden="true">{{ $hub->icon }}</span>
                            <span class="sr-only">{{ $hub->label }}</span>
                        </x-erp.nav-link>
                    </li>
                @endforeach
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
            class="absolute bottom-full mb-2 bg-card text-shell-foreground-active rounded-2xl shadow-xl border border-shell-border overflow-hidden z-50 divide-y divide-shell-border"
            :class="wide ? 'left-3 right-3' : 'left-2 w-56 md:left-full md:ml-2'">
            <div class="px-3.5 py-3 bg-card">
                <p class="text-xs font-bold text-foreground truncate">{{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-[11px] text-muted-foreground truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                @if ($roleName)
                    <span
                        class="inline-flex items-center mt-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-muted text-foreground border border-border">{{ $roleName }}</span>
                @endif
            </div>
            <div class="p-1.5 space-y-0.5 bg-card">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[44px] md:min-h-[36px] rounded-xl text-xs font-medium text-muted-foreground hover:text-foreground hover:bg-muted active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring transition-all duration-150 motion-reduce:transition-none">
                    <span class="material-symbols-rounded text-[16px]" aria-hidden="true">person</span> My Profile
                </a>
                @if ($navRegistry->find('nav.admin.settings.general') !== null && $navResolver->isVisible($navRegistry->find('nav.admin.settings.general'), $authUser))
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[44px] md:min-h-[36px] rounded-xl text-xs font-medium text-muted-foreground hover:text-foreground hover:bg-muted active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring transition-all duration-150 motion-reduce:transition-none">
                    <span class="material-symbols-rounded text-[16px]" aria-hidden="true">settings</span> Settings
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}"
                    @submit="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Signing out will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 px-2.5 min-h-[44px] md:min-h-[36px] rounded-xl text-xs font-semibold text-destructive hover:bg-destructive/10 hover:text-destructive active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive/40 transition-all duration-150 cursor-pointer motion-reduce:transition-none">
                        <span class="material-symbols-rounded text-[16px]" aria-hidden="true">logout</span> Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
