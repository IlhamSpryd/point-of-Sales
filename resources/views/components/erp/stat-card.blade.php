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
        'positive', 'success' => 'bg-[#ECFDF5] text-[#047857] border-emerald-200/80',
        'negative', 'danger', 'destructive' => 'bg-[#FFF1F2] text-[#BE123C] border-rose-200/80',
        'warning' => 'bg-[#FBF3E7] text-[#8A5A16] border-amber-200/80',
        default => 'bg-[#F3F4F6] text-[#4B5563] border-[#E5E7EB]',
    };
@endphp

<div data-gsap="kpi-card" {{ $attributes->twMerge('card-surface bg-white border border-[#E5E7EB] rounded-2xl p-5 flex flex-col justify-between gap-3 shadow-xs hover:border-[#D1D5DB] transition-all') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-bold uppercase tracking-wider text-[#4B5563] truncate">
            {{ $displayLabel }}
        </span>
        @if ($icon)
            <span class="inline-flex size-9 items-center justify-center rounded-xl bg-[#F3F4F6] text-[#1F2937] border border-[#E5E7EB] shrink-0">
                <span class="material-symbols-rounded text-[19px] leading-none text-[#374151]" aria-hidden="true">{{ $icon }}</span>
            </span>
        @endif
    </div>

    <div class="flex items-baseline justify-between gap-2 mt-1">
        <div @class(['text-2xl sm:text-3xl font-extrabold text-[#111827] tracking-tight', 'font-mono tabular-nums' => $mono])>
            {{ $value }}
        </div>

        @if ($delta !== null && $delta !== '')
            <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $deltaClasses }}">
                {{ $delta }}
            </span>
        @endif
    </div>

    @if ($displayHint || trim($slot ?? '') !== '')
        <div class="text-xs font-medium text-[#6B7280] mt-1 leading-normal">
            {{ $displayHint ?? $slot }}
        </div>
    @endif
</div>
