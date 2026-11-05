@props([
    'status' => null,
    'label' => null,
    'variant' => null,
])

@php
    // Thin forwarder: x-ui.badge resolves the variant and its colours.
    $rawStatus = $status instanceof \BackedEnum ? $status->value : (string) $status;

    $displayLabel = $label
        ?? ($status instanceof \BackedEnum && method_exists($status, 'label')
            ? $status->label()
            : ($rawStatus ? ucwords(str_replace(['_', '-'], ' ', strtolower($rawStatus))) : ''));
@endphp

<x-ui.badge :variant="$variant ?? $rawStatus" :as-badge="false">
    {{ $slot->isNotEmpty() ? $slot : $displayLabel }}
</x-ui.badge>
