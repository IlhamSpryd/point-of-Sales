{{--
    L2 CONTEXTUAL MODULE SUB-NAVIGATION (ADR-NAV-013)

    Renders the horizontal pill bar for the hub that owns the CURRENT route.
    Suppressed automatically when the hub has zero or one visible page for the
    user (Governance Law R-5), so single-page hubs never show a one-item bar.

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
            'pricing' => 'nav.catalog.pricing',
            'inventory' => 'nav.supply.inventory',
            'purchasing' => 'nav.supply.procurement',
            'sales' => 'nav.sales.orders',
            'customers' => 'nav.sales.customers',
            'finance' => 'nav.finance.gl',
            'hr' => 'nav.workforce.people',
            'reports' => 'nav.analytics.bi',
            'organization' => 'nav.admin.org',
            'governance' => 'nav.admin.access',
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

    $resolvedAriaLabel = $ariaLabel ?? (($hubNode?->label ?? 'Module').' Navigation');
@endphp

@if ($hubNode !== null && $pages !== [])
    {{-- Semantic token compatibility: bg-primary text-primary-foreground --}}
    <div class="w-full mb-6 border-b border-border">
        <nav aria-label="{{ $resolvedAriaLabel }}"
            x-data
            x-init="$nextTick(() => {
                const activeTab = $el.querySelector('[aria-current=page]');
                if (activeTab) {
                    const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                    $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
                }
            })"
            class="flex items-center gap-6 overflow-x-auto scrollbar-hide -mb-px">
            @foreach ($pages as $page)
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
                    class="pb-3 pt-1 border-b-2 text-xs transition-colors motion-reduce:transition-none whitespace-nowrap min-h-[40px] inline-flex items-center {{ $isActive ? 'border-foreground text-foreground font-medium' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border font-normal' }}">
                    <span>{{ $page->label }}</span>
                </x-erp.nav-link>
            @endforeach
        </nav>
    </div>
@endif
