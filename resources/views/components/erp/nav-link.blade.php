@props([
    'href' => '#',
    'active' => false,
    'navigate' => true,
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
        $activeClasses = 'bg-shell-accent text-shell-foreground-active font-semibold border border-shell-border shadow-2xs';
        $idleClasses = 'text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent/60';
    }

    $merged = $class ?? '';
@endphp

<a href="{{ $href }}"
   @if ($navigate) wire:navigate @endif
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
