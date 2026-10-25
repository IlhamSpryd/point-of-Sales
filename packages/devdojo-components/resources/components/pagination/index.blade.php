@props([
    'currentPage' => 1,
    'totalPages' => 1,
    'baseUrl' => null,
])

@php
    $current = max(1, (int) $currentPage);
    $last = max(1, (int) $totalPages);
    $base = $baseUrl ?? (request()?->url() ?? '');
    $url = fn ($p) => $base === '' ? '#' : $base.(str_contains($base, '?') ? '&' : '?').'page='.$p;

    // Build a windowed list of pages (1 … current-1 current current+1 … last).
    $window = 1;
    $shown = collect(range(1, $last))
        ->filter(fn ($p) => $p === 1 || $p === $last || abs($p - $current) <= $window)
        ->values();

    $items = [];
    $previous = 0;
    foreach ($shown as $page) {
        if ($page - $previous > 1) {
            $items[] = '…';
        }
        $items[] = $page;
        $previous = $page;
    }

    $navClasses = 'inline-flex h-9 min-w-9 items-center justify-center gap-1 rounded-medium border border-foreground/10 bg-background px-3 text-sm font-medium text-foreground transition hover:bg-secondary';
@endphp

<nav aria-label="Pagination" {{ $attributes->twMerge('flex items-center gap-1.5') }}>
    @if ($current > 1)
        <a href="{{ $url($current - 1) }}" rel="prev" aria-label="Previous page" class="{{ $navClasses }}">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
        </a>
    @else
        <span aria-disabled="true" class="{{ $navClasses }} pointer-events-none opacity-50">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
        </span>
    @endif

    @foreach ($items as $item)
        @if ($item === '…')
            <span class="inline-flex h-9 min-w-9 items-center justify-center text-sm text-foreground/40">…</span>
        @elseif ($item === $current)
            <span aria-current="page" class="inline-flex h-9 min-w-9 items-center justify-center rounded-medium bg-primary px-3 text-sm font-medium text-primary-foreground">{{ $item }}</span>
        @else
            <a href="{{ $url($item) }}" class="inline-flex h-9 min-w-9 items-center justify-center rounded-medium px-3 text-sm font-medium text-foreground/70 transition hover:bg-secondary hover:text-foreground">{{ $item }}</a>
        @endif
    @endforeach

    @if ($current < $last)
        <a href="{{ $url($current + 1) }}" rel="next" aria-label="Next page" class="{{ $navClasses }}">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
        </a>
    @else
        <span aria-disabled="true" class="{{ $navClasses }} pointer-events-none opacity-50">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
        </span>
    @endif
</nav>
