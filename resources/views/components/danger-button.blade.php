@props(['disabled' => false])

<x-button variant="danger" :disabled="$disabled" type="submit" {{ $attributes }}>
    {{ $slot }}
</x-button>
