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
        @if (! empty($breadcrumbs))
            <x-ui.breadcrumbs class="mb-2">
                @foreach ($breadcrumbs as $crumb)
                    <x-ui.breadcrumbs.item
                        :href="$crumb['href'] ?? ($crumb['url'] ?? null)"
                        :active="$loop->last"
                    >
                        {{ $crumb['label'] ?? '' }}
                    </x-ui.breadcrumbs.item>
                @endforeach
            </x-ui.breadcrumbs>
        @endif

        <div class="flex items-center gap-2.5 flex-wrap">
            <h1 class="text-lg sm:text-xl font-bold text-foreground tracking-tight truncate">
                {{ $title }}
            </h1>
            @if ($badge)
                <x-ui.badge :variant="$badgeVariant">{{ $badge }}</x-ui.badge>
            @endif
        </div>

        @if ($sub)
            <p class="mt-1 text-xs sm:text-sm font-medium text-muted-foreground">
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
