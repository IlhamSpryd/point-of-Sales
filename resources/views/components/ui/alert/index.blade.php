@props([
    'variant' => 'primary',
    'icon' => null,
    'title' => '',
    'description' => '',
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'relative w-full max-w-xl rounded-medium border p-4 text-sm [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:size-4 [&>svg+*]:translate-y-[-3px] [&:has(svg)]:pl-11',
        'bg-card text-card-foreground border-foreground/10 dark:border-foreground/15' => $variant === 'primary',
        'bg-secondary text-secondary-foreground border-foreground/10 dark:border-foreground/15' => $variant === 'secondary',
        'bg-rose-50 text-rose-600 border-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:border-rose-500/30' => $variant === 'destructive',
        'bg-blue-50 text-blue-600 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/30' => $variant === 'info',
        'bg-green-50 text-green-600 border-green-200 dark:bg-green-500/10 dark:text-green-300 dark:border-green-500/30' => $variant === 'success',
        'bg-amber-50 text-amber-700 border-amber-300 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/30' => $variant === 'warning',
    ]);

    $defaultIcons = [
        'destructive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
        'info' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
        'success' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
        'warning' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
    ];
@endphp

<div role="alert" {{ $attributes->twMerge($classes) }}>
    @if ($icon)
        <x-dynamic-component class="size-4" :component="$icon" />
    @elseif (isset($defaultIcons[$variant]))
        {!! $defaultIcons[$variant] !!}
    @endif

    @if ($title)
        <h5 class="font-medium leading-none tracking-tight">{{ $title }}</h5>
    @endif

    @if ($description)
        <div @class(['opacity-70', 'mt-1' => $title])>{!! $description !!}</div>
    @endif

    @if (trim($slot ?? ''))
        <div @class(['opacity-90', 'mt-1' => $title])>{{ $slot }}</div>
    @endif
</div>
