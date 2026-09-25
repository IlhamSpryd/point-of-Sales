@props([
    'variant' => 'primary', // primary, secondary, danger, ghost
    'size' => 'md',         // sm, md, lg
    'hotkey' => null,
])

@php
    $baseClasses = 'inline-flex items-center justify-center gap-2 font-semibold rounded-xl transition-all duration-200 ease-[cubic-bezier(0.4,0,0.2,1)] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95';

    $sizes = [
        'sm' => 'text-xs px-3 py-1.5',
        'md' => 'text-sm px-4 py-2.5',
        'lg' => 'text-sm px-6 py-3',
    ];

    $variants = [
        'primary'   => 'bg-primary-700 text-white hover:bg-primary-900 focus-visible:ring-primary-700 shadow-sm hover:shadow-md',
        'secondary' => 'bg-white text-primary-700 border border-yovel-border hover:bg-primary-50 focus-visible:ring-primary-700 shadow-sm',
        'danger'    => 'bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 focus-visible:ring-rose-500 shadow-sm',
        'ghost'     => 'bg-transparent text-primary-500 hover:bg-primary-50 hover:text-primary-700 focus-visible:ring-primary-700',
    ];

    $classes = $baseClasses . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

<button {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
    @if($hotkey)
        <kbd class="ml-auto inline-flex items-center justify-center min-w-[24px] h-6 px-1.5 rounded bg-yovel-ink/10 text-[10px] font-bold tracking-widest text-inherit border border-yovel-ink/5 uppercase shadow-sm whitespace-nowrap">{{ $hotkey }}</kbd>
    @endif
</button>
