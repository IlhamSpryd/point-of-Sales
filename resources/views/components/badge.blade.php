@props([
    'type' => null,
    'variant' => null,
    'status' => null,
    'dot' => true,
    'asBadge' => true,
    'pill' => false,
])

@php
    // Thin forwarder: x-ui.badge is the single badge implementation.
    // All status -> colour resolution happens there via StatusPalette.
    $type = $type ?? $variant;

    if ($type === null && $status instanceof \BackedEnum && method_exists($status, 'badgeType')) {
        $type = $status->badgeType();
    }
@endphp

<x-ui.badge
    :status="$status"
    :variant="$type"
    :dot="$dot"
    :pill="$pill"
    :as-badge="$asBadge"
    {{ $attributes }}
>
    {{ $slot }}
</x-ui.badge>
