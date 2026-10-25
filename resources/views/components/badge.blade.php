@props([
    'type' => null,
    'variant' => null,
    'status' => null,
    'dot' => true,
])

@php
    $type = $type ?? $variant;

    if ($status instanceof \BackedEnum) {
        if ($type === null && method_exists($status, 'badgeType')) {
            $type = $status->badgeType();
        }
    }

    $type = $type ?? 'secondary';

    $variants = [
        'success'   => 'bg-success-50 text-success-700 border-success-600/20',
        'paid'      => 'bg-success-50 text-success-700 border-success-600/20',
        'completed' => 'bg-success-50 text-success-700 border-success-600/20',
        'danger'    => 'bg-danger-50 text-danger-700 border-danger-600/20',
        'cancelled' => 'bg-danger-50 text-danger-700 border-danger-600/20',
        'void'      => 'bg-danger-50 text-danger-700 border-danger-600/20',
        'warning'   => 'bg-warning-50 text-warning-600 border-warning-600/20',
        'pending'   => 'bg-warning-50 text-warning-600 border-warning-600/20',
        'info'      => 'bg-info-50 text-info-600 border-info-600/20',
        'secondary' => 'bg-primary-100 text-primary-500 border-primary-200',
        'neutral'   => 'bg-[#F7F7F5] text-zinc-700 border-[#E9E9E7]',
    ];

    $dotColors = [
        'success'   => 'bg-emerald-500',
        'paid'      => 'bg-emerald-500',
        'completed' => 'bg-emerald-500',
        'danger'    => 'bg-rose-500',
        'cancelled' => 'bg-rose-500',
        'void'      => 'bg-rose-500',
        'warning'   => 'bg-amber-500',
        'pending'   => 'bg-amber-500',
        'info'      => 'bg-sky-500',
        'secondary' => 'bg-zinc-400',
        'neutral'   => 'bg-zinc-400',
    ];

    $classes = "inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium tracking-normal border " . ($variants[$type] ?? $variants['secondary']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    @if($dot)
        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $dotColors[$type] ?? $dotColors['secondary'] }}" aria-hidden="true"></span>
    @endif
    {{ $slot->isNotEmpty() ? $slot : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : '') }}
</span>
