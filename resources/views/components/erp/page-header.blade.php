@props([
    'title' => '',
    'description' => null,
    'subtitle' => null,
    'breadcrumbs' => [],
    'badge' => null,
    'badgeVariant' => 'secondary',
])

@php
    $sub = $description ?? $subtitle;
@endphp

<div {{ $attributes->twMerge('flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6 shrink-0') }}>
    <div class="min-w-0">
        <div class="flex items-center gap-2.5 flex-wrap">
            <h1 class="text-[18px] font-semibold tracking-[-0.02em] leading-tight text-foreground truncate">
                {{ $title }}
            </h1>
            @if ($badge)
                <x-ui.badge :variant="$badgeVariant">{{ $badge }}</x-ui.badge>
            @endif
        </div>

        @if ($sub)
            <p class="mt-0.5 text-[12px] font-medium text-muted-foreground">
                {{ $sub }}
            </p>
        @endif
    </div>

    @if (isset($actions) || trim($slot ?? '') !== '')
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            {{ $actions ?? $slot }}
        </div>
    @endif
</div>
