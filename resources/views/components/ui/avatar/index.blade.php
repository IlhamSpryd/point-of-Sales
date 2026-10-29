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

    // Optional deterministic color from a seed (color="auto").
    $palette = ['blue', 'green', 'amber', 'rose', 'violet', 'cyan', 'orange', 'teal', 'pink', 'indigo'];
    if ($color === 'auto') {
        $color = $palette[crc32((string) ($name ?? $initials ?? 'avatar')) % count($palette)];
    }

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

    $colorClasses = match ($color) {
        'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
        'green' => 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
        'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
        'violet' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
        'cyan' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300',
        'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
        'teal' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
        'pink' => 'bg-pink-100 text-pink-700 dark:bg-pink-500/20 dark:text-pink-300',
        'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
        default => 'bg-secondary text-secondary-foreground',
    };

    $statusClasses = match ($status) {
        'online' => 'bg-green-500',
        'away' => 'bg-amber-500',
        'busy' => 'bg-rose-500',
        'offline' => 'bg-foreground/30',
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
        <svg class="{{ $iconClasses }} text-foreground/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" /></svg>
    @endif

    {{-- Hairline ring for definition, especially over photos. --}}
    <span class="pointer-events-none absolute inset-0 {{ $shape }} ring-1 ring-inset ring-foreground/10"></span>

    @if ($statusClasses)
        <span class="absolute bottom-0 right-0 block rounded-full ring-2 ring-background {{ $dotClasses }} {{ $statusClasses }}"></span>
    @endif
</span>
