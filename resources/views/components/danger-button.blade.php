@props(['disabled' => false])

<x-ui.button variant="destructive" :disabled="$disabled" type="submit" {{ $attributes }}>
    {{ $slot }}
</x-ui.button>
