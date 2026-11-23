@props(['disabled' => false])

<x-ui.button variant="primary" :disabled="$disabled" type="submit" {{ $attributes }}>
    {{ $slot }}
</x-ui.button>
