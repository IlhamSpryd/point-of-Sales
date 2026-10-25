@props([
    'position' => 'top',
    'arrow' => true,
    'content' => '',
    'delay' => 100,
])

@php
    switch ($position ?? 'top') {
        case 'bottom':
            $panelClasses = 'top-full left-1/2 -translate-x-1/2 mt-2';
            $originClass = 'origin-top';
            $arrowClasses = '-top-1 left-1/2 -translate-x-1/2';
            break;
        case 'left':
            $panelClasses = 'right-full top-1/2 -translate-y-1/2 mr-2';
            $originClass = 'origin-right';
            $arrowClasses = '-right-1 top-1/2 -translate-y-1/2';
            break;
        case 'right':
            $panelClasses = 'left-full top-1/2 -translate-y-1/2 ml-2';
            $originClass = 'origin-left';
            $arrowClasses = '-left-1 top-1/2 -translate-y-1/2';
            break;
        case 'top':
        default:
            $panelClasses = 'bottom-full left-1/2 -translate-x-1/2 mb-2';
            $originClass = 'origin-bottom';
            $arrowClasses = '-bottom-1 left-1/2 -translate-x-1/2';
            break;
    }
@endphp

<div {{ $attributes->twMerge('relative inline-flex') }}
    x-data="{
        show: false,
        timeout: null,
        open() { clearTimeout(this.timeout); this.timeout = setTimeout(() => this.show = true, {{ (int) $delay }}); },
        close() { clearTimeout(this.timeout); this.show = false; },
    }"
    x-on:mouseenter="open()"
    x-on:mouseleave="close()"
    x-on:focusin="open()"
    x-on:focusout="close()">
    {{ $slot }}

    <div x-show="show"
        x-cloak
        role="tooltip"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="{{ $panelClasses }} {{ $originClass }} absolute z-50 w-max max-w-xs">
        <div class="relative rounded-small bg-foreground px-2.5 py-1.5 text-xs font-medium text-background shadow-md">
            @isset($tip)
                {{ $tip }}
            @else
                {{ $content }}
            @endisset
            @if ($arrow)
                <span class="{{ $arrowClasses }} absolute h-2 w-2 rotate-45 bg-foreground"></span>
            @endif
        </div>
    </div>
</div>
