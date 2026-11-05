@props([
    'variant' => null,
    'color' => null,
    'type' => null,
    'status' => null,
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
    'asBadge' => false,
    'dot' => false,
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

    if ($colorMapped !== null) {
        $asBadge = true;
    }

    if ($status instanceof \BackedEnum) {
        if ($variant === null && method_exists($status, 'badgeType')) {
            $variant = $status->badgeType();
        }
    } elseif (is_string($status) && $variant === null) {
        $variant = $status;
    }

    // `primary` and `outline` are structural variants, not statuses: they are
    // resolved against semantic UI tokens rather than StatusPalette.
    $areStructural = in_array(strtolower((string) $variant), ['primary', 'outline'], true);
    $semantic = \App\Support\Color\StatusPalette::variant($variant ?? 'neutral');

    $isBadge = $pill || $asBadge || $dot || $areStructural || $semantic !== \App\Support\Color\StatusVariant::Neutral;

    $structure = 'border rounded-md px-2 py-0.5 text-xs';

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center whitespace-nowrap font-medium tracking-normal transition-colors',

        // ── Pill / chip form ──
        $structure => $isBadge,
        \App\Support\Color\StatusPalette::badgeClasses($semantic) => $isBadge && ! $areStructural,
        'bg-primary text-primary-foreground border-primary' => $isBadge && strtolower((string) $variant) === 'primary',
        'bg-transparent text-foreground border-border' => $isBadge && strtolower((string) $variant) === 'outline',

        // ── Inline text form (no fill): used for ordinary status cells ──
        'bg-transparent border-0 p-0 text-xs sm:text-sm' => ! $isBadge,
        \App\Support\Color\StatusPalette::textClasses($semantic) => ! $isBadge && ! $areStructural,
        'text-foreground' => ! $isBadge && $areStructural,
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if($dot)
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
