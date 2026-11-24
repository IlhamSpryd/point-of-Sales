@props([
    'href' => '#',
    'active' => false,
    'navigate' => true,
    'hover' => true,
    'label' => null,
    'class' => null,
    'closeMobile' => true,
    'variant' => 'sidebar',
])

{{--
    Canonical navigation link (RISK-NAV-14 / ADR-NAV-004).

    Every sidebar row, L2 pill and palette-style entry renders through this
    component so the POS unsaved-cart guard is defined EXACTLY once. A missed
    guard silently discards an in-progress sale, so centralising it is a
    data-integrity control, not a convenience.

    Guard behaviour:
      - `window.apeironPosCartDirty` is set by the POS terminal when the cart has
        lines and is cleared on a successful transaction.
      - On click we confirm; if the operator cancels we preventDefault AND
        stopImmediatePropagation (wire:navigate listens at the document level, so
        preventDefault alone is not enough).
      - If they accept, the flag is cleared so the next hop is not re-prompted.
--}}

@php
    if ($variant === 'subnav') {
        $activeClasses = 'text-foreground font-semibold bg-transparent';
        $idleClasses = 'text-muted-foreground hover:text-foreground hover:bg-accent/70 font-medium bg-transparent';
    } else {
        $activeClasses = 'bg-accent text-accent-foreground font-semibold shadow-2xs';
        $idleClasses = 'text-muted-foreground hover:text-foreground hover:bg-accent/50 font-medium';
    }

    $merged = $class ?? '';
@endphp

<a href="{{ $href }}"
   @if ($navigate)
       @if ($hover) wire:navigate.hover @else wire:navigate @endif
   @endif
   @if ($active) aria-current="page" @endif
   @if ($label) title="{{ $label }}" @endif
   x-on:click="
        if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) {
            $event.preventDefault();
            $event.stopImmediatePropagation();
            return false;
        } else if (window.apeironPosCartDirty) {
            window.apeironPosCartDirty = false;
        }
        @if ($closeMobile) if (sidebarMobileOpen) { sidebarMobileOpen = false; } @endif
   "
   {{ $attributes->twMerge('group relative flex items-center rounded-[var(--radius-control)] whitespace-nowrap transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-shell-ring ' . $merged) }}
   class="{{ $active ? $activeClasses : $idleClasses }}">
    {{ $slot }}
</a>
