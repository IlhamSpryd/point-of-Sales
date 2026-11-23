@props([
    'placeholder' => 'Search navigation & features…',
])

<div {{ $attributes->twMerge('inline-flex items-center') }}>
    {{-- Desktop trigger with keyboard shortcut pill --}}
    <button type="button"
        @click="$dispatch('command-open')"
        class="hidden md:inline-flex items-center justify-between w-48 xl:w-56 h-8 px-2.5 text-[12px] text-muted-foreground bg-shell hover:text-foreground border border-border rounded-lg transition-colors focus:outline-none focus:ring-1 focus:ring-ring cursor-pointer group"
        title="Search navigation or press Cmd+K">
        <div class="flex items-center gap-2 truncate">
            <span class="material-symbols-rounded text-[16px] text-muted-foreground group-hover:text-foreground transition-colors shrink-0" aria-hidden="true">search</span>
            <span class="truncate">{{ $placeholder }}</span>
        </div>
        <kbd class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-sans font-medium text-muted-foreground bg-background border border-border rounded shrink-0">
            <span class="text-[10px]">⌘</span>K
        </kbd>
    </button>

    {{-- Mobile icon-only trigger --}}
    <button type="button"
        @click="$dispatch('command-open')"
        aria-label="Open search dialog"
        class="md:hidden flex items-center justify-center w-8 h-8 rounded-lg text-muted-foreground bg-secondary hover:bg-muted hover:text-foreground border border-border transition-colors cursor-pointer">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">search</span>
    </button>
</div>
