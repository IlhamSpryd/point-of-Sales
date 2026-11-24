@props([
    'label' => '',
    'title' => null,
    'value' => '0',
    'delta' => null,
    'deltaVariant' => 'neutral',
    'change' => null,
    'changeDirection' => null,
    'trendDirection' => null,
    'trend' => null,
    'trendLabel' => null,
    'icon' => null,
    'iconVariant' => 'secondary',
    'hint' => null,
    'description' => null,
    'subtext' => null,
    'mono' => true,
])

@php
    $displayLabel = !empty($label) ? $label : ($title ?? '');
    $displayDelta = $change ?? $trend ?? $delta;
    $displayHint = $trendLabel ?? $hint ?? $description ?? $subtext ?? null;

    // Detect direction from props or delta string (+ or -)
    $direction = $changeDirection ?? $trendDirection;
    if (!$direction && $displayDelta !== null) {
        $strDelta = (string) $displayDelta;
        if (str_starts_with($strDelta, '+')) {
            $direction = 'up';
        } elseif (str_starts_with($strDelta, '-')) {
            $direction = 'down';
        }
    }

    // Resolve delta variant if not explicitly set
    $effectiveVariant = $deltaVariant;
    if ($effectiveVariant === 'neutral' && $direction === 'up') {
        $effectiveVariant = 'positive';
    } elseif ($effectiveVariant === 'neutral' && $direction === 'down') {
        $effectiveVariant = 'negative';
    }

    $deltaClasses = match ($effectiveVariant) {
        'positive', 'success' => 'text-positive font-medium',
        'negative', 'danger', 'destructive' => 'text-destructive font-medium',
        'warning' => 'text-warning font-medium',
        default => 'text-muted-foreground font-medium',
    };

    // Icon holder is deliberately monochrome (P2: no tinted icon wells).
    $iconClasses = 'bg-muted text-muted-foreground border-border';
@endphp

<div data-gsap="kpi-card" {{ $attributes->twMerge('card-surface bg-card border border-border rounded-[8px] p-3.5 sm:p-4 flex flex-col justify-between gap-3 shadow-xs hover:border-border-strong hover:shadow-sm transition-all duration-200') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-[11.5px] font-medium text-muted-foreground truncate">
            {{ $displayLabel }}
        </span>
        @if ($icon)
            <span class="inline-flex size-7 items-center justify-center rounded-[6px] border shrink-0 transition-transform duration-200 {{ $iconClasses }}">
                <x-ui.icon :name="$icon" size="xs" class="currentColor" />
            </span>
        @endif
    </div>

    <div class="flex items-baseline justify-between gap-2 mt-0.5">
        <div @class(['text-[18px] sm:text-[19px] font-semibold tracking-[-0.02em] text-foreground tabular-nums whitespace-nowrap', 'numeric' => $mono])>
            {{ $value }}
        </div>

        @if ($displayDelta !== null && $displayDelta !== '')
            <span class="inline-flex items-center gap-1 text-[11px] font-medium tabular-nums {{ $deltaClasses }}">
                @if ($direction === 'up')
                    <span class="material-symbols-rounded text-[13px] leading-none shrink-0" aria-hidden="true">trending_up</span>
                @elseif ($direction === 'down')
                    <span class="material-symbols-rounded text-[13px] leading-none shrink-0" aria-hidden="true">trending_down</span>
                @endif
                <span>{{ $displayDelta }}</span>
            </span>
        @endif
    </div>

    @if ($displayHint || trim($slot ?? '') !== '')
        <div class="text-[11px] font-medium text-muted-foreground mt-0.5 leading-normal flex items-center gap-1">
            {{ $displayHint ?? $slot }}
        </div>
    @endif
</div>
