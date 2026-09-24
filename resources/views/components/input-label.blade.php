@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-yovel-ink']) }}>
    {{ $value ?? $slot }}
</label>
