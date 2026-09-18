@props([
    'type' => 'success', // success, danger, warning, info, secondary
])

@php
    $variants = [
        'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'danger' => 'bg-rose-50 text-rose-700 border-rose-200',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
        'info' => 'bg-blue-50 text-blue-700 border-blue-200',
        'secondary' => 'bg-[#F1F1EF] text-[#787774] border-[#E9E9E7]',
    ];

    $classes = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold tracking-wide uppercase border " . ($variants[$type] ?? $variants['secondary']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
