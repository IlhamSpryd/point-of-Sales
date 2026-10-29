@props([
    'variant' => null,
    'type' => null,
    'icon' => null,
    'title' => '',
    'description' => '',
    'dismissible' => false,
])

@php
    $resolvedVariant = match (strtolower((string) ($variant ?? $type ?? 'primary'))) {
        'danger', 'error', 'destructive' => 'destructive',
        'success' => 'success',
        'warning' => 'warning',
        'info' => 'info',
        'secondary' => 'secondary',
        default => 'primary',
    };

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'relative w-full rounded-xl border p-4 text-xs sm:text-sm flex items-start gap-3',
        'bg-card text-card-foreground border-border' => $resolvedVariant === 'primary',
        'bg-secondary text-secondary-foreground border-border' => $resolvedVariant === 'secondary',
        'bg-[#FFF1F2] bg-rose-50 text-[#BE123C] border-rose-200' => $resolvedVariant === 'destructive',
        'bg-[#EFF6FF] text-[#1D4ED8] border-blue-200' => $resolvedVariant === 'info',
        'bg-[#ECFDF5] bg-green-50 text-[#047857] border-emerald-200' => $resolvedVariant === 'success',
        'bg-[#FBF3E7] text-[#8A5A16] border-amber-200' => $resolvedVariant === 'warning',
    ]);

    $defaultIconNames = [
        'destructive' => 'error',
        'info' => 'info',
        'success' => 'check_circle',
        'warning' => 'warning',
        'primary' => 'info',
        'secondary' => 'info',
    ];
    $iconName = $icon ?? ($defaultIconNames[$resolvedVariant] ?? 'info');
@endphp

<div x-data="{ shown: true }" x-show="shown" role="alert" {{ $attributes->twMerge($classes) }}>
    @if (\Illuminate\Support\Facades\View::exists('components.'.str_replace('.', '/', $iconName)))
        <x-dynamic-component class="size-4 shrink-0 mt-0.5" :component="$iconName" />
    @else
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0 mt-0.5" aria-hidden="true">{{ $iconName }}</span>
    @endif

    <div class="flex-1 min-w-0">
        @if ($title)
            <h5 class="font-bold leading-snug tracking-tight">{{ $title }}</h5>
        @endif

        @if ($description)
            <div @class(['opacity-90 leading-relaxed', 'mt-1' => $title])>{!! $description !!}</div>
        @endif

        @if (trim($slot ?? '') !== '')
            <div @class(['leading-relaxed', 'mt-1' => $title])>{{ $slot }}</div>
        @endif
    </div>

    @if ($dismissible)
        <button
            type="button"
            @click="shown = false"
            aria-label="Tutup peringatan"
            class="inline-flex size-6 shrink-0 items-center justify-center rounded-md opacity-60 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
        >
            <span class="material-symbols-rounded text-base leading-none" aria-hidden="true">close</span>
        </button>
    @endif
</div>
