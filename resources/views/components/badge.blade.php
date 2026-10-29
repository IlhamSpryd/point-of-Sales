@props([
    'type' => null,
    'variant' => null,
    'status' => null,
    'dot' => false,
    'asBadge' => false,
    'pill' => false,
])

@php
    $type = $type ?? $variant;

    if ($status instanceof \BackedEnum) {
        if ($type === null && method_exists($status, 'badgeType')) {
            $type = $status->badgeType();
        }
    }

    $type = $type ?? 'secondary';

    $isBadge = $asBadge || $pill;

    $textColors = [
        'success'   => 'text-emerald-700 dark:text-emerald-400',
        'paid'      => 'text-emerald-700 dark:text-emerald-400',
        'completed' => 'text-emerald-700 dark:text-emerald-400',
        'danger'    => 'text-red-700 dark:text-red-400',
        'cancelled' => 'text-red-700 dark:text-red-400',
        'void'      => 'text-red-700 dark:text-red-400',
        'warning'   => 'text-amber-700 dark:text-amber-400',
        'pending'   => 'text-amber-700 dark:text-amber-400',
        'info'      => 'text-blue-700 dark:text-blue-400',
        'secondary' => 'text-gray-500 dark:text-gray-400',
        'neutral'   => 'text-gray-500 dark:text-gray-400',
    ];

    $badgeVariants = [
        'success'   => 'bg-emerald-50 text-emerald-800 border-emerald-200/80',
        'paid'      => 'bg-emerald-50 text-emerald-800 border-emerald-200/80',
        'completed' => 'bg-emerald-50 text-emerald-800 border-emerald-200/80',
        'danger'    => 'bg-rose-50 text-rose-800 border-rose-200/80',
        'cancelled' => 'bg-rose-50 text-rose-800 border-rose-200/80',
        'void'      => 'bg-rose-50 text-rose-800 border-rose-200/80',
        'warning'   => 'bg-amber-50 text-amber-800 border-amber-200/80',
        'pending'   => 'bg-amber-50 text-amber-800 border-amber-200/80',
        'info'      => 'bg-blue-50 text-blue-800 border-blue-200/80',
        'secondary' => 'bg-zinc-100 text-zinc-700 border-zinc-200',
        'neutral'   => 'bg-zinc-100 text-zinc-700 border-zinc-200',
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

    if ($isBadge) {
        $classes = "inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium tracking-normal border " . ($badgeVariants[$type] ?? $badgeVariants['secondary']);
    } else {
        $classes = "inline-flex items-center gap-1.5 text-xs sm:text-sm font-medium tracking-normal " . ($textColors[$type] ?? $textColors['secondary']);
    }
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    @if($dot)
        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $dotColors[$type] ?? $dotColors['secondary'] }}" aria-hidden="true"></span>
    @endif
    {{ $slot->isNotEmpty() ? $slot : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : '') }}
</span>
