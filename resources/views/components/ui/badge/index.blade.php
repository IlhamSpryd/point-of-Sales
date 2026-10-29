@props([
    'variant' => null,
    'type' => null,
    'status' => null,
    'size' => 'sm',
    'icon' => null,
    'iconPosition' => 'before',
    'pill' => false,
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

    $dotColors = [
        'success'     => 'bg-emerald-500',
        'destructive' => 'bg-rose-500',
        'warning'     => 'bg-amber-500',
        'info'        => 'bg-blue-500',
        'secondary'   => 'bg-zinc-400',
        'primary'     => 'bg-zinc-400',
        'outline'     => 'bg-zinc-400',
    ];

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center justify-center whitespace-nowrap font-medium tracking-tight transition-colors border',
        $pill ? 'rounded-full' : 'rounded-md',
        'gap-1 px-2 py-0.5 text-[11px] leading-4' => $size === 'sm',
        'gap-1.5 px-2.5 py-0.5 text-xs' => $size === 'md',
        'gap-1.5 px-3 py-1 text-xs' => $size === 'lg',
        'bg-primary text-primary-foreground border-transparent' => $variant === 'primary',
        'bg-[#F1F1EF] text-[#55544E] border-[#E9E9E7]' => $variant === 'secondary',
        'bg-transparent border-border text-foreground' => $variant === 'outline',
        'bg-[#FFF1F2] bg-rose-50 text-[#BE123C] border-rose-200/70' => $variant === 'destructive',
        'bg-[#EFF6FF] text-[#1D4ED8] border-blue-200/70' => $variant === 'info',
        'bg-[#ECFDF5] bg-green-50 text-[#047857] text-green-600 border-emerald-200/70' => $variant === 'success',
        'bg-[#FBF3E7] text-[#8A5A16] border-amber-200/70' => $variant === 'warning',
    ]);
@endphp

<span {{ $attributes->twMerge($classes) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full shrink-0 {{ $dotColors[$variant] ?? 'bg-zinc-400' }}" aria-hidden="true"></span>
    @endif

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
