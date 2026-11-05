@props([
    'value' => null,
    'for' => '',
])

<label for="{{ $for ?: (string) $attributes->get('for') }}" {{ $attributes->merge(['class' => 'block text-sm font-medium text-foreground text-apeiron-ink mb-1']) }}>
    {{ $value ?? $slot }}
</label>
