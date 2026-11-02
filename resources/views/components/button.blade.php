@props([
    'variant' => 'primary', // primary, secondary, danger, ghost
    'size' => 'md',         // sm, md, lg
    'hotkey' => null,
    'disabled' => false,
])

@php
    $baseClasses = 'inline-flex items-center justify-center gap-2 font-medium rounded-lg transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed active:opacity-90';

    $sizes = [
        'sm' => 'text-xs px-3 py-1.5',
        'md' => 'text-sm px-4 py-2',
        'lg' => 'text-sm px-5 py-2.5',
    ];

    $variants = [
        'primary'   => 'bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring',
        'secondary' => 'bg-card text-foreground border border-border hover:bg-muted focus-visible:ring-ring',
        'danger'    => 'bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive',
        'ghost'     => 'bg-transparent text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-ring',
    ];

    $classes = $baseClasses . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

<button @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
    @if($hotkey)
        <kbd class="ml-auto inline-flex items-center justify-center min-w-[24px] h-6 px-1.5 rounded bg-apeiron-ink/10 text-[10px] font-bold tracking-widest text-inherit border border-apeiron-ink/5 uppercase shadow-sm whitespace-nowrap">{{ $hotkey }}</kbd>
    @endif
</button>
