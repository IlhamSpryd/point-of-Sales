@props([
    'position' => 'bottom',
    'align' => 'left',
    'gap' => '2',
    'animate' => true,
])

<div x-data="{
    popoverOpen: false,
    triggerPosition: { top: 0, left: 0, width: 0, height: 0 },

    init() {
        this.$watch('popoverOpen', value => {
            if (value) {
                this.calculateTriggerPosition();
            }
        });
    },

    calculateTriggerPosition() {
        const triggerRect = this.$refs.trigger.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        this.triggerPosition = {
            top: triggerRect.top + scrollTop,
            left: triggerRect.left + scrollLeft,
            width: triggerRect.width,
            height: triggerRect.height
        };
    },

    getPopoverStyles() {
        let top = this.triggerPosition.top;
        let left = this.triggerPosition.left;
        let transformX = '';
        let transformY = '';

        if ('{{ $position }}' === 'bottom') {
            top += this.triggerPosition.height + {{ (int) $gap * 4 }};
        } else if ('{{ $position }}' === 'top') {
            top -= {{ (int) $gap * 4 }};
            transformY = 'translateY(-100%)';
        } else if ('{{ $position }}' === 'right') {
            left += this.triggerPosition.width + {{ (int) $gap * 4 }};
        } else if ('{{ $position }}' === 'left') {
            left -= {{ (int) $gap * 4 }};
            transformX = 'translateX(-100%)';
        }

        if ('{{ $align }}' === 'center' && ('{{ $position }}' === 'top' || '{{ $position }}' === 'bottom')) {
            left += (this.triggerPosition.width / 2);
            transformX = 'translateX(-50%)';
        } else if ('{{ $align }}' === 'right' && ('{{ $position }}' === 'top' || '{{ $position }}' === 'bottom')) {
            left += this.triggerPosition.width;
            transformX = 'translateX(-100%)';
        } else if ('{{ $align }}' === 'center' && ('{{ $position }}' === 'right' || '{{ $position }}' === 'left')) {
            top += (this.triggerPosition.height / 2);
            transformY = 'translateY(-50%)';
        } else if ('{{ $align }}' === 'bottom' && ('{{ $position }}' === 'right' || '{{ $position }}' === 'left')) {
            top += this.triggerPosition.height;
            transformY = 'translateY(-100%)';
        }

        return {
            position: 'absolute',
            top: top + 'px',
            left: left + 'px',
            transform: [transformX, transformY].filter(t => t).join(' '),
            transformOrigin: this.getTransformOrigin(),
            zIndex: 50
        };
    },

    {{-- Scale-from-corner origin: the popover grows out of the corner nearest
         the trigger (top-right for a right-aligned popover, top-left for left). --}}
    getTransformOrigin() {
        let originY = '{{ $position }}' === 'top' ? 'bottom' : 'top';
        let originX = '{{ $position }}' === 'left' ? 'right' : 'left';

        if ('{{ $position }}' === 'top' || '{{ $position }}' === 'bottom') {
            originX = '{{ $align }}' === 'right' ? 'right' : ('{{ $align }}' === 'center' ? 'center' : 'left');
        } else {
            originY = '{{ $align }}' === 'bottom' ? 'bottom' : ('{{ $align }}' === 'center' ? 'center' : 'top');
        }

        return originY + ' ' + originX;
    },

    openPopover() {
        this.popoverOpen = true;
    }
}"
x-modelable="popoverOpen"
@resize.window="if (popoverOpen) calculateTriggerPosition()"
@scroll.window="if (popoverOpen) calculateTriggerPosition()"
@class([
    'relative inline-flex w-auto items-start',
    'flex-col' => $position === 'bottom' || $position === 'top',
    'flex-row' => $position === 'right' || $position === 'left',
])
{{ $attributes }}>

    <div x-ref="trigger" x-on:click="openPopover()">
        @if (isset($trigger))
            {{ $trigger }}
        @else
            <div class="relative inline-flex items-center justify-center rounded-medium border border-foreground/10 bg-background p-2 text-sm font-medium text-foreground transition-colors hover:bg-secondary focus:outline-none disabled:pointer-events-none disabled:opacity-50">
                Popover
            </div>
        @endif
    </div>

    <template x-teleport="body">
        <div
            x-show="popoverOpen"
            x-on:click.away="popoverOpen = false"
            x-on:keydown.escape.window="popoverOpen = false"
            @if ($animate)
                x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
            @else
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            @endif
            :style="getPopoverStyles()"
            x-cloak
            class="w-max max-w-sm">
            @if (isset($content))
                <div {{ $content->attributes->twMerge('overflow-hidden rounded-medium border border-foreground/10 bg-popover p-4 text-popover-foreground shadow-lg dark:border-foreground/15') }}>
                    {{ $content }}
                </div>
            @else
                {{ $slot }}
            @endif
        </div>
    </template>
</div>
