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

<th scope="col" {{ $attributes->twMerge("h-8 whitespace-nowrap px-3 py-2 {$alignClass} {$monoClass} align-middle text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground") }}>
    {{ $slot }}
</th>
