@props([
    'direction' => 'horizontal',
    'gutterSize' => 8,
    'minSize' => 100,
    'storageKey' => null,
])

@php
    $baseClasses = 'flex h-full w-full items-stretch justify-stretch';
@endphp

@once
    {{-- Split.js powers the drag-to-resize behavior; it loads once from a CDN. --}}
    <script src="https://cdn.jsdelivr.net/npm/split.js@1.6.5/dist/split.min.js" defer></script>
@endonce

<div
    x-data="{
        splitInstance: null,
        direction: '{{ $direction }}',
        storageKey: @js($storageKey),
        _splitRetries: 0,
        _lastSizes: null,
        _reinitQueued: false,

        // Sizes persisted under storageKey (an array of pane percentages the
        // host app saves from splitter-resized events), or null.
        storedSizes(count) {
            if (!this.storageKey) return null;
            try {
                const sizes = JSON.parse(localStorage.getItem(this.storageKey));
                if (!Array.isArray(sizes) || sizes.length !== count) return null;
                if (!sizes.every(s => Number.isFinite(s) && s >= 0)) return null;
                const total = sizes.reduce((sum, s) => sum + s, 0);
                return (total > 95 && total < 105) ? sizes : null;
            } catch (e) { return null; }
        },

        initialSizes(panes) {
            const remembered = (this._lastSizes && this._lastSizes.length === panes.length)
                ? this._lastSizes
                : this.storedSizes(panes.length);
            return remembered || panes.map(p => {
                // Not ||: a collapsed pane legitimately declares size 0.
                const declared = parseFloat(p.dataset.size);
                return Number.isFinite(declared) ? declared : (100 / panes.length);
            });
        },

        // Paint the initial layout before Split.js (async, from a CDN) is up,
        // using the same calc() Split.js will apply — otherwise the first
        // frames render at flex defaults and snap once Split initializes.
        applyStaticSizes() {
            const panes = [...this.$el.querySelectorAll(':scope > .dd-split-pane')];
            if (panes.length < 2) return;
            const sizes = this.initialSizes(panes);
            const property = this.direction === 'vertical' ? 'height' : 'width';
            panes.forEach((p, i) => {
                const gutterPx = (i === 0 || i === panes.length - 1) ? {{ (int) $gutterSize }} / 2 : {{ (int) $gutterSize }};
                p.style[property] = 'calc(' + sizes[i] + '% - ' + gutterPx + 'px)';
            });
        },

        initializeSplitter() {
            // Split.js loads async from the CDN above, while Alpine fires
            // x-init synchronously — so the first call can run before
            // window.Split exists. Poll briefly until it does, then build.
            if (typeof window.Split === 'undefined') {
                this._splitRetries = (this._splitRetries || 0) + 1;
                if (this._splitRetries < 100) {
                    return setTimeout(() => this.initializeSplitter(), 50);
                }
                console.warn('Split.js unavailable after retry; splitter init aborted.');
                return;
            }
            this._splitRetries = 0;

            const panes = [...this.$el.querySelectorAll(':scope > .dd-split-pane')];
            if (panes.length < 2) return;

            const sizes = this.initialSizes(panes);
            // Per-pane minimums via data-min-size (px); fall back to the shared prop.
            const minSizes = panes.map(p => p.dataset.minSize !== undefined ? parseInt(p.dataset.minSize) : {{ (int) $minSize }});

            // Tear down any prior instance so re-init swaps orientations cleanly.
            // Gutter elements stay in the DOM — they're adopted again below.
            if (this.splitInstance) {
                try { this.splitInstance.destroy(false, true); } catch (e) {}
                this.splitInstance = null;
            }
            panes.forEach(p => { p.style.removeProperty('width'); p.style.removeProperty('height'); });

            // Adopt existing gutters — server-rendered x-splitter.gutter
            // elements or survivors of a prior instance — so Livewire morphs
            // see the same children the server rendered and never shuffle the
            // panes (which would reload any iframe inside them). Extra ones
            // (e.g. after a pane count change) are dropped.
            const gutters = [...this.$el.querySelectorAll(':scope > .gutter')];
            gutters.splice(panes.length - 1).forEach(g => g.remove());

            this.splitInstance = Split(panes, {
                direction: this.direction,
                sizes: sizes,
                gutterSize: {{ (int) $gutterSize }},
                minSize: minSizes,
                gutter: (index, gutterDirection) => {
                    const gutter = gutters[index - 1] || document.createElement('div');
                    gutter.setAttribute('wire:ignore', '');
                    gutter.className = `gutter gutter-${gutterDirection}`;
                    return gutter;
                },
                onDragStart: (sizes, gutter) => {
                    if (gutter && gutter.classList) gutter.classList.add('gutter-dragging');
                    document.body.classList.add('gutter-dragging-body');
                    if (gutter && gutter.classList && gutter.classList.contains('gutter-vertical')) {
                        document.body.classList.add('gutter-dragging-vertical');
                    }
                },
                onDragEnd: (sizes, gutter) => {
                    this._lastSizes = sizes;
                    if (gutter && gutter.classList) gutter.classList.remove('gutter-dragging');
                    document.body.classList.remove('gutter-dragging-body', 'gutter-dragging-vertical');
                    // Let the host app react (persist sizes, refresh embedded editors, …).
                    this.$el.dispatchEvent(new CustomEvent('splitter-resized', { detail: { sizes }, bubbles: true }));
                },
            });
            this._lastSizes = sizes;
            this.$el.dispatchEvent(new CustomEvent('splitter-initialized', { detail: { sizes }, bubbles: true }));

            if (!this._morphGuard) {
                // Safety net for splitters without server-rendered gutters: a
                // Livewire morph removes client-injected ones. Detect that and
                // rebuild — _lastSizes keeps the user's layout. Give panes
                // wire:key when the splitter lives inside a morphing region.
                this._morphGuard = new MutationObserver(() => {
                    const paneCount = this.$el.querySelectorAll(':scope > .dd-split-pane').length;
                    const gutterCount = this.$el.querySelectorAll(':scope > .gutter').length;
                    if (paneCount >= 2 && gutterCount !== paneCount - 1 && !this._reinitQueued) {
                        this._reinitQueued = true;
                        setTimeout(() => { this._reinitQueued = false; this.initializeSplitter(); }, 60);
                    }
                });
                this._morphGuard.observe(this.$el, { childList: true });
            }
        },

        setSizes(sizes) {
            if (this.splitInstance && Array.isArray(sizes)) {
                this._lastSizes = sizes;
                this.splitInstance.setSizes(sizes);
                this.$el.dispatchEvent(new CustomEvent('splitter-resized', { detail: { sizes }, bubbles: true }));
            }
        },
    }"
    x-init="applyStaticSizes(); initializeSplitter()"
    @splitter-init.window="initializeSplitter()"
    @splitter-set-sizes.window="setSizes($event.detail?.sizes)"
    @splitter-set-direction.window="
        const d = $event.detail?.direction;
        if ((d === 'horizontal' || d === 'vertical') && d !== direction) {
            direction = d;
            $nextTick(() => initializeSplitter());
        }
    "
    :class="direction === 'vertical' ? 'flex-col' : ''"
    {{ $attributes->twMerge($baseClasses) }}
    wire:ignore.self
>
    {{ $slot }}
</div>

@once
    <style>
        /*
         * Gutter: a wide invisible hit zone with a hairline painted in the
         * middle. The gutter element is transparent and sized to the gutterSize
         * prop (Split.js relies on that for pane math); the visible line is a
         * centered ::after that grows on hover/drag via transform (paint-only,
         * so Split.js never sees a size delta). ::before extends the clickable
         * area a few px past the gutter on each side.
         *
         * Colors use theme tokens, so the seam adapts to dark mode for free.
         */
        .gutter {
            position: relative;
            z-index: 5;
            flex-shrink: 0;
            background: transparent;
        }
        .gutter::before,
        .gutter::after {
            content: '';
            position: absolute;
        }
        .gutter::before {
            pointer-events: auto;
        }
        .gutter::after {
            pointer-events: none;
            background-color: var(--border);
            transform-origin: center center;
            transition: background-color 150ms ease, transform 150ms ease;
        }

        /* Horizontal: a vertical seam between side-by-side panes. */
        .gutter.gutter-horizontal {
            cursor: col-resize;
            width: {{ (int) $gutterSize }}px;
        }
        .gutter.gutter-horizontal::before {
            top: 0; bottom: 0; left: -6px; right: -6px;
        }
        .gutter.gutter-horizontal::after {
            top: 0; bottom: 0; left: 50%; width: 1px;
            transform: translateX(-50%);
        }
        .gutter.gutter-horizontal:hover::after {
            transform: translateX(-50%) scaleX(3);
            background-color: var(--muted-foreground);
        }
        .gutter.gutter-horizontal.gutter-dragging::after {
            transform: translateX(-50%) scaleX(3);
            background-color: var(--primary);
        }

        /* Vertical: a horizontal seam between stacked panes. */
        .gutter.gutter-vertical {
            cursor: row-resize;
            height: {{ (int) $gutterSize }}px;
        }
        .gutter.gutter-vertical::before {
            left: 0; right: 0; top: -6px; bottom: -6px;
        }
        .gutter.gutter-vertical::after {
            left: 0; right: 0; top: 50%; height: 1px;
            transform: translateY(-50%);
        }
        .gutter.gutter-vertical:hover::after {
            transform: translateY(-50%) scaleY(3);
            background-color: var(--muted-foreground);
        }
        .gutter.gutter-vertical.gutter-dragging::after {
            transform: translateY(-50%) scaleY(3);
            background-color: var(--primary);
        }

        /* While dragging, suppress text selection and hold the resize cursor
           even when the pointer briefly leaves the gutter. */
        .gutter-dragging-body { user-select: none; cursor: col-resize; }
        .gutter-dragging-body.gutter-dragging-vertical { cursor: row-resize; }
    </style>
@endonce
