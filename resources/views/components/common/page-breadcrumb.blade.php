@props([
    'pageTitle' => 'Page',
    'breadcrumbs' => [],
])

<div {{ $attributes->twMerge('flex flex-wrap items-center justify-between gap-3 mb-6') }}>
    <h2 class="text-xl font-bold text-foreground tracking-tight">
        {{ $pageTitle }}
    </h2>
    <nav aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <li>
                <a class="inline-flex items-center gap-1 hover:text-foreground transition-colors" href="{{ route('home') }}" wire:navigate>
                    <span>{{ __('Home') }}</span>
                    <span class="material-symbols-rounded text-sm text-muted-foreground/60" aria-hidden="true">chevron_right</span>
                </a>
            </li>
            @foreach ($breadcrumbs as $crumb)
                <li>
                    @if (!empty($crumb['url']) && !$loop->last)
                        <a class="inline-flex items-center gap-1 hover:text-foreground transition-colors" href="{{ $crumb['url'] }}" wire:navigate>
                            <span>{{ $crumb['label'] }}</span>
                            <span class="material-symbols-rounded text-sm text-muted-foreground/60" aria-hidden="true">chevron_right</span>
                        </a>
                    @else
                        <span class="font-medium text-foreground" aria-current="page">{{ $crumb['label'] ?? $crumb }}</span>
                    @endif
                </li>
            @endforeach
            @if (empty($breadcrumbs))
                <li class="font-medium text-foreground" aria-current="page">
                    {{ $pageTitle }}
                </li>
            @endif
        </ol>
    </nav>
</div>
