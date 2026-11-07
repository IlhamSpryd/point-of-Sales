@props([
    'label' => '',
    'used' => 0,
    'limit' => 0,
    'unit' => 'Unit',
    'warningThreshold' => 80,
])

@php
    $usedCount = (int) $used;
    $limitCount = (int) $limit;
    $percent = $limitCount > 0 ? min(100, round(($usedCount / $limitCount) * 100)) : 0;

    $color = match (true) {
        $percent >= 100 => 'bg-destructive',
        $percent >= $warningThreshold => 'bg-warning',
        default => 'bg-success',
    };
@endphp

<div {{ $attributes->twMerge('space-y-1.5') }}>
    <div class="flex items-center justify-between text-xs font-semibold">
        <span class="text-foreground">{{ $label }}</span>
        <span class="text-muted-foreground numeric font-sans">
            <strong class="text-foreground">{{ $usedCount }}</strong> / {{ $limitCount }} {{ $unit }}
            <span class="text-[11px] font-bold ml-1">({{ $percent }}%)</span>
        </span>
    </div>

    {{-- TailAdmin Linear Meter Progress Bar --}}
    <div class="w-full h-2 rounded-full bg-muted overflow-hidden border border-border">
        <div class="h-full rounded-full transition-all duration-300 {{ $color }}"
            style="width: {{ $percent }}%"></div>
    </div>
</div>
