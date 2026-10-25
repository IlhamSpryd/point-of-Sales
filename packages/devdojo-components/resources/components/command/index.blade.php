@props([
    'items' => [],
    'placeholder' => 'Type a command or search…',
])

<div
    x-data="{
        open: false,
        search: '',
        active: 0,
        items: @js(array_values($items)),
        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (! q) return this.items;
            return this.items
                .map(item => [item, this.score(item, q)])
                .filter(([, score]) => score !== Infinity)
                .sort((a, b) => a[1] - b[1])
                .map(([item]) => item);
        },
        // Fuzzy rank: prefix beats substring beats a scattered subsequence,
        // so 'dtpkr' still finds 'Date Picker'.
        score(item, q) {
            const title = (item.title || '').toLowerCase();
            if (title.startsWith(q)) return 0;
            const index = title.indexOf(q);
            if (index !== -1) return 1 + index / 100;
            let at = 0, gaps = 0, previous = -2;
            for (const char of q) {
                at = title.indexOf(char, at);
                if (at === -1) return Infinity;
                if (at !== previous + 1) gaps++;
                previous = at;
                at++;
            }
            return 3 + gaps + title.length / 100;
        },
        openPalette() {
            this.open = true;
            this.search = '';
            this.active = 0;
            this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
        },
        move(dir) {
            const count = this.filtered.length;
            if (! count) return;
            this.active = (this.active + dir + count) % count;
            this.$nextTick(() => {
                const el = this.$refs.list && this.$refs.list.querySelector('[data-active=\'true\']');
                if (el) el.scrollIntoView({ block: 'nearest' });
            });
        },
        select(item) {
            if (! item) return;
            this.$dispatch('command-select', { value: item.value, title: item.title });
            this.open = false;
        },
        showGroup(item, index) {
            // Results are ranked while searching, so group headers only make
            // sense for the browse (empty-query) list.
            if (! item.group || this.search.trim() !== '') return false;
            return index === 0 || (this.filtered[index - 1] || {}).group !== item.group;
        }
    }"
    @keydown.window.cmd.k.prevent="openPalette()"
    @keydown.window.ctrl.k.prevent="openPalette()"
    @command-open.window="openPalette()"
    {{ $attributes->twMerge('inline-block') }}
>
    {{-- Trigger --}}
    <div @click="openPalette()">
        @if (isset($trigger))
            {{ $trigger }}
        @else
            <button type="button" class="inline-flex items-center gap-2 rounded-medium border border-foreground/10 bg-background px-3 py-2 text-sm text-foreground/50 transition hover:bg-secondary">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                <span>Search…</span>
                <kbd class="ml-6 rounded-small border border-foreground/10 bg-secondary px-1.5 py-0.5 font-mono text-[10px] text-foreground/60">⌘K</kbd>
            </button>
        @endif
    </div>

    {{-- Palette, teleported so it is never clipped. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-id="['dd-command']"
            class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[15vh]"
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            @keydown.escape.window="open = false"
        >
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" @click="open = false"></div>

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
                class="relative z-10 flex w-full max-w-xl flex-col overflow-hidden rounded-large border border-foreground/10 bg-popover text-popover-foreground shadow-lg dark:border-foreground/15"
            >
                <div class="flex items-center gap-2 border-b border-foreground/10 px-3">
                    <svg class="size-4 shrink-0 text-foreground/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
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
                        class="h-12 w-full bg-transparent text-sm text-foreground outline-none placeholder:text-foreground/40"
                    />
                </div>

                <div x-ref="list" :id="$id('dd-command')" role="listbox" class="max-h-80 overflow-y-auto overflow-x-hidden overscroll-contain p-1.5">
                    <template x-for="(item, index) in filtered" :key="item.value">
                        <div>
                            <template x-if="showGroup(item, index)">
                                <div class="px-2 pb-1 pt-2 text-xs font-medium text-foreground/40" x-text="item.group"></div>
                            </template>
                            <div
                                :id="$id('dd-command') + '-option-' + index"
                                role="option"
                                :aria-selected="active === index"
                                :data-active="active === index"
                                @click="select(item)"
                                @mousemove="active = index"
                                class="flex cursor-pointer items-center gap-2.5 rounded-medium px-2.5 py-2 text-sm text-foreground/80"
                                :class="active === index ? 'bg-secondary text-foreground' : ''"
                            >
                                <template x-if="item.icon"><span class="flex size-4 shrink-0 items-center justify-center text-foreground/60" x-html="item.icon"></span></template>
                                <span x-text="item.title"></span>
                                <template x-if="item.shortcut"><span class="ml-auto font-mono text-xs tracking-widest text-foreground/40" x-text="item.shortcut"></span></template>
                            </div>
                        </div>
                    </template>

                    <div x-show="filtered.length === 0" class="px-3 py-8 text-center text-sm text-foreground/50">No results found.</div>
                </div>

                <div class="flex items-center gap-4 border-t border-foreground/10 px-3.5 py-2 text-[11px] text-foreground/40">
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-foreground/10 bg-secondary px-1 font-sans text-[10px] text-foreground/55">↑</kbd>
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-foreground/10 bg-secondary px-1 font-sans text-[10px] text-foreground/55">↓</kbd>
                        Navigate
                    </span>
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-small border border-foreground/10 bg-secondary px-1 font-sans text-[10px] text-foreground/55">↵</kbd>
                        Select
                    </span>
                    <span class="flex items-center gap-1.5">
                        <kbd class="inline-flex h-[18px] items-center justify-center rounded-small border border-foreground/10 bg-secondary px-1 font-sans text-[10px] text-foreground/55">esc</kbd>
                        Close
                    </span>
                </div>
            </div>
        </div>
    </template>
</div>
