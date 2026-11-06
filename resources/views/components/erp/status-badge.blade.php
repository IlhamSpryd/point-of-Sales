@props([
    'status' => null,
    'label' => null,
    'size' => 'sm',
    'dot' => true,
    'pill' => false,
])

@php
    // Thin forwarder: x-ui.badge resolves the variant via StatusPalette.
    $rawStatus = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $displayLabel = $label
        ?? ($status instanceof \BackedEnum && method_exists($status, 'label')
            ? $status->label()
            : ucwords(str_replace(['_', '-'], ' ', strtolower($rawStatus ?: 'unknown'))));
@endphp

<x-ui.badge :variant="$rawStatus ?: null" :dot="$dot" :pill="$pill" {{ $attributes }}>
    {{ $slot->isNotEmpty() ? $slot : $displayLabel }}
</x-ui.badge>
