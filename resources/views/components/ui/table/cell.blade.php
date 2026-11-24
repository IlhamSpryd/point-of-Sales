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
    $monoClass = $mono ? 'tabular-nums' : '';
@endphp

<td {{ $attributes->twMerge("whitespace-nowrap px-3 py-2 {$alignClass} {$monoClass} align-middle text-[12px] text-foreground") }}>
    {{ $slot }}
</td>
