@props([
    'align' => 'right',
    'icon' => 'more_vert',
    'label' => 'Actions',
])

<div
    x-data="{
        dropdownOpen: false,
        pos: { top: 0, left: 0, transform: '' },

        toggleDropdown() {
            if (!this.dropdownOpen) {
                this.updatePosition();
            }
            this.dropdownOpen = !this.dropdownOpen;
        },

        updatePosition() {
            if (!this.$refs.trigger) return;
            const rect = this.$refs.trigger.getBoundingClientRect();
            const gap = 4;
            const isRight = '{{ $align }}' === 'right';
            const flipUp = (rect.bottom + 180 > window.innerHeight) && (rect.top > 180);

            let top = flipUp ? (rect.top - gap) : (rect.bottom + gap);
            let left = isRight ? rect.right : rect.left;
            let transformX = isRight ? '-100%' : '0%';
            let transformY = flipUp ? '-100%' : '0%';

            this.pos = {
                top: top + 'px',
                left: left + 'px',
                transform: `translate(${transformX}, ${transformY})`
            };
        }
    }"
    @scroll.window="if (dropdownOpen) dropdownOpen = false"
    @resize.window="if (dropdownOpen) dropdownOpen = false"
    {{ $attributes->twMerge('relative inline-flex items-center justify-end') }}
>
    <button
        type="button"
        x-ref="trigger"
        @click="toggleDropdown()"
        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground hover:bg-muted/80 focus:outline-hidden focus-visible:ring-1 focus-visible:ring-ring transition-colors cursor-pointer select-none"
        aria-haspopup="true"
        :aria-expanded="dropdownOpen.toString()"
        aria-label="{{ $label }}"
    >
        <span class="material-symbols-rounded text-[18px] leading-none shrink-0" aria-hidden="true">{{ $icon }}</span>
    </button>

    <template x-teleport="body">
        <div
            x-show="dropdownOpen"
            @click.away="dropdownOpen = false"
            @keydown.escape.window="dropdownOpen = false"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            :style="{
                position: 'fixed',
                top: pos.top,
                left: pos.left,
                transform: pos.transform,
                zIndex: 60
            }"
            x-cloak
            class="min-w-36 max-w-56 overflow-hidden rounded-md border border-border bg-popover p-1 text-xs text-popover-foreground shadow-md font-sans"
        >
            <div @click="dropdownOpen = false" class="flex flex-col gap-0.5">
                {{ $slot }}
            </div>
        </div>
    </template>
</div>

