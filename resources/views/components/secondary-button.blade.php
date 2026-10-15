@props(['disabled' => false])

<x-button variant="secondary" :disabled="$disabled" type="button" {{ $attributes }}>
    {{ $slot }}
</x-button>
