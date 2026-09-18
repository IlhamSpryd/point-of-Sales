@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-[#37352F]']) }}>
    {{ $value ?? $slot }}
</label>
