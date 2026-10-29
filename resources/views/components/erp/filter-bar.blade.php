@props([
    'searchModel' => 'search',
    'searchPlaceholder' => 'Cari data...',
    'searchAction' => null,
    'searchName' => 'search',
    'resetMethod' => null,
])

<div {{ $attributes->twMerge('card-surface p-3.5 sm:p-4 mb-5 flex flex-col lg:flex-row lg:items-center justify-between gap-3') }}>
    <div class="flex flex-1 flex-col sm:flex-row sm:items-center gap-2.5">
        @if ($searchAction)
            <form action="{{ $searchAction }}" method="GET" class="relative w-full sm:max-w-xs">
                <span class="material-symbols-rounded pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-muted-foreground" aria-hidden="true">search</span>
                <input
                    type="search"
                    name="{{ $searchName }}"
                    value="{{ request($searchName) }}"
                    placeholder="{{ $searchPlaceholder }}"
                    aria-label="{{ $searchPlaceholder }}"
                    class="h-9 w-full rounded-medium border border-input bg-background pl-9 pr-3 text-xs sm:text-sm text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                />
            </form>
        @elseif ($searchModel)
            <div class="relative w-full sm:max-w-xs">
                <span class="material-symbols-rounded pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-muted-foreground" aria-hidden="true">search</span>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="{{ $searchModel }}"
                    placeholder="{{ $searchPlaceholder }}"
                    aria-label="{{ $searchPlaceholder }}"
                    class="h-9 w-full rounded-medium border border-input bg-background pl-9 pr-3 text-xs sm:text-sm text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                />
            </div>
        @endif

        @if (isset($filters) || trim($slot ?? '') !== '')
            <div class="flex flex-wrap items-center gap-2">
                {{ $filters ?? $slot }}
            </div>
        @endif
    </div>

    @if (isset($actions) || $resetMethod)
        <div class="flex flex-wrap items-center justify-end gap-2 shrink-0">
            @if ($resetMethod)
                <x-ui.button variant="ghost" size="sm" wire:click="{{ $resetMethod }}" icon="restart_alt">
                    Reset
                </x-ui.button>
            @endif
            {{ $actions ?? '' }}
        </div>
    @endif
</div>
