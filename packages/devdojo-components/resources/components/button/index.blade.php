@props([
    'type' => 'button',
    'size' => 'md',
    'variant' => 'primary',
    'href' => null,
    'loader' => false,
    'loading' => false,
    'topHighlight' => true,
])

@php
    switch ($size ?? 'md') {
        case 'xs':
            $sizeClasses = 'h-7 px-2.5 text-[11px] rounded-small gap-1';
            $loaderClasses = 'size-3';
            break;
        case 'sm':
            $sizeClasses = 'h-[30px] px-2.5 text-[13px] rounded-small gap-1.5';
            $loaderClasses = 'size-3';
            break;
        case 'md':
            $sizeClasses = 'h-9 px-3.5 text-sm rounded-medium gap-2';
            $loaderClasses = 'size-3.5';
            break;
        case 'lg':
            $sizeClasses = 'h-11 px-5 text-[15px] rounded-large gap-2';
            $loaderClasses = 'size-4';
            break;
        case 'xl':
            $sizeClasses = 'px-5 py-2.5 leading-6 text-base rounded-medium';
            $loaderClasses = 'size-4';
            break;
        case '2xl':
            $sizeClasses = 'px-6 py-3 leading-6 text-base rounded-medium';
            $loaderClasses = 'size-5';
            break;
        case '3xl':
            $sizeClasses = 'px-6 py-3.5 leading-7 text-lg rounded-large';
            $loaderClasses = 'size-5';
            break;
        default:
            $sizeClasses = 'h-9 px-3.5 text-sm rounded-medium gap-2';
            $loaderClasses = 'size-3.5';
            break;
    }
@endphp

@php
    $topHighlightClasses = $topHighlight ? ' shadow-[inset_0_1px_1px_0_rgba(255,255,255,0.3),inset_0_-1px_1px_0_rgba(0,0,0,0.3)] dark:shadow-[inset_0_1px_1px_0_rgba(255,255,255,0.3),inset_0_-1px_1px_0_rgba(255,255,255,0.15)]' : '';

    $primaryClasses = 'border-transparent no-underline bg-linear-to-b from-primary/90 via-primary/90 to-primary text-primary-foreground select-none hover:from-primary hover:via-primary hover:to-primary focus-visible:ring-2 focus-visible:ring-primary/10 dark:focus-visible:ring-primary/20 focus-visible:ring-offset-2 focus-visible:ring-offset-background' . $topHighlightClasses;

    switch ($variant ?? 'primary') {
        case 'primary':
            $typeClasses = $primaryClasses;
            break;
        case 'secondary':
            $typeClasses = 'border-transparent no-underline text-secondary-foreground bg-secondary hover:bg-secondary/80 focus-visible:ring-2 focus-visible:ring-secondary/90 focus-visible:ring-offset-2 focus-visible:ring-offset-background';
            break;
        case 'destructive':
            $typeClasses = 'border-transparent no-underline bg-destructive focus-visible:ring-2 focus-visible:ring-destructive/30 focus-visible:ring-offset-2 focus-visible:ring-offset-background text-white hover:opacity-95' . $topHighlightClasses;
            break;
        case 'outline':
            $typeClasses = 'no-underline text-foreground bg-background hover:bg-secondary border-foreground/10 dark:border-foreground/15 focus-visible:ring-2 focus-visible:ring-secondary/90 focus-visible:ring-offset-2 focus-visible:ring-offset-background';
            break;
        case 'ghost':
            $typeClasses = 'border-transparent no-underline text-foreground hover:bg-secondary focus-visible:ring-2 focus-visible:ring-secondary/90 focus-visible:ring-offset-2 focus-visible:ring-offset-background';
            break;
        case 'link':
            $typeClasses = 'border-transparent no-underline text-foreground hover:underline';
            break;
        default:
            $typeClasses = $primaryClasses;
            break;
    }
@endphp

@php
    // An href makes this a link — render a real anchor unless the caller
    // explicitly asked for a submit button.
    if ($href !== null && $type !== 'submit') {
        $type = 'a';
    }

    switch ($type ?? 'button') {
        case 'button':
            $typeAttr = 'button type="button"';
            $typeClose = 'button';
            break;
        case 'submit':
            $typeAttr = 'button type="submit"';
            $typeClose = 'button';
            break;
        case 'a':
            $link = $href ?? '';
            $typeAttr = 'a href="' . $link . '"';
            $typeClose = 'a';
            break;
        default:
            $typeAttr = 'button type="button"';
            $typeClose = 'button';
            break;
    }
@endphp

@php
    $wireTarget = is_null($attributes->get('wire:target')) ? $attributes->get('wire:click') : $attributes->get('wire:target');
@endphp

<{!! $typeAttr !!} {{ $attributes->twMerge($sizeClasses . ' ' . $typeClasses . ' relative cursor-pointer border inline-flex items-center justify-center font-medium whitespace-nowrap select-none transition-all duration-150 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60') }}>
    @if ($loading ?? false)
        <span class="absolute flex h-full w-full items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="{{ $loaderClasses }} animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg></span>
    @endif
    @if ($loader ?? false)
        <span class="absolute flex h-full w-full items-center justify-center" wire:loading.flex wire:target="{{ $wireTarget }}"><svg xmlns="http://www.w3.org/2000/svg" class="{{ $loaderClasses }} animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </span>
    @endif
    <span @class(['inline-flex items-center gap-x-2', 'opacity-0' => $loading]) @if ($loader ?? false) wire:loading.class="opacity-0" wire:target="{{ $wireTarget }}" @endif>{{ $slot }}</span>
</{{ $typeClose }}>
