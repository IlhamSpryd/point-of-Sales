@props(['disabled' => false])

<x-button variant="primary" :disabled="$disabled" type="submit" {{ $attributes }}>
    {{ $slot }}
</x-button>
