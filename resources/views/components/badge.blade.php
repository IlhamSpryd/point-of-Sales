@props([
    'type' => 'success', // success, danger, warning, info, secondary
])

@php
    $variants = [
        'success' => 'bg-success-50 text-success-700 border-success-600/20',
        'danger'  => 'bg-danger-50 text-danger-700 border-danger-600/20',
        'warning' => 'bg-warning-50 text-warning-600 border-warning-600/20',
        'info'    => 'bg-info-50 text-info-600 border-info-600/20',
        'secondary' => 'bg-primary-100 text-primary-500 border-primary-200',
    ];

    $classes = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold tracking-wide uppercase border " . ($variants[$type] ?? $variants['secondary']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
