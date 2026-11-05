@props([
    'placeholder' => 'Cari menu & fitur…',
])

<div {{ $attributes->twMerge('inline-flex items-center') }}>
    {{-- Desktop trigger with keyboard shortcut pill --}}
    <button type="button"
        @click="$dispatch('command-open')"
        class="hidden md:inline-flex items-center gap-2.5 px-3 py-1.5 text-xs text-shell-foreground bg-shell-accent hover:bg-shell-border hover:text-shell-foreground-active border border-shell-border rounded-lg transition-all focus:outline-none focus:ring-1 focus:ring-shell-ring shadow-xs cursor-pointer group"
        title="Cari menu atau tekan Cmd+K / Ctrl+K">
        <span class="material-symbols-rounded text-[17px] text-shell-foreground group-hover:text-shell-foreground-active transition-colors" aria-hidden="true">search</span>
        <span class="text-[12px] font-normal text-shell-foreground group-hover:text-shell-foreground-active truncate max-w-[140px] lg:max-w-none">{{ $placeholder }}</span>
        <kbd class="hidden lg:inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-mono font-medium tracking-wide text-shell-foreground bg-shell border border-shell-border rounded shadow-xs ml-1.5">
            <span class="text-[11px]">⌘</span>K
        </kbd>
    </button>

    {{-- Mobile icon-only trigger --}}
    <button type="button"
        @click="$dispatch('command-open')"
        aria-label="Buka pencarian menu"
        class="md:hidden flex items-center justify-center w-8 h-8 rounded-lg text-shell-foreground bg-shell-accent hover:bg-shell-border hover:text-shell-foreground-active border border-shell-border transition-all cursor-pointer">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">search</span>
    </button>
</div>
