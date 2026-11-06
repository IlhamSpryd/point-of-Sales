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
        'positive', 'success' => 'bg-success-muted text-success border-success-border',
        'negative', 'danger', 'destructive' => 'bg-destructive-muted text-destructive border-destructive-border',
        'warning' => 'bg-warning-muted text-warning border-warning-border',
        default => 'bg-muted text-muted-foreground border-border',
    };

    // Icon holder is deliberately monochrome (P2: no tinted icon wells).
    $iconClasses = 'bg-muted text-muted-foreground border-border';
@endphp

<div data-gsap="kpi-card" {{ $attributes->twMerge('card-surface bg-card border border-border rounded-xl p-5 flex flex-col justify-between gap-3 shadow-xs hover:border-border-strong hover:shadow-sm transition-all duration-200') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground truncate">
            {{ $displayLabel }}
        </span>
        @if ($icon)
            <span class="inline-flex size-10 items-center justify-center rounded-xl border shrink-0 transition-transform duration-200 {{ $iconClasses }}">
                <x-ui.icon :name="$icon" size="sm" class="currentColor" />
            </span>
        @endif
    </div>

    <div class="flex items-baseline justify-between gap-2 mt-1">
        <div @class(['text-2xl sm:text-3xl font-extrabold text-foreground tracking-tight', 'numeric' => $mono])>
            {{ $value }}
        </div>

        @if ($displayDelta !== null && $displayDelta !== '')
            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold {{ $deltaClasses }}">
                @if ($direction === 'up')
                    <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">trending_up</span>
                @elseif ($direction === 'down')
                    <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">trending_down</span>
                @endif
                <span>{{ $displayDelta }}</span>
            </span>
        @endif
    </div>

    @if ($displayHint || trim($slot ?? '') !== '')
        <div class="text-xs font-medium text-muted-foreground mt-1 leading-normal flex items-center gap-1">
            {{ $displayHint ?? $slot }}
        </div>
    @endif
</div>
