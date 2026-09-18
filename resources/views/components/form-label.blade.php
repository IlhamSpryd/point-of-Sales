@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-[#37352F] mb-1.5']) }}>
    {{ $value ?? $slot }}
</label>
