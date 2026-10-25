@props([
    'scale' => 100,
    'dotPattern' => false,
    'centerCanvasOnResize' => false,
])

@once
    <style>
        .dd-infinite-canvas {
            width: 100000px;
            height: 100000px;
            will-change: transform;
            /* Prevent the browser's overscroll/bounce while panning. */
            overscroll-behavior: none;
        }

        /* Optional dotted background, themed via tokens for automatic dark mode. */
        .dd-infinite-canvas[data-dots] {
            --dot-bg: var(--background);
            --dot-color: var(--border);
            --dot-size: 2px;
            --dot-space: 16px;
            background:
                linear-gradient(90deg, var(--dot-bg) calc(var(--dot-space) - var(--dot-size)), transparent 1%) center / var(--dot-space) var(--dot-space),
                linear-gradient(var(--dot-bg) calc(var(--dot-space) - var(--dot-size)), transparent 1%) center / var(--dot-space) var(--dot-space),
                var(--dot-color);
        }
    </style>
@endonce

<div
    x-data="{
        pos: { x: -50000, y: -50000 },
        scale: {{ $scale / 100 }},
        vx: 0,
        vy: 0,
        speed: 0.5,
        friction: 0.7,
        rafId: null,

        init() {
            this.centerCanvas();
            this.startLoop();

            this.$el.addEventListener('wheel', (e) => {
                e.preventDefault();
                const { dx, dy } = this.normalizeWheel(e);
                this.applyDelta(dx, dy);
            }, { passive: false });

            this.$watch('scale', (value) => {
                window.dispatchEvent(new CustomEvent('canvas-zoom', { detail: { scale: value } }));
            });

            window.addEventListener('message', (e) => {
                if (e?.data?.type === 'canvas-scroll') {
                    this.applyDelta(e.data.deltaX, e.data.deltaY);
                }
            });

            @if ($centerCanvasOnResize)
                window.addEventListener('resize', () => {
                    this.centerCanvas();
                });
            @endif
        },

        centerCanvas() {
            const posX = -(50000 - this.$el.clientWidth / 2);
            const posY = -(50000 - this.$el.clientHeight / 2);
            this.positionCanvas(posX, posY);
        },

        positionCanvas(x, y) {
            if (x != null) { this.pos.x = x; }
            if (y != null) { this.pos.y = y; }
            this.commitTransform();
        },

        applyDelta(dx, dy) {
            this.vx += dx * this.speed;
            this.vy += dy * this.speed;
        },

        startLoop() {
            const step = () => {
                if (Math.abs(this.vx) > 0.01 || Math.abs(this.vy) > 0.01) {
                    this.pos.x -= this.vx;
                    this.pos.y -= this.vy;
                    this.vx *= this.friction;
                    this.vy *= this.friction;
                    this.commitTransform();
                }
                this.rafId = requestAnimationFrame(step);
            };
            step();
        },

        commitTransform() {
            this.$refs.canvas.style.transform =
                `translate(${this.pos.x}px, ${this.pos.y}px) scale(${this.scale})`;
        },

        normalizeWheel(e) {
            let dx = e.deltaX, dy = e.deltaY;
            if (e.deltaMode === 1) { // lines → pixels
                dx *= 16;
                dy *= 16;
            }
            return { dx, dy };
        },
    }"
    @canvas-zoom-in.window="scale += 0.05; commitTransform()"
    @canvas-zoom-out.window="scale -= 0.05; commitTransform()"
    @canvas-center.window="centerCanvas()"
    @canvas-position.window="positionCanvas($event.detail.x, $event.detail.y)"
    {{ $attributes->twMerge('relative h-full w-full overflow-hidden') }}
>
    <div x-ref="canvas" class="dd-infinite-canvas absolute inset-0 h-full w-full origin-center" @if ($dotPattern) data-dots @endif>
        <div class="absolute left-1/2 top-1/2 flex h-auto w-full -translate-x-1/2 -translate-y-1/2 transform items-center justify-center">
            {{ $slot }}
        </div>
    </div>
</div>
