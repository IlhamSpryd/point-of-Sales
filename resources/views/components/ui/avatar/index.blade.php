@props([
    'src' => null,
    'name' => null,
    'initials' => null,
    'size' => 'md',
    'circle' => false,
    'color' => null,
    'status' => null,
])

@php
    // Derive initials from a full name when none are given.
    if ($name && ! $initials) {
        $parts = collect(explode(' ', trim($name)))->filter()->values();
        $initials = $parts->count() > 1
            ? mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[1], 0, 1))
            : mb_strtoupper(mb_substr($parts[0] ?? '', 0, 2));
    }

    // Monochrome per design principles: hue is reserved for state, not decoration.
    // The legacy $color prop (incl. color="auto") is accepted for API compatibility but no longer tints.

    $sizeClasses = match ($size) {
        'xs' => 'size-6 text-[10px]',
        'sm' => 'size-8 text-xs',
        'lg' => 'size-12 text-base',
        'xl' => 'size-16 text-lg',
        default => 'size-10 text-sm',
    };

    $iconClasses = match ($size) {
        'xs' => 'size-3.5',
        'sm' => 'size-4',
        'lg' => 'size-7',
        'xl' => 'size-9',
        default => 'size-5',
    };

    $dotClasses = match ($size) {
        'xs', 'sm' => 'size-2',
        'lg', 'xl' => 'size-3.5',
        default => 'size-2.5',
    };

    // Monochrome per design principles: hue is reserved for state, not decoration.
    // The legacy $color prop is accepted for API compatibility but no longer tints.
    $colorClasses = 'bg-secondary text-secondary-foreground';

    $statusClasses = match ($status) {
        'online' => 'bg-success',
        'away' => 'bg-warning',
        'busy' => 'bg-destructive',
        'offline' => 'bg-secondary',
        default => '',
    };

    $shape = $circle ? 'rounded-full' : 'rounded-medium';
@endphp

<span {{ $attributes->twMerge('relative inline-flex shrink-0 select-none items-center justify-center font-medium '.$sizeClasses.' '.$shape.' '.($src ? 'bg-secondary' : $colorClasses)) }}>
    @if ($src)
        <img src="{{ $src }}" alt="{{ $name ?? 'Avatar' }}" class="size-full {{ $shape }} object-cover" />
    @elseif ($initials)
        <span class="leading-none">{{ $initials }}</span>
    @elseif (trim($slot ?? '') !== '')
        {{ $slot }}
    @else
        <svg class="{{ $iconClasses }} text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" /></svg>
    @endif

    {{-- Hairline ring for definition, especially over photos. --}}
    <span class="pointer-events-none absolute inset-0 {{ $shape }} ring-1 ring-inset ring-ring"></span>

    @if ($statusClasses)
        <span class="absolute bottom-0 right-0 block rounded-full ring-2 ring-background {{ $dotClasses }} {{ $statusClasses }}"></span>
    @endif
</span>
