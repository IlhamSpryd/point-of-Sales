@props([
    'vertical' => false,
    'label' => null,
])

@if ($vertical)
    <div role="separator" aria-orientation="vertical" {{ $attributes->twMerge('mx-2 h-full w-px shrink-0 self-stretch bg-foreground/10') }}></div>
@elseif ($label)
    <div role="separator" {{ $attributes->twMerge('flex w-full items-center gap-3 text-xs font-medium text-foreground/50') }}>
        <span class="h-px flex-1 bg-foreground/10"></span>
        <span class="shrink-0">{{ $label }}</span>
        <span class="h-px flex-1 bg-foreground/10"></span>
    </div>
@else
    <div role="separator" aria-orientation="horizontal" {{ $attributes->twMerge('h-px w-full bg-foreground/10') }}></div>
@endif
