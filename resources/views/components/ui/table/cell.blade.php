@props([
    'align' => 'left',
    'mono' => false,
])

@php
    $alignClass = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
    $monoClass = $mono ? 'font-mono tabular-nums' : '';
@endphp

<td {{ $attributes->twMerge("whitespace-nowrap px-4 py-3 {$alignClass} {$monoClass} align-middle text-xs sm:text-sm text-foreground") }}>
    {{ $slot }}
</td>
