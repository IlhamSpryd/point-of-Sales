@props([
    'id' => 'sidebar' . uniqid(),
    'text',
    'icon' => null,
    'active' => false,
    'open' => false,
    'size' => 'sm'
])

@php
    $sizes = [
        'sm' => 'px-2.5 py-1.5 data-state-collapsed-icon:p-2',
        'md' => 'px-3 py-2 data-state-collapsed-icon:p-2',
        'lg' => 'px-3.5 py-2.5 data-state-collapsed-icon:p-2'
    ];
    $padding = $sizes[$size] ?? $sizes['md'];
@endphp

<div x-data="{ {{ $id }}: {{ $open ? 'true' : 'false' }} }"
    :class="{ 'rounded-medium ease-out duration-300' : {{ $id }} == true }"
    class="relative w-full select-none">
    <div
        @click="{{ $id }}=!{{ $id }};"
        class="@if($active){{ 'text-sidebar-accent-foreground bg-sidebar-accent' }}@endif ease-linear hover:bg-sidebar-accent hover:text-sidebar-accent-foreground duration-50 transition-colors flex rounded-medium w-full h-auto {{ $padding }} cursor-pointer text-sm font-normal justify-start items-center overflow-hidden group-hover:autoflow-auto items"
        
    >
        <div class="flex relative items-center w-full h-auto">
            @if($icon)
                <x-dynamic-component :component="$icon" class="flex-shrink-0 mr-2 size-4" />
            @endif
            <div class="w-full flex items-center justify-center data-state-collapsed-icon:hidden">
                <span class="mr-0">{{ $text }}</span>
                <span :class="{ 'rotate-90' : {{ $id }} == true }" class="mr-0.5 ml-auto size-3.5 duration-300 ease-out">
                    <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"></path></svg>
                </span>
            </div>
        </div>

        <template x-teleport="#{{ $id }}">
            <div x-show="{{ $id }}" class="relative pl-6 pt-px space-y-1"  x-collapse x-cloak>
                <span class="absolute left-0 top-0 w-px ml-4 h-full bg-sidebar-accent-foreground/[8%]"></span>
                {{ $slot }}
            </div>
        </template>
    </div>

    <div id="{{ $id }}"></div>
</div>
