@props([
    'status' => null,
    'label' => null,
    'size' => 'sm',
    'dot' => true,
])

@php
    $rawStatus = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $displayLabel = $label
        ?? ($status instanceof \BackedEnum && method_exists($status, 'label')
            ? $status->label()
            : ucwords(str_replace(['_', '-'], ' ', strtolower($rawStatus ?: 'unknown'))));

    $variant = match (strtolower($rawStatus)) {
        'active', 'paid', 'completed', 'approved', 'posted', 'matched', 'received', 'open', 'ready', 'fulfilled', 'settled' => 'success',
        'pending', 'draft_approval', 'pending_approval', 'partially_paid', 'partially_received', 'partially_fulfilled', 'in_transit', 'counting', 'brewing', 'closing', 'handover_pending', 'on_hold' => 'warning',
        'issued', 'sent', 'confirmed', ' converted', 'converted', 'dispatched' => 'info',
        'cancelled', 'void', 'rejected', 'overdue', 'failed', 'disputed', 'variance_flagged', 'blocked', 'suspended', 'expired' => 'destructive',
        default => 'secondary',
    };
@endphp

<x-ui.badge :variant="$variant" :size="$size" :dot="$dot" {{ $attributes }}>
    {{ $slot->isNotEmpty() ? $slot : $displayLabel }}
</x-ui.badge>
