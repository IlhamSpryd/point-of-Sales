@props([
    'title' => '',
    'description' => '',
])

<div {{ $attributes->twMerge('flex w-full flex-col items-center justify-center gap-0 rounded-large border border-dashed border-border px-6 py-20 text-center') }}>
    <div class="flex size-14 items-center justify-center rounded-large bg-secondary text-muted-foreground">
        @if (isset($icon))
            {{ $icon }}
        @else
            <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" /></svg>
        @endif
    </div>

    @if ($title)
        <p class="mt-5 text-lg font-semibold text-foreground">{{ $title }}</p>
    @endif

    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground text-pretty">{{ $description }}</p>
    @endif

    @if (trim($slot ?? '') !== '')
        <div class="mt-6 flex items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
