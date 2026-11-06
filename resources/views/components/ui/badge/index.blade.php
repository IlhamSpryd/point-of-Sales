@props([
    'variant' => null,
    'color' => null,
    'type' => null,
    'status' => null,
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
    'chip' => false,
    'asBadge' => false,
    'dot' => true,
])

@php
    if ($variant === 'dot') {
        $dot = true;
        $variant = null;
    }

    $colorMapped = $color ? match (strtolower((string) $color)) {
        'error', 'danger' => 'destructive',
        'brand' => 'primary',
        'gray' => 'secondary',
        default => strtolower((string) $color),
    } : null;

    $variant = $variant ?? $colorMapped ?? $type;

    if ($status instanceof \BackedEnum) {
        if ($variant === null && method_exists($status, 'badgeType')) {
            $variant = $status->badgeType();
        }
    } elseif (is_string($status) && $variant === null) {
        $variant = $status;
    }

    // `primary` and `outline` are structural variants, not statuses
    $areStructural = in_array(strtolower((string) $variant), ['primary', 'outline'], true);
    $semantic = \App\Support\Color\StatusPalette::variant($variant ?? 'neutral');

    // Only render chunky pill/chip if explicitly requested or structural
    $isBadge = $pill || $chip || $asBadge || $areStructural;
    $showDot = $dot && ! $isBadge;

    $structure = 'border rounded-md px-2 py-0.5 text-xs';

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center whitespace-nowrap font-medium tracking-normal transition-colors text-xs',

        // ── Pill / chip form (explicit only) ──
        $structure => $isBadge,
        \App\Support\Color\StatusPalette::badgeClasses($semantic) => $isBadge && ! $areStructural,
        'bg-primary text-primary-foreground border-primary' => $isBadge && strtolower((string) $variant) === 'primary',
        'bg-transparent text-foreground border-border' => $isBadge && strtolower((string) $variant) === 'outline',

        // ── Minimalist inline status form (clean enterprise default): NO background fill, NO borders ──
        'bg-transparent border-0 p-0 text-foreground' => ! $isBadge,
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if($showDot)
        <span class="w-1.5 h-1.5 rounded-full shrink-0 mr-1.5 {{ \App\Support\Color\StatusPalette::dotClasses($semantic) }}" aria-hidden="true"></span>
    @endif
    @if ($icon && $iconPosition === 'before')
        @if (\Illuminate\Support\Facades\View::exists('components.'.str_replace('.', '/', $icon)))
            <x-dynamic-component :component="$icon" class="size-3.5 shrink-0" />
        @else
            <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
    @endif

    {{ $slot->isNotEmpty() ? $slot : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : '') }}

    @if ($icon && $iconPosition === 'after')
        @if (\Illuminate\Support\Facades\View::exists('components.'.str_replace('.', '/', $icon)))
            <x-dynamic-component :component="$icon" class="size-3.5 shrink-0" />
        @else
            <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
    @endif
</span>
