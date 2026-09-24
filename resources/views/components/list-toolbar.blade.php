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
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-yovel-muted text-[18px]">search</span>
            <input type="search" name="{{ $searchName }}" value="{{ request($searchName) }}" placeholder="{{ $searchPlaceholder }}"
                   class="w-full h-10 pl-10 pr-3 rounded-xl border border-transparent bg-white text-sm text-yovel-ink placeholder-yovel-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200">
        </form>
    @endif

    <div class="flex gap-2 w-full lg:w-auto shrink-0">
        {{ $actions ?? '' }}
    </div>
</div>
