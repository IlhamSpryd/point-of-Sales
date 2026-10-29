@props([
    'status' => null,
    'label' => null,
    'variant' => null,
])

@php
    $rawStatus = $status instanceof \BackedEnum ? $status->value : (string) $status;
    
    $displayLabel = $label
        ?? ($status instanceof \BackedEnum && method_exists($status, 'label')
            ? $status->label()
            : ($rawStatus ? ucwords(str_replace(['_', '-'], ' ', strtolower($rawStatus))) : ''));

    $semanticVariant = $variant ?? match (strtolower($rawStatus)) {
        'active', 'paid', 'completed', 'approved', 'posted', 'matched', 'received', 'open', 'ready', 'fulfilled', 'settled', 'success' => 'success',
        'pending', 'draft_approval', 'pending_approval', 'partially_paid', 'partially_received', 'partially_fulfilled', 'in_transit', 'counting', 'brewing', 'closing', 'handover_pending', 'on_hold', 'warning' => 'warning',
        'cancelled', 'void', 'rejected', 'overdue', 'failed', 'disputed', 'variance_flagged', 'blocked', 'suspended', 'expired', 'danger', 'destructive' => 'destructive',
        'issued', 'sent', 'confirmed', 'converted', 'dispatched', 'info' => 'info',
        default => 'secondary',
    };

    $colorClasses = match ($semanticVariant) {
        'success' => 'text-emerald-700 dark:text-emerald-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'destructive' => 'text-red-700 dark:text-red-400',
        'info' => 'text-blue-700 dark:text-blue-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
@endphp

<span {{ $attributes->merge(['class' => 'text-xs sm:text-sm font-medium ' . $colorClasses]) }}>
    {{ $slot->isNotEmpty() ? $slot : $displayLabel }}
</span>
