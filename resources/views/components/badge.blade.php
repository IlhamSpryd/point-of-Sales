@props([
    'type' => 'success', // success, danger, warning, info, secondary
])

@php
    $variants = [
        'success' => 'bg-emerald-50 text-emerald-700 border-emerald-100/50',
        'danger' => 'bg-rose-50 text-rose-700 border-rose-100/50',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-100/50',
        'info' => 'bg-blue-50 text-blue-700 border-blue-100/50',
        'secondary' => 'bg-zinc-50 text-zinc-600 border-zinc-200',
    ];

    $classes = "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold tracking-wide uppercase border " . ($variants[$type] ?? $variants['secondary']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
