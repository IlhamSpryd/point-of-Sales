{{--
    L2 CONTEXTUAL MODULE SUB-NAVIGATION (ADR-NAV-013 / Wave 8 Tier-1 Calm Hub)

    Renders the horizontal tab bar for the hub that owns the CURRENT route.
    Suppressed automatically when the hub has zero or one visible page for the
    user (Governance Law R-5), so single-page hubs never show a one-item bar.

    Wave 8 Tier-1 Rule: Maximum 5 primary horizontal tabs + "More ▾" dropdown
    for tab 6+. When an item in "More ▾" is active, the dropdown trigger is
    actively illuminated (2px bg-foreground underline) and displays the active
    tab label for persistent wayfinding.

    Usage:
      <x-erp.module-sub-nav />                          -- auto-detects the hub
      <x-erp.module-sub-nav hub="nav.finance.gl" />     -- explicit hub id
      <x-erp.module-sub-nav hub="nav.finance.gl" aria-label="Finance Navigation" />

    Replaces the seven per-module partials and the legacy `module` string key.
--}}
@props([
    'hub' => null,
    'module' => null,
    'ariaLabel' => null,
])

@php
    /** @var \App\Platform\Navigation\NavigationRegistry $navRegistry */
    $navRegistry = app(\App\Platform\Navigation\NavigationRegistry::class);
    /** @var \App\Platform\Navigation\NavigationVisibilityResolver $navResolver */
    $navResolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);

    $user = auth()->user();
    $currentRouteName = request()->route()?->getName();

    $hubId = $hub;

    // Legacy callers pass a `module` key (pos-ops, catalog, ...). Map them onto
    // the registry so existing page includes keep working during the migration.
    if ($hubId === null && $module !== null) {
        $hubId = match ((string) $module) {
            'pos-ops' => 'nav.frontline.desk',
            'catalog' => 'nav.catalog.master',
            'pricing' => 'nav.catalog.master',
            'inventory' => 'nav.supply.inventory',
            'purchasing' => 'nav.supply.inventory',
            'sales' => 'nav.sales.orders',
            'customers' => 'nav.sales.orders',
            'finance' => 'nav.finance.gl',
            'hr' => 'nav.workforce.people',
            'reports' => 'nav.analytics.reports',
            'organization' => 'nav.settings',
            'governance' => 'nav.settings',
            default => null,
        };
    }

    if ($hubId === null && $currentRouteName !== null) {
        $currentNode = $navRegistry->primaryForRoute($currentRouteName);
        $hubId = $navRegistry->hubFor($currentNode)?->id;
    }

    $hubNode = $hubId !== null ? $navRegistry->find($hubId) : null;
    $pages = ($hubNode !== null && $hubNode->isHub())
        ? $navResolver->visiblePagesFor($hubNode, $user)
        : [];

    // R-5: a single visible page needs no pill bar.
    $pages = count($pages) > 1 ? $pages : [];

    // Wave 8: Enforce maximum 5 primary horizontal tabs + More ▾ for overflow
    $primaryPages = count($pages) > 5 ? array_slice($pages, 0, 5) : $pages;
    $morePages = count($pages) > 5 ? array_slice($pages, 5) : [];

    $activeMorePage = null;
    foreach ($morePages as $morePage) {
        if (request()->routeIs(...$morePage->active)) {
            $activeMorePage = $morePage;
            break;
        }
    }
    $hasActiveMore = $activeMorePage !== null;

    $resolvedAriaLabel = $ariaLabel ?? (($hubNode?->label ?? 'Module').' Navigation');
@endphp

@if ($hubNode !== null && $pages !== [])
    {{-- Semantic token compatibility: bg-primary text-primary-foreground --}}
    <div class="w-full mb-4 border-b border-border">
        <nav aria-label="{{ $resolvedAriaLabel }}"
            x-data
            x-init="$nextTick(() => {
                const activeTab = $el.querySelector('[aria-current=page]');
                if (activeTab) {
                    const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                    $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
                }
            })"
            class="flex items-center gap-1 sm:gap-1.5 h-9 overflow-x-auto scrollbar-hide no-scrollbar -mb-px">
            @foreach ($primaryPages as $page)
                @php
                    /** @var \App\Platform\Navigation\NavigationNode $page */
                    $isActive = request()->routeIs(...$page->active);
                    $pageUrl = \Illuminate\Support\Facades\Route::has((string) $page->route)
                        ? route((string) $page->route)
                        : '#';
                @endphp
                <x-erp.nav-link
                    :href="$pageUrl"
                    :active="$isActive"
                    :close-mobile="false"
                    variant="subnav"
                    class="relative h-9 md:h-9 min-h-[40px] md:min-h-0 px-2.5 py-1.5 text-[12px] inline-flex items-center justify-center rounded-[6px] transition-colors duration-150 whitespace-nowrap">
                    <span>{{ $page->label }}</span>
                    @if ($isActive)
                        <span class="h-[2px] bg-foreground rounded-t-full absolute bottom-0 inset-x-2" aria-hidden="true"></span>
                    @endif
                </x-erp.nav-link>
            @endforeach

            @if ($morePages !== [])
                <div class="relative inline-flex items-center" x-data="{ open: false }" @keydown.escape.stop="open = false" @click.outside="open = false">
                    <button type="button"
                        @click="open = !open"
                        :aria-expanded="open.toString()"
                        aria-haspopup="true"
                        aria-label="More {{ $hubNode->label }} navigation tabs"
                        class="relative h-9 md:h-9 min-h-[40px] md:min-h-0 px-2.5 py-1.5 text-[12px] inline-flex items-center justify-center gap-1 rounded-[6px] transition-colors duration-150 whitespace-nowrap cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-shell-ring {{ $hasActiveMore ? 'text-foreground font-semibold bg-accent/50' : 'text-muted-foreground hover:text-foreground hover:bg-accent/70 font-medium bg-transparent' }}">
                        <span>{{ $hasActiveMore ? $activeMorePage->label : 'More' }}</span>
                        <svg class="w-3.5 h-3.5 transition-transform duration-150" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        @if ($hasActiveMore)
                            <span class="h-[2px] bg-foreground rounded-t-full absolute bottom-0 inset-x-2" aria-hidden="true"></span>
                        @endif
                    </button>

                    <div x-show="open" x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                        x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                        role="menu"
                        aria-orientation="vertical"
                        class="absolute left-0 sm:left-auto sm:right-0 top-full mt-1 w-52 max-h-72 overflow-y-auto rounded-xl bg-popover text-popover-foreground shadow-popover border border-border-strong p-1 z-50 divide-y divide-border/40">
                        <div class="py-0.5 space-y-0.5">
                            @foreach ($morePages as $morePage)
                                @php
                                    /** @var \App\Platform\Navigation\NavigationNode $morePage */
                                    $isMoreActive = request()->routeIs(...$morePage->active);
                                    $moreUrl = \Illuminate\Support\Facades\Route::has((string) $morePage->route)
                                        ? route((string) $morePage->route)
                                        : '#';
                                @endphp
                                <a href="{{ $moreUrl }}"
                                   wire:navigate.hover
                                   role="menuitem"
                                   @click="open = false"
                                   @if ($isMoreActive) aria-current="page" @endif
                                   class="flex items-center justify-between px-2.5 py-1.5 text-xs rounded-lg font-medium transition-colors {{ $isMoreActive ? 'bg-accent text-accent-foreground font-semibold' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
                                    <span class="truncate">{{ $morePage->label }}</span>
                                    @if ($isMoreActive)
                                        <svg class="w-3.5 h-3.5 text-accent-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </nav>
    </div>
@endif
