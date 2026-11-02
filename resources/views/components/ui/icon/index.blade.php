@props([
    'name' => null,
    'size' => 'md', // 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl'
    'fill' => false,
    'label' => null,
])

@php
    $sizeClasses = match ($size) {
        'xs' => 'text-[14px]',
        'sm' => 'text-[16px]',
        'md' => 'text-[20px]',
        'lg' => 'text-[24px]',
        'xl' => 'text-[28px]',
        '2xl' => 'text-[32px]',
        default => is_string($size) && !in_array($size, ['xs', 'sm', 'md', 'lg', 'xl', '2xl'], true) ? $size : 'text-[20px]',
    };

    $fontVariation = $fill
        ? "'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24"
        : "'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24";

    $iconName = $name ?? (string) $slot;
@endphp

<span
    {{ $attributes->merge(['class' => "material-symbols-rounded select-none inline-block align-middle leading-none shrink-0 {$sizeClasses}"]) }}
    style="font-variation-settings: {!! $fontVariation !!};"
    @if ($label)
        role="img"
        aria-label="{{ $label }}"
    @else
        aria-hidden="true"
    @endif
>{{ trim($iconName) }}</span>
