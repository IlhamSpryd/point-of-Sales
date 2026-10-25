@props([
    'lines' => null,
    'circle' => false,
])

@php
    $shape = $circle ? 'rounded-full' : 'rounded-medium';
@endphp

@if ($lines)
    <div {{ $attributes->twMerge('w-full space-y-2.5') }}>
        @for ($i = 0; $i < (int) $lines; $i++)
            <div @class([
                'h-3.5 animate-pulse rounded-medium bg-foreground/10',
                'w-full' => $i !== (int) $lines - 1,
                'w-2/3' => $i === (int) $lines - 1,
            ])></div>
        @endfor
    </div>
@else
    <div {{ $attributes->twMerge('h-4 w-full animate-pulse bg-foreground/10 '.$shape) }}></div>
@endif
