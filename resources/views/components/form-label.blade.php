@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-apeiron-ink mb-1.5']) }}>
    {{ $value ?? $slot }}
</label>
