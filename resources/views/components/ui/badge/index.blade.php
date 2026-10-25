@props([
    'variant' => null,
    'type' => null,
    'status' => null,
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
    'dot' => false,
])

@php
    $variant = $variant ?? $type;

    if ($status instanceof \BackedEnum) {
        if ($variant === null && method_exists($status, 'badgeType')) {
            $variant = $status->badgeType();
        }
    } elseif (is_string($status) && $variant === null) {
        $variant = $status;
    }

    $variant = match ($variant) {
        'danger', 'cancelled', 'void' => 'destructive',
        'paid', 'completed' => 'success',
        'pending' => 'warning',
        'neutral' => 'secondary',
        default => $variant ?? 'secondary',
    };

    $dotColors = [
        'success'     => 'bg-emerald-500',
        'destructive' => 'bg-rose-500',
        'warning'     => 'bg-amber-500',
        'info'        => 'bg-sky-500',
        'secondary'   => 'bg-zinc-400',
        'primary'     => 'bg-zinc-400',
        'outline'     => 'bg-zinc-400',
    ];

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
        'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' => $variant === 'destructive',
        'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300' => $variant === 'info',
        'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-300' => $variant === 'success',
        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $variant === 'warning',
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full shrink-0 {{ $dotColors[$variant] ?? 'bg-zinc-400' }}" aria-hidden="true"></span>
    @endif

    @if ($icon && $iconPosition === 'before')
        <x-dynamic-component :component="$icon" />
    @endif

    {{ $slot->isNotEmpty() ? $slot : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : '') }}

    @if ($icon && $iconPosition === 'after')
        <x-dynamic-component :component="$icon" />
    @endif
</span>
