@props([
    'label' => '',
    'title' => null,
    'value' => '0',
    'delta' => null,
    'deltaVariant' => 'neutral',
    'icon' => null,
    'hint' => null,
    'description' => null,
    'subtext' => null,
    'mono' => true,
])

@php
    $displayLabel = !empty($label) ? $label : ($title ?? '');
    $displayHint = $hint ?? $description ?? $subtext ?? null;

    $deltaClasses = match ($deltaVariant) {
        'positive', 'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
        'negative', 'danger', 'destructive' => 'bg-rose-50 text-rose-700 border-rose-200/80',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200/80',
        default => 'bg-muted text-muted-foreground border-border',
    };
@endphp

<div data-gsap="kpi-card" {{ $attributes->twMerge('card-surface bg-card border border-border rounded-xl p-5 flex flex-col justify-between gap-3 shadow-xs hover:border-border-strong transition-all') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground truncate">
            {!! $displayLabel !!}
        </span>
        @if ($icon)
            <span class="inline-flex size-9 items-center justify-center rounded-lg bg-secondary text-foreground border border-border shrink-0">
                <x-ui.icon :name="$icon" size="sm" class="text-foreground" />
            </span>
        @endif
    </div>

    <div class="flex items-baseline justify-between gap-2 mt-1">
        <div @class(['text-2xl sm:text-3xl font-extrabold text-foreground tracking-tight', 'numeric' => $mono])>
            {{ $value }}
        </div>

        @if ($delta !== null && $delta !== '')
            <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $deltaClasses }}">
                {{ $delta }}
            </span>
        @endif
    </div>

    @if ($displayHint || trim($slot ?? '') !== '')
        <div class="text-xs font-medium text-muted-foreground mt-1 leading-normal">
            {{ $displayHint ?? $slot }}
        </div>
    @endif
</div>
