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

<th scope="col" {{ $attributes->twMerge("h-10 whitespace-nowrap px-4 {$alignClass} {$monoClass} align-middle text-xs font-semibold uppercase tracking-wider text-muted-foreground") }}>
    {{ $slot }}
</th>
