@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-apeiron-ink']) }}>
    {{ $value ?? $slot }}
</label>
