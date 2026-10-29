@props([
    'variant' => null,
    'type' => null,
    'status' => null,
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
    'asBadge' => false,
    'dot' => false,
])

@php
    $variant = $variant ?? $type;

    if ($status instanceof \BackedEnum) {
        if ($variant === null && method_exists($status, 'badgeType')) {
            $variant = $status->badgeType();
        }
    } elseif (is_string($status) && $variant === null) {
        $variant = $status;
    }

    $variant = match (strtolower((string) $variant)) {
        'danger', 'error', 'cancelled', 'void', 'rejected', 'overdue', 'destructive' => 'destructive',
        'paid', 'completed', 'active', 'approved', 'posted', 'matched', 'success' => 'success',
        'pending', 'draft_approval', 'partially_paid', 'partially_received', 'warning' => 'warning',
        'issued', 'sent', 'confirmed', 'info' => 'info',
        'neutral', 'draft', 'inactive', 'archived', 'secondary' => 'secondary',
        'primary' => 'primary',
        'outline' => 'outline',
        default => 'secondary',
    };

    $isBadge = $pill || $asBadge;

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center whitespace-nowrap font-medium tracking-normal transition-colors',
        
        // Explicit pill/chip tag style (when requested asBadge or pill)
        'border rounded-md px-2 py-0.5 text-xs' => $isBadge,
        'bg-emerald-50 text-emerald-800 border-emerald-200/80' => $isBadge && $variant === 'success',
        'bg-rose-50 text-rose-800 border-rose-200/80' => $isBadge && $variant === 'destructive',
        'bg-amber-50 text-amber-800 border-amber-200/80' => $isBadge && $variant === 'warning',
        'bg-blue-50 text-blue-800 border-blue-200/80' => $isBadge && $variant === 'info',
        'bg-zinc-100 text-zinc-700 border-zinc-200' => $isBadge && $variant === 'secondary',
        'bg-zinc-900 text-white border-zinc-900' => $isBadge && $variant === 'primary',
        'bg-transparent text-zinc-700 border-zinc-300' => $isBadge && $variant === 'outline',

        // Standard Table & Status Display: ABSOLUTELY NO COLORED PILL BADGES
        'bg-transparent border-0 p-0 text-xs sm:text-sm' => ! $isBadge,
        'text-emerald-700 dark:text-emerald-400' => ! $isBadge && $variant === 'success',
        'text-red-700 dark:text-red-400' => ! $isBadge && $variant === 'destructive',
        'text-amber-700 dark:text-amber-400' => ! $isBadge && $variant === 'warning',
        'text-blue-700 dark:text-blue-400' => ! $isBadge && $variant === 'info',
        'text-gray-500 dark:text-gray-400' => ! $isBadge && $variant === 'secondary',
        'text-gray-900 dark:text-gray-100' => ! $isBadge && $variant === 'primary',
        'text-gray-600 dark:text-gray-400' => ! $isBadge && $variant === 'outline',
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if ($icon && $iconPosition === 'before')
        @if (\Illuminate\Support\Facades\View::exists('components.'.str_replace('.', '/', $icon)))
            <x-dynamic-component :component="$icon" class="size-3.5 shrink-0" />
        @else
            <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
    @endif

    {{ $slot->isNotEmpty() ? $slot : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : '') }}

    @if ($icon && $iconPosition === 'after')
        @if (\Illuminate\Support\Facades\View::exists('components.'.str_replace('.', '/', $icon)))
            <x-dynamic-component :component="$icon" class="size-3.5 shrink-0" />
        @else
            <span class="material-symbols-rounded text-[14px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
    @endif
</span>
