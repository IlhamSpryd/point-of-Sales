@props([
    'href' => null,
    'icon' => null,
    'variant' => 'default',
    'type' => 'button',
])

@php
    $isDestructive = $variant === 'destructive' || $variant === 'danger';
    $baseClasses = 'flex w-full items-center gap-2 px-2.5 py-1.5 rounded-sm text-xs font-medium transition-colors cursor-pointer text-left select-none';
    $variantClasses = $isDestructive
        ? 'text-destructive hover:bg-destructive/10 hover:text-destructive focus:bg-destructive/10'
        : 'text-foreground hover:bg-accent hover:text-accent-foreground focus:bg-accent';
    $classes = "{$baseClasses} {$variantClasses}";
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->twMerge($classes) }}>
        @if ($icon)
            <span class="material-symbols-rounded text-[16px] leading-none shrink-0 opacity-70" aria-hidden="true">{{ $icon }}</span>
        @endif
        <span class="truncate">{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->twMerge($classes) }}>
        @if ($icon)
            <span class="material-symbols-rounded text-[16px] leading-none shrink-0 opacity-70" aria-hidden="true">{{ $icon }}</span>
        @endif
        <span class="truncate">{{ $slot }}</span>
    </button>
@endif

