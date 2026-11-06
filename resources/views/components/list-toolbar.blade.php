{{-- Uniform toolbar grouping search and action controls cleanly --}}
@props([
    'searchAction' => null,
    'searchName' => 'search',
    'searchPlaceholder' => 'Search...',
])

<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between w-full lg:w-auto shrink-0">
    @if ($searchAction)
        <form action="{{ $searchAction }}" method="GET" class="relative w-full sm:w-64 shrink-0">
            <x-search-input name="{{ $searchName }}" value="{{ request($searchName) }}" placeholder="{{ $searchPlaceholder }}" />
        </form>
    @else
        <div class="relative w-full sm:w-64 shrink-0">
            <x-search-input name="{{ $searchName }}" value="{{ request($searchName) }}" placeholder="{{ $searchPlaceholder }}" />
        </div>
    @endif

    <div class="flex gap-2 w-full lg:w-auto shrink-0">
        {{ $actions ?? '' }}
    </div>
</div>
