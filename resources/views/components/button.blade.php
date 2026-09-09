@props([
    'variant' => 'primary', // primary, secondary, danger
])

@php
    $baseClasses = 'inline-flex items-center justify-center gap-2 font-medium rounded-lg text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

    $variants = [
        'primary' => 'bg-zinc-900 text-white hover:bg-zinc-800 focus:ring-zinc-900 shadow-sm px-4 py-2',
        'secondary' => 'bg-white text-zinc-700 border border-zinc-200 hover:bg-zinc-50 focus:ring-zinc-200 px-4 py-2',
        'danger' => 'bg-red-50 text-red-700 hover:bg-red-100 border border-red-200 focus:ring-red-500 shadow-sm px-4 py-2',
    ];

    $classes = $baseClasses . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

<button {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</button>
