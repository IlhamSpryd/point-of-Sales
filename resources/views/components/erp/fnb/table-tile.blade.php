@props([
    'code' => '',
    'name' => null,
    'area' => 'Main Dining',
    'capacity' => 4,
    'status' => 'available',
    'occupiedSince' => null,
    'orderTotal' => null,
])

@php
    $statusKey = strtolower((string) $status);

    $statusConfig = match ($statusKey) {
        'occupied', 'terisi' => [
            'label' => 'Terisi',
            'dotClass' => 'bg-warning animate-pulse',
            'badgeClass' => 'bg-warning-muted text-warning border-warning-border',
            'cardClass' => 'border-warning-border bg-card hover:border-warning',
        ],
        'bill_printed', 'menunggu_bayar' => [
            'label' => 'Cetak Tagihan',
            'dotClass' => 'bg-info animate-pulse',
            'badgeClass' => 'bg-info-muted text-info border-info-border',
            'cardClass' => 'border-info-border bg-card hover:border-info',
        ],
        'dirty', 'kotor' => [
            'label' => 'Perlu Dibersihkan',
            'dotClass' => 'bg-destructive',
            'badgeClass' => 'bg-destructive-muted text-destructive border-destructive-border',
            'cardClass' => 'border-destructive-border bg-card hover:border-destructive',
        ],
        default => [
            'label' => 'Tersedia',
            'dotClass' => 'bg-success',
            'badgeClass' => 'bg-success-muted text-success border-success-border',
            'cardClass' => 'border-border bg-card hover:border-border',
        ],
    };
@endphp

<div {{ $attributes->twMerge('rounded-2xl border p-4 shadow-xs transition-all duration-200 select-none flex flex-col justify-between gap-3 ' . $statusConfig['cardClass']) }}>
    {{-- Header: Table Code & Status Badge --}}
    <div class="flex items-start justify-between gap-2">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xl font-black text-foreground tracking-tight font-mono">
                    {{ $code }}
                </span>
                <span class="text-[11px] font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-md flex items-center gap-1">
                    <span class="material-symbols-rounded text-[13px]">group</span>
                    <span>{{ $capacity }} Kursi</span>
                </span>
            </div>
            @if ($name)
                <p class="text-xs font-semibold text-foreground mt-0.5 truncate max-w-[150px]">
                    {{ $name }}
                </p>
            @endif
            <p class="text-[10px] text-muted-foreground mt-0.5 uppercase tracking-wider font-semibold">
                {{ $area }}
            </p>
        </div>

        {{-- Status Pill with Dot --}}
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border text-xs font-semibold {{ $statusConfig['badgeClass'] }}">
            <span class="w-1.5 h-1.5 rounded-full {{ $statusConfig['dotClass'] }}" aria-hidden="true"></span>
            <span>{{ $statusConfig['label'] }}</span>
        </span>
    </div>

    {{-- Body: Order total or occupied duration if active --}}
    @if ($orderTotal || $occupiedSince)
        <div class="pt-2 border-t border-border flex items-center justify-between text-xs font-medium">
            @if ($occupiedSince)
                <span class="text-muted-foreground flex items-center gap-1 text-[11px]">
                    <span class="material-symbols-rounded text-sm">schedule</span>
                    <span>{{ $occupiedSince }}</span>
                </span>
            @endif
            @if ($orderTotal)
                <span class="font-bold text-foreground numeric font-mono ml-auto">
                    {{ $orderTotal }}
                </span>
            @endif
        </div>
    @endif

    {{-- Optional Footer Slot for Actions --}}
    @if (isset($actions))
        <div class="pt-2 border-t border-border flex items-center justify-end gap-1.5">
            {{ $actions }}
        </div>
    @endif
</div>
