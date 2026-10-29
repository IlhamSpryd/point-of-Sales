@props([
    'type' => 'button',
    'size' => 'md',
    'variant' => 'primary',
    'href' => null,
    'icon' => null,
    'hotkey' => null,
    'loader' => false,
    'loading' => false,
    'topHighlight' => false,
])

@php
    switch ($size ?? 'md') {
        case 'xs':
            $sizeClasses = 'h-7 px-2.5 text-xs rounded-md gap-1';
            $loaderClasses = 'size-3';
            break;
        case 'sm':
            $sizeClasses = 'h-8 px-3 text-xs rounded-lg gap-1.5';
            $loaderClasses = 'size-3.5';
            break;
        case 'md':
            $sizeClasses = 'h-9 px-3.5 text-xs sm:text-sm rounded-lg gap-1.5';
            $loaderClasses = 'size-3.5';
            break;
        case 'lg':
            $sizeClasses = 'h-10 px-5 text-sm rounded-lg gap-2';
            $loaderClasses = 'size-4';
            break;
        case 'icon':
            $sizeClasses = 'size-9 p-0 rounded-lg';
            $loaderClasses = 'size-4';
            break;
        case 'icon-sm':
            $sizeClasses = 'size-8 p-0 rounded-md';
            $loaderClasses = 'size-3.5';
            break;
        default:
            $sizeClasses = 'h-9 px-3.5 text-xs sm:text-sm rounded-lg gap-1.5';
            $loaderClasses = 'size-3.5';
            break;
    }

    switch ($variant ?? 'primary') {
        case 'primary':
            $typeClasses = 'border-transparent no-underline bg-[#171717] text-white hover:bg-[#262626] focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-1';
            break;
        case 'secondary':
            $typeClasses = 'border-border no-underline text-secondary-foreground bg-white hover:bg-secondary focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-1';
            break;
        case 'destructive':
        case 'danger':
            $typeClasses = 'border-transparent no-underline bg-rose-600 text-white hover:bg-rose-700 focus-visible:ring-2 focus-visible:ring-rose-500/40 focus-visible:ring-offset-1';
            break;
        case 'outline':
            $typeClasses = 'no-underline text-foreground bg-background hover:bg-secondary border-border focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-1';
            break;
        case 'ghost':
            $typeClasses = 'border-transparent no-underline text-foreground hover:bg-secondary focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-1';
            break;
        case 'link':
            $typeClasses = 'border-transparent no-underline text-foreground hover:underline px-0 h-auto';
            break;
        default:
            $typeClasses = 'border-transparent no-underline bg-[#171717] text-white hover:bg-[#262626] focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-1';
            break;
    }

    $tag = ($href !== null && $type !== 'submit') ? 'a' : 'button';
    $wireTarget = $attributes->get('wire:target') ?? $attributes->get('wire:click');
@endphp

@if ($tag === 'a')
    <a href="{{ $href }}"
        {{ $attributes->twMerge($sizeClasses.' '.$typeClasses.' relative cursor-pointer border inline-flex items-center justify-center font-semibold whitespace-nowrap select-none transition-all duration-150 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60') }}
    >
        @if ($icon)
            <span class="material-symbols-rounded text-[18px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
        {{ $slot }}
        @if ($hotkey)
            <x-ui.kbd class="ml-1 hidden sm:inline-flex">{{ $hotkey }}</x-ui.kbd>
        @endif
    </a>
@else
    <button
        type="{{ $type ?? 'button' }}"
        {{ $attributes->twMerge($sizeClasses.' '.$typeClasses.' relative cursor-pointer border inline-flex items-center justify-center font-semibold whitespace-nowrap select-none transition-all duration-150 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60') }}
    >
        @if ($loading ?? false)
            <span class="absolute flex h-full w-full items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="{{ $loaderClasses }} animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </span>
        @endif
        @if ($loader && $wireTarget)
            <span class="absolute flex h-full w-full items-center justify-center" wire:loading.flex wire:target="{{ $wireTarget }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="{{ $loaderClasses }} animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </span>
        @endif
        <span @class(['inline-flex items-center justify-center gap-1.5', 'opacity-0' => $loading]) @if($loader && $wireTarget) wire:loading.class="opacity-0" wire:target="{{ $wireTarget }}" @endif>
            @if ($icon)
                <span class="material-symbols-rounded text-[18px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
            @endif
            {{ $slot }}
            @if ($hotkey)
                <x-ui.kbd class="ml-1 hidden sm:inline-flex">{{ $hotkey }}</x-ui.kbd>
            @endif
        </span>
    </button>
@endif
