@props([
    'event' => 'order.paid',
    'url' => '',
    'status' => 200,
    'durationMs' => 0,
    'timestamp' => 'Baru saja',
])

@php
    $code = (int) $status;
    $isSuccess = $code >= 200 && $code < 300;
    $statusClass = match (true) {
        $isSuccess => 'bg-success-muted text-success border-success-border',
        $code >= 500 => 'bg-destructive-muted text-destructive border-destructive-border',
        default => 'bg-warning-muted text-warning border-warning-border',
    };
@endphp

<div {{ $attributes->twMerge('rounded-xl border border-border bg-card p-4 shadow-xs select-none space-y-2.5 transition-all hover:border-border') }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold font-sans uppercase bg-secondary text-primary border border-border">
                {{ $event }}
            </span>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-bold font-sans {{ $statusClass }}">
                <span>{{ $status }}</span>
                <span>{{ $isSuccess ? 'OK' : 'Error' }}</span>
            </span>
        </div>

        <div class="flex items-center gap-2 text-xs text-muted-foreground">
            <span class="numeric font-sans text-[11px]">{{ $durationMs }} ms</span>
            <span>&bull;</span>
            <span class="text-[11px]">{{ $timestamp }}</span>
        </div>
    </div>

    <div class="bg-muted px-3 py-2 rounded-lg border border-border font-sans text-xs text-foreground truncate">
        {{ $url }}
    </div>

    <div class="flex items-center justify-between text-xs pt-1">
        <span class="text-[11px] text-muted-foreground">HMAC-SHA256 Signature Verified</span>
        @if (isset($action))
            {{ $action }}
        @else
            <button type="button"
                class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer">
                <span class="material-symbols-rounded text-sm">replay</span>
                <span>Kirim Ulang</span>
            </button>
        @endif
    </div>
</div>
