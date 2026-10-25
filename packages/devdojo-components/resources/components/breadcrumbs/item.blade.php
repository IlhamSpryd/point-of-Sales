@props([
    'href' => null,
    'current' => false,
])

{{-- The leading separator is hidden on the first item via the first-child rule. --}}
<li class="inline-flex items-center gap-1.5 [&:first-child>[data-sep]]:hidden">
    <svg data-sep class="size-3.5 shrink-0 text-foreground/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>

    @if ($href && ! $current)
        <a href="{{ $href }}" {{ $attributes->twMerge('text-sm text-foreground/60 transition-colors hover:text-foreground') }}>{{ $slot }}</a>
    @else
        <span aria-current="page" {{ $attributes->twMerge('text-sm font-medium text-foreground') }}>{{ $slot }}</span>
    @endif
</li>
