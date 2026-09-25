{{-- [OMEGA-NODE2] Toolbar seragam agar search + aksi tidak "mengambang lepas"
     di atas background putih — dibungkus satu kelompok visual, konsisten
     dengan pola toggle Dine-in/Takeaway di Kasir. | 2026-09-23 --}}
@props([
    'searchAction' => null,
    'searchName' => 'search',
    'searchPlaceholder' => 'Cari...',
])

<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between rounded-2xl border border-yovel-border bg-yovel-bg p-2 shrink-0">
    @if ($searchAction)
        <form action="{{ $searchAction }}" method="GET" class="relative w-full sm:w-56 lg:w-72 shrink-0" style="max-width: 300px;">
            <x-search-input name="{{ $searchName }}" value="{{ request($searchName) }}" placeholder="{{ $searchPlaceholder }}" />
        </form>
    @endif

    <div class="flex gap-2 w-full lg:w-auto shrink-0">
        {{ $actions ?? '' }}
    </div>
</div>
