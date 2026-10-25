@aware([
    'side' => 'left',
    'variant' => 'sidebar',
    'collapsible' => 'offcanvas'
])

@props([
    'href' => '#',
    'icon' => null,
    'active' => false,
    'class' => '',
    'size' => 'sm',
    'emoji' => null
])

@php
    $sizes = [
        'sm' => 'px-2.5 py-1.5 data-state-collapsed-icon:p-2',
        'md' => 'px-3 py-2 data-state-collapsed-icon:p-2',
        'lg' => 'px-3.5 py-2.5 data-state-collapsed-icon:p-2'
    ];
    $padding = $sizes[$size] ?? $sizes['md'];
@endphp

<a 
    href="{{ $href }}"
    {{ $attributes->merge([
        'class' => 'group flex items-center rounded-medium ' . $padding . ' text-sm font-normal hover:bg-sidebar-accent hover:text-sidebar-accent-foreground' .
        ($active ? 'bg-sidebar-accent text-sidebar-accent-foreground' : 'transparent') . ' ' . 
        $class
    ]) }}
>
    @if($emoji)
        <span class="mr-2 size-4 flex-shrink-0">{{ $emoji }}</span>
    @endif
    @if($icon)
        <x-dynamic-component 
            :component="$icon" 
            class="mr-2 size-4 flex-shrink-0" 
        />
    @endif
    <span class="data-state-collapsed-icon:overflow-hidden data-state-collapsed-icon:opacity-0 w-auto transition-[opacity] z-20 ease-out duration-300">{{ $slot }}</span>
</a>
