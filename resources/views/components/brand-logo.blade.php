@props([
    'variant' => 'wordmark', // 'wordmark' | 'symbol' | 'minimal' | 'image'
    'mode' => 'auto', // 'auto' (currentColor) | 'light' (#171717) | 'dark' (#FFFFFF)
    'size' => null,
    'alt' => 'Apeiron Systems',
])

@php
    $colorVariant = match($mode) {
        'light' => 'light',
        'dark' => 'dark',
        default => 'master',
    };
@endphp

@if ($variant === 'symbol' || $variant === 'minimal')
    <x-apeiron-symbol
        :variant="$variant === 'minimal' ? 'minimal' : $colorVariant"
        :size="$size"
        {{ $attributes }}
    />
@elseif ($variant === 'image')
    <picture {{ $attributes }}>
        <source srcset="{{ asset('assets/brand/apeiron-symbol-dark.svg') }}" media="(prefers-color-scheme: dark)">
        <img src="{{ asset('assets/brand/apeiron-symbol-light.svg') }}" alt="{{ $alt }}" class="{{ $size ?? 'h-8 w-auto' }}">
    </picture>
@else
    <x-apeiron-wordmark
        :variant="$colorVariant"
        :size="$size"
        {{ $attributes }}
    />
@endif
