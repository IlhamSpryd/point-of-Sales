@props([
    'variant' => 'primary', // primary, secondary, danger, ghost
    'size' => 'md',         // sm, md, lg
    'hotkey' => null,
    'disabled' => false,
])

{{-- Legacy alias: forwards to the canonical x-ui.button. Deprecated. --}}
<x-ui.button :variant="$variant" :size="$size" :hotkey="$hotkey" :disabled="$disabled" {{ $attributes }}>
    {{ $slot }}
</x-ui.button>
