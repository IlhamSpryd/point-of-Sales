@props(['value'])

<x-form-label :value="$value ?? null" {{ $attributes }}>
    {{ $slot }}
</x-form-label>
