@props([
    'systemStock' => 0,
    'physicalStock' => 0,
    'unit' => 'Pcs',
    'showBreakdown' => false,
])

@php
    $sys = (float) $systemStock;
    $phy = (float) $physicalStock;
    $diff = $phy - $sys;
    $isSurplus = $diff > 0.0001;
    $isShortage = $diff < -0.0001;
    $isMatch = !$isSurplus && !$isShortage;

    $badgeClasses = match (true) {
        $isSurplus => 'bg-success-muted text-success border-success-border',
        $isShortage => 'bg-destructive-muted text-destructive border-destructive-border',
        default => 'bg-muted text-muted-foreground border-border',
    };
@endphp

<div {{ $attributes->twMerge('inline-flex flex-col items-start gap-1') }}>
    {{-- Main Variance Pill --}}
    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full border text-xs font-bold numeric font-mono {{ $badgeClasses }}">
        @if ($isSurplus)
            <span class="material-symbols-rounded text-xs" aria-hidden="true">trending_up</span>
            <span>+{{ number_format($diff, 2) }} {{ $unit }}</span>
        @elseif ($isShortage)
            <span class="material-symbols-rounded text-xs" aria-hidden="true">trending_down</span>
            <span>{{ number_format($diff, 2) }} {{ $unit }}</span>
        @else
            <span class="material-symbols-rounded text-xs" aria-hidden="true">check</span>
            <span>0 {{ $unit }}</span>
        @endif
    </span>

    @if ($showBreakdown)
        <span class="text-[10px] text-muted-foreground flex items-center gap-1.5 numeric font-mono">
            <span>Sistem: {{ number_format($sys, 2) }}</span>
            <span class="text-border">&bull;</span>
            <span>Fisik: {{ number_format($phy, 2) }}</span>
        </span>
    @endif
</div>
