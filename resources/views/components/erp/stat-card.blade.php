@props([
    'label' => '',
    'value' => '0',
    'delta' => null,
    'deltaVariant' => 'neutral',
    'icon' => null,
    'hint' => null,
    'mono' => true,
])

@php
    $deltaClasses = match ($deltaVariant) {
        'positive', 'success' => 'bg-[#ECFDF5] text-[#047857] border-emerald-200/70',
        'negative', 'danger', 'destructive' => 'bg-[#FFF1F2] text-[#BE123C] border-rose-200/70',
        'warning' => 'bg-[#FBF3E7] text-[#8A5A16] border-amber-200/70',
        default => 'bg-secondary text-muted-foreground border-border',
    };
@endphp

<div data-gsap="kpi-card" {{ $attributes->twMerge('card-surface p-4 sm:p-5 flex flex-col justify-between gap-2') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
            {{ $label }}
        </span>
        @if ($icon)
            <span class="inline-flex size-8 items-center justify-center rounded-lg bg-secondary text-foreground/70 shrink-0">
                <span class="material-symbols-rounded text-[18px] leading-none" aria-hidden="true">{{ $icon }}</span>
            </span>
        @endif
    </div>

    <div class="flex items-baseline justify-between gap-2 mt-1">
        <div @class(['text-xl sm:text-2xl font-bold text-foreground tracking-tight', 'font-mono tabular-nums' => $mono])>
            {{ $value }}
        </div>

        @if ($delta !== null && $delta !== '')
            <span class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold {{ $deltaClasses }}">
                {{ $delta }}
            </span>
        @endif
    </div>

    @if ($hint || trim($slot ?? '') !== '')
        <div class="text-xs text-muted-foreground">
            {{ $hint ?? $slot }}
        </div>
    @endif
</div>
