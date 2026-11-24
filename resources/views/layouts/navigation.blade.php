{{--
    APEIRON ENTERPRISE SIDEBAR — Calm Hub Navigation (ADR-NAV-001 / ADR-NAV-003 / ADR-NAV-004 / Wave 8 Tier-1)

    Single source of truth: config/navigation/domains/*.php through
    App\Platform\Navigation\NavigationVisibilityResolver.

    Structure: 4 domain groups (3 primary scrolling + 1 pinned settings) -> 10 clickable hub rows.
    Zero vertical scroll on 1366x768 (expanded <= 575px, collapsed micro-rail ~515px).
    Zero accordions, zero item-count badges, zero "SOON" rows.
    L2 module navigation lives in the canvas as a horizontal tab bar (ADR-NAV-013).
--}}
@php
    $authUser = auth()->user();
    $roleName = $authUser?->role?->name;

    /** @var \App\Platform\Navigation\NavigationVisibilityResolver $navResolver */
    $navResolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);
    /** @var \App\Platform\Navigation\NavigationRegistry $navRegistry */
    $navRegistry = app(\App\Platform\Navigation\NavigationRegistry::class);

    $navSections = $navResolver->sidebar($authUser);

    // Split primary scrolling sections vs pinned sections (Settings)
    $primarySections = array_values(array_filter($navSections, fn (array $section): bool => ! $section['domain']->isPinned()));
    $pinnedSections = array_values(array_filter($navSections, fn (array $section): bool => $section['domain']->isPinned()));

    // Resolve the active hub exactly once, from the registry (never from a
    // hand-maintained pattern list). This is the G-6 contract: one hub active.
    $activeHubId = null;
    $currentRouteName = request()->route()?->getName();
    if ($currentRouteName !== null) {
        $activeNode = $navRegistry->primaryForRoute($currentRouteName);
        $activeHubId = $activeNode !== null ? $navRegistry->hubFor($activeNode)?->id : null;
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
    :class="[ isMobile ? (sidebarMobileOpen ? 'fixed inset-y-0 left-0 translate-x-0 shadow-2xl z-40' : 'fixed inset-y-0 left-0 -translate-x-full z-40') : 'relative translate-x-0 z-30', wide ? (isMobile ? 'w-72' : 'w-60') : 'w-14' ]"
    class="h-full flex flex-col bg-[var(--chrome-surface)] text-shell-foreground select-none overflow-x-hidden transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

    {{-- Header: DEMURE Luxury Brand Logo + Seamless Collapse/Expand Toggle --}}
    <div class="h-14 flex items-center shrink-0 transition-all duration-200" :class="wide ? 'justify-between px-3.5' : 'justify-center px-0'">
        <div x-show="wide" class="flex items-center min-w-0">
            <a href="{{ $homeUrl }}" wire:navigate.hover
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
                {{-- Hover: Arrow > icon --}}
                <span class="hidden group-hover:flex items-center justify-center transition-all duration-150 text-shell-foreground-active">
                    <span class="material-symbols-rounded text-[20px]">chevron_right</span>
                </span>
            </button>
        </div>

        {{-- Seamless Sidebar Collapse Chevron (Desktop/Laptop - when wide) --}}
        <button type="button" @click="expanded = false" x-show="wide && !sidebarMobileOpen" x-cloak title="Collapse sidebar"
            aria-label="Collapse sidebar"
            class="hidden md:inline-flex w-7 h-7 rounded-md items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted/60 transition-colors duration-150 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring">
            <span class="material-symbols-rounded text-[18px] transition-transform duration-200" :class="expanded ? '' : 'rotate-180'">chevron_left</span>
        </button>

        {{-- Close drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus())" x-show="sidebarMobileOpen" x-cloak
            aria-label="Close navigation menu"
            class="md:hidden w-11 h-11 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
    </div>

    {{-- Main Navigation Container (Scrollable Primary Sections) --}}
    <nav id="sidebar-nav-container" class="flex-1 min-h-0 py-2 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-2.5' : 'px-1.5 flex flex-col items-center'"
        aria-label="Main navigation">

        {{-- MODE A: Expanded Primary Sections --}}
        <div x-show="wide" class="w-full space-y-3">
            @foreach ($primarySections as $section)
                @php
                    /** @var \App\Platform\Navigation\NavigationNode $domain */
                    $domain = $section['domain'];
                @endphp

                <div>
                    {{-- Domain label: full opacity text-muted-foreground for WCAG AAA --}}
                    <p class="px-2.5 pb-1 text-[10px] font-semibold uppercase tracking-[0.06em] text-muted-foreground select-none">
                        {{ $domain->label }}
                    </p>

                    <ul class="space-y-0.5">
                        @foreach ($section['hubs'] as $hub)
                            @php
                                /** @var \App\Platform\Navigation\NavigationNode $hub */
                                $isActive = $activeHubId === $hub->id;
                                $hubUrl = $navResolver->landingUrlForHub($hub, $authUser);
                            @endphp
                            <li>
                                <x-erp.nav-link
                                    :href="$hubUrl"
                                    :active="$isActive"
                                    :label="$hub->label"
                                    class="w-full px-2.5 h-[34px] min-h-[44px] md:min-h-[34px] justify-start text-[12.5px] font-medium {{ $isActive ? 'bg-accent text-accent-foreground font-semibold before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-[2.5px] before:rounded-r before:bg-foreground' : '' }}">
                                    <span class="material-symbols-rounded text-[18px] shrink-0 text-center flex items-center justify-center transition-colors motion-reduce:transition-none {{ $isActive ? 'text-accent-foreground' : 'text-muted-foreground group-hover:text-foreground' }}" aria-hidden="true">{{ $hub->icon }}</span>

                                    <span class="ml-2.5 flex-1 truncate overflow-hidden">{{ $hub->label }}</span>
                                </x-erp.nav-link>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- MODE B: Micro-rail Collapsed Primary Hubs (h-9 w-9 on desktop, space-y-1) --}}
        <ul x-show="!wide" x-cloak class="w-full flex flex-col items-center space-y-1 py-1">
            @foreach ($primarySections as $section)
                @foreach ($section['hubs'] as $hub)
                    @php
                        /** @var \App\Platform\Navigation\NavigationNode $hub */
                        $isActive = $activeHubId === $hub->id;
                        $hubUrl = $navResolver->landingUrlForHub($hub, $authUser);
                    @endphp
                    <li class="flex justify-center w-full">
                        <x-erp.nav-link
                            :href="$hubUrl"
                            :active="$isActive"
                            :label="$hub->label"
                            class="w-9 h-9 min-h-[44px] min-w-[44px] md:min-h-[36px] md:min-w-[36px] md:w-9 md:h-9 justify-center rounded-[var(--radius-control)] active:scale-95 focus-visible:ring-inset-0 motion-reduce:transition-none {{ $isActive ? 'bg-accent text-accent-foreground shadow-2xs font-semibold' : '' }}">
                            <span class="material-symbols-rounded text-[18px] {{ $isActive ? 'text-accent-foreground' : 'text-muted-foreground group-hover:text-foreground' }}" aria-hidden="true">{{ $hub->icon }}</span>
                            <span class="sr-only">{{ $hub->label }}</span>
                        </x-erp.nav-link>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </nav>

    {{-- PINNED SECTION: Settings (Pinned directly above user footer, G-4 zero scroll) --}}
    @if ($pinnedSections !== [])
        <div class="shrink-0 border-t border-shell-border pt-1.5 pb-1" :class="wide ? 'px-2.5' : 'px-1.5 flex flex-col items-center'">
            {{-- Expanded Pinned Hubs --}}
            <div x-show="wide" class="w-full">
                @foreach ($pinnedSections as $section)
                    <ul class="space-y-0.5">
                        @foreach ($section['hubs'] as $hub)
                            @php
                                /** @var \App\Platform\Navigation\NavigationNode $hub */
                                $isActive = $activeHubId === $hub->id;
                                $hubUrl = $navResolver->landingUrlForHub($hub, $authUser);
                            @endphp
                            <li>
                                <x-erp.nav-link
                                    :href="$hubUrl"
                                    :active="$isActive"
                                    :label="$hub->label"
                                    class="w-full px-2.5 h-[34px] min-h-[44px] md:min-h-[34px] justify-start text-[12.5px] font-medium {{ $isActive ? 'bg-accent text-accent-foreground font-semibold before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-[2.5px] before:rounded-r before:bg-foreground' : '' }}">
                                    <span class="material-symbols-rounded text-[18px] shrink-0 text-center flex items-center justify-center transition-colors motion-reduce:transition-none {{ $isActive ? 'text-accent-foreground' : 'text-muted-foreground group-hover:text-foreground' }}" aria-hidden="true">{{ $hub->icon }}</span>

                                    <span class="ml-2.5 flex-1 truncate overflow-hidden">{{ $hub->label }}</span>
                                </x-erp.nav-link>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>

            {{-- Collapsed Pinned Hubs (h-9 w-9, space-y-1) --}}
            <ul x-show="!wide" x-cloak class="w-full flex flex-col items-center space-y-1">
                @foreach ($pinnedSections as $section)
                    @foreach ($section['hubs'] as $hub)
                        @php
                            /** @var \App\Platform\Navigation\NavigationNode $hub */
                            $isActive = $activeHubId === $hub->id;
                            $hubUrl = $navResolver->landingUrlForHub($hub, $authUser);
                        @endphp
                        <li class="flex justify-center w-full">
                            <x-erp.nav-link
                                :href="$hubUrl"
                                :active="$isActive"
                                :label="$hub->label"
                                class="w-9 h-9 min-h-[44px] min-w-[44px] md:min-h-[36px] md:min-w-[36px] md:w-9 md:h-9 justify-center rounded-[var(--radius-control)] active:scale-95 focus-visible:ring-inset-0 motion-reduce:transition-none {{ $isActive ? 'bg-accent text-accent-foreground shadow-2xs font-semibold' : '' }}">
                                <span class="material-symbols-rounded text-[18px] {{ $isActive ? 'text-accent-foreground' : 'text-muted-foreground group-hover:text-foreground' }}" aria-hidden="true">{{ $hub->icon }}</span>
                                <span class="sr-only">{{ $hub->label }}</span>
                            </x-erp.nav-link>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    {{-- DEMURE Luxury Footer: User Card --}}
    <div class="shrink-0 mt-auto relative border-t border-shell-border" :class="wide ? 'p-2.5' : 'py-2 px-1.5 flex flex-col items-center'">
        
        {{-- Luxury User Card --}}
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            aria-haspopup="true"
            aria-label="User account menu"
            class="flex items-center rounded-xl transition-all duration-150 whitespace-nowrap min-h-[38px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring cursor-pointer group select-none"
            :class="[wide ? 'w-full p-1.5 hover:bg-shell-accent' : 'w-9 h-9 justify-center p-0 hover:bg-shell-accent rounded-xl', openPopover ? 'bg-shell-accent' : '']"
            title="{{ auth()->user()->name ?? 'Profile' }}">
            <div
                class="w-7 h-7 rounded-full bg-shell-foreground-active text-background flex items-center justify-center font-bold text-xs shrink-0 shadow-2xs">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div x-show="wide" x-cloak class="ml-2.5 flex-1 text-left overflow-hidden transition-all duration-150">
                <p class="text-[12px] font-bold text-shell-foreground-active truncate leading-tight group-hover:text-shell-foreground-active">{{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-[10px] text-muted-foreground truncate leading-tight mt-0.5">{{ auth()->user()->email ?? $roleName }}</p>
            </div>
            <span x-show="wide" x-cloak
                class="material-symbols-rounded text-[16px] text-muted-foreground transition-transform duration-200 group-hover:text-foreground"
                :class="openPopover ? 'rotate-90 text-foreground' : ''">chevron_right</span>
        </button>

        {{-- Dropdown Profile Popover (Teleported to body so never clipped by sidebar overflow) --}}
        <template x-teleport="body">
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
                class="fixed z-[55] bg-popover text-popover-foreground rounded-2xl shadow-popover border border-border-strong overflow-hidden divide-y divide-border-strong w-60"
                :class="wide ? 'left-3 bottom-16' : 'left-[64px] bottom-3'">
                <div class="px-3.5 py-3 bg-popover">
                    <p class="text-xs font-bold text-popover-foreground truncate">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-[11px] text-muted-foreground truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                    @if ($roleName)
                        <span
                            class="inline-flex items-center mt-2 px-1.5 py-0.5 rounded text-[10px] font-medium bg-muted text-foreground border border-border">{{ $roleName }}</span>
                    @endif
                </div>
                <div class="p-1.5 space-y-0.5 bg-popover">
                    <a href="{{ route('profile.edit') }}" wire:navigate.hover
                        @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; } openPopover = false;"
                        role="menuitem"
                        class="flex items-center gap-2.5 px-2.5 min-h-[44px] md:min-h-[36px] rounded-xl text-xs font-medium text-muted-foreground hover:text-foreground hover:bg-muted active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring transition-all duration-150 motion-reduce:transition-none">
                        <span class="material-symbols-rounded text-[16px]" aria-hidden="true">person</span> My Profile
                    </a>
                    @if ($navRegistry->find('nav.admin.settings.general') !== null && $navResolver->isVisible($navRegistry->find('nav.admin.settings.general'), $authUser))
                    <a href="{{ route('settings.index') }}" wire:navigate.hover
                        @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (sidebarMobileOpen) { sidebarMobileOpen = false; } openPopover = false;"
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
        </template>
    </div>
</aside>
