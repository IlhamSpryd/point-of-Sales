@props([
    'variant' => 'primary', // primary, secondary, danger
])

@php
    $baseClasses = 'inline-flex items-center justify-center gap-2 font-medium rounded-xl text-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95';

    $variants = [
        'primary' => 'bg-[#37352F] text-white hover:bg-black focus:ring-[#37352F] shadow-sm px-4 py-2',
        'secondary' => 'bg-white text-[#37352F] border border-[#E9E9E7] hover:bg-[#F7F7F5] focus:ring-[#37352F] px-4 py-2',
        'danger' => 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 focus:ring-rose-500 shadow-sm px-4 py-2',
    ];

    $classes = $baseClasses . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

<button {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</button>
