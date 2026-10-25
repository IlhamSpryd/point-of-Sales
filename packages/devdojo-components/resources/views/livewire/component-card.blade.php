<div class="self-start">
    @if ($published)
        <div class="relative" x-data="{ menu: false }">
            {{-- Joined status + menu group --}}
            <div class="inline-flex h-7 items-stretch overflow-hidden rounded-medium border border-foreground/10 bg-card shadow-xs">
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap pl-2.5 pr-2 text-xs font-medium text-green-600 dark:text-green-400">
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    Added
                </span>
                <button type="button" @click="menu = ! menu" aria-label="More options" :aria-expanded="menu"
                    class="inline-flex w-6 items-center justify-center border-l border-foreground/10 text-foreground/40 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:bg-secondary focus-visible:text-foreground">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
                </button>
            </div>

            <div x-show="menu" x-cloak @click.away="menu = false" @keydown.escape.stop="menu = false"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0 -translate-y-1"
                class="absolute right-0 top-full z-50 mt-1.5 w-52 origin-top-right overflow-hidden rounded-medium border border-foreground/10 bg-popover p-1 text-popover-foreground shadow-lg dark:border-foreground/15">
                <button type="button" wire:click="reAdd" wire:target="reAdd" wire:loading.attr="disabled"
                    @click="menu = false"
                    class="flex w-full items-center gap-2 rounded-small px-2.5 py-1.5 text-left text-[13px] text-foreground/80 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:bg-secondary focus-visible:text-foreground">
                    <svg wire:loading.remove wire:target="reAdd" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /></svg>
                    <svg wire:loading wire:target="reAdd" class="h-3.5 w-3.5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    <span>Re-add <span class="text-foreground/45">(overwrite)</span></span>
                </button>
            </div>
        </div>
    @else
        <button type="button" wire:click="add" wire:target="add" wire:loading.attr="disabled"
            class="inline-flex h-7 shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-medium border border-foreground/10 bg-card px-2.5 text-xs font-medium text-foreground/70 shadow-xs outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98] disabled:opacity-60">
            <svg wire:loading.remove wire:target="add" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14" /></svg>
            <svg wire:loading wire:target="add" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            <span>Add</span>
        </button>
    @endif
</div>
