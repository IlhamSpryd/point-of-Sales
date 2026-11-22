@props([
    'items' => [],
    'placeholder' => 'Type a command or search… (Ctrl+K)',
    'headless' => false,
])

<div
    x-data="window.apeironCommandPalette ? window.apeironCommandPalette(@js(array_values($items))) : { open: false, search: '', active: 0, items: @js(array_values($items)), loadRecents() {}, openPalette() { this.open = true; }, closePalette() { this.open = false; }, move() {}, select() {}, showGroup() { return false; } }"
    @keydown.window.cmd.k.prevent="openPalette()"
    @keydown.window.ctrl.k.prevent="openPalette()"
    @command-open.window="openPalette()"
    x-init="loadRecents()"
    {{ $attributes->twMerge('inline-block') }}
>
    {{-- Trigger --}}
    @if (!$headless)
        <div @click="openPalette()">
            @if (isset($trigger))
                {{ $trigger }}
            @else
                <button type="button" class="inline-flex items-center gap-2 rounded-control border border-border bg-background px-3 py-1.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground">
                    <x-ui.icon name="search" size="xs" />
                    <span>Search navigation…</span>
                    <kbd class="ml-4 rounded border border-border bg-muted px-1.5 py-0.5 font-sans text-[10px] text-muted-foreground">⌘K</kbd>
                </button>
            @endif
        </div>
    @endif

    {{-- Palette, teleported so it is never clipped. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-id="['dd-command']"
            class="fixed inset-0 z-50 flex items-start justify-center p-0 sm:p-4 pt-0 sm:pt-[15vh]"
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            @keydown.escape.window="closePalette()"
        >
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-overlay-scrim" @click="closePalette()"></div>

            <div
                x-show="open"
                x-trap.noscroll="open"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="select(filtered[active])"
                class="relative z-10 flex h-dvh sm:h-auto w-full max-w-none sm:max-w-xl flex-col overflow-hidden rounded-none sm:rounded-large border-0 sm:border border-border bg-popover text-popover-foreground shadow-lg"
            >
                <div class="flex items-center gap-2 border-b border-border px-3">
                    <svg class="size-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    <input
                        x-ref="search"
                        x-model="search"
                        @input="active = 0"
                        type="text"
                        placeholder="{{ $placeholder }}"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                        role="combobox"
                        aria-expanded="true"
                        aria-autocomplete="list"
                        :aria-controls="$id('dd-command')"
                        :aria-activedescendant="filtered.length ? $id('dd-command') + '-option-' + active : null"
                        class="h-12 w-full bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                    />
                </div>

                <div x-ref="list" :id="$id('dd-command')" role="listbox" class="max-h-80 overflow-y-auto overflow-x-hidden overscroll-contain p-1.5">
                    <template x-for="(item, index) in filtered" :key="item.value">
                        <div>
                            <template x-if="showGroup(item, index)">
                                <div class="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground" x-text="item.group"></div>
                            </template>
                            <div
                                :id="$id('dd-command') + '-option-' + index"
                                role="option"
                                :aria-selected="active === index"
                                :data-active="active === index"
                                @click="select(item)"
                                @mousemove="active = index"
                                class="flex cursor-pointer items-center gap-2.5 rounded-medium px-2.5 py-2 text-sm text-foreground"
                                :class="active === index ? 'bg-secondary text-foreground' : ''"
                            >
                                <template x-if="item.icon"><span class="material-symbols-rounded text-base shrink-0 text-muted-foreground" x-text="item.icon"></span></template>
                                <span x-text="item.title"></span>
                                <template x-if="item.shortcut"><span class="ml-auto font-sans text-xs tracking-widest text-muted-foreground" x-text="item.shortcut"></span></template>
                            </div>
                        </div>
                    </template>

                    <div x-show="filtered.length === 0" class="px-3 py-8 text-center text-sm text-muted-foreground">No results found.</div>
                </div>

                <div class="flex items-center gap-4 border-t border-border px-3.5 py-2 text-[11px] text-muted-foreground">
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-border bg-secondary px-1 font-sans text-[10px] text-muted-foreground">↑</kbd>
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-border bg-secondary px-1 font-sans text-[10px] text-muted-foreground">↓</kbd>
                        Navigate
                    </span>
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-border bg-secondary px-1 font-sans text-[10px] text-muted-foreground">↵</kbd>
                        Select
                    </span>
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] items-center justify-center rounded-small border border-border bg-secondary px-1 font-sans text-[10px] text-muted-foreground">esc</kbd>
                        Close
                    </span>
                </div>
            </div>
        </div>
    </template>
</div>
