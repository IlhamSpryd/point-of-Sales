@props(['disabled' => false])

<x-button variant="primary" :disabled="$disabled" {{ $attributes }}>
    {{ $slot }}
</x-button>
