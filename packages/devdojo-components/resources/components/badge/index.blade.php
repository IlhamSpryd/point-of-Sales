@props([
    'variant' => 'primary',
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center justify-center whitespace-nowrap font-medium tracking-tight transition-colors',
        $pill ? 'rounded-full' : 'rounded-medium',
        /* Sizes */
        'gap-1 px-2 py-0.5 text-xs [&>svg]:size-3' => $size === 'sm',
        'gap-1.5 px-2.5 py-1 text-xs [&>svg]:size-3.5' => $size === 'md',
        'gap-1.5 px-3 py-1 text-sm [&>svg]:size-4' => $size === 'lg',
        /* Variants — solid brand, soft status (matching the alert palette) and outline */
        'bg-primary text-primary-foreground' => $variant === 'primary',
        'bg-secondary text-secondary-foreground' => $variant === 'secondary',
        'border border-foreground/15 text-foreground' => $variant === 'outline',
        'bg-destructive-muted text-destructive' => $variant === 'destructive',
        'bg-info-muted text-info' => $variant === 'info',
        'bg-success-muted text-success' => $variant === 'success',
        'bg-warning-muted text-warning' => $variant === 'warning',
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if ($icon && $iconPosition === 'before')
        <x-dynamic-component :component="$icon" />
    @endif

    {{ $slot }}

    @if ($icon && $iconPosition === 'after')
        <x-dynamic-component :component="$icon" />
    @endif
</span>
