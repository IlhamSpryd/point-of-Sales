@props(['disabled' => false])

<x-ui.button variant="secondary" :disabled="$disabled" type="button" {{ $attributes }}>
    {{ $slot }}
</x-ui.button>
