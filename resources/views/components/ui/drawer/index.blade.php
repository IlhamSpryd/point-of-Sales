@props([
    'position' => 'right',
    'parent' => null,
    'open' => false,
    'title' => null,
    'description' => null,
    'header' => null,
    'footer' => null,
    'content' => null,
    'maxWidth' => 'md',
    'zIndex' => '50',
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $attributes = $attributes->whereDoesntStartWith('wire:model');
    $openExpression = $wireModel ? "\$wire.entangle('{$wireModel}')" : ($open ? 'true' : 'false');

    $maxWidthClass = match ($maxWidth) {
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        'full' => 'max-w-full',
        default => 'max-w-md',
    };

    $drawerId = $attributes->get('id') ?? uniqid('dd-drawer-');
    $titleId = $drawerId.'-title';
@endphp

<div
    x-data="{
        open: {!! $openExpression !!},
        drawerId: @js($drawerId),
        closeDrawer() {
            this.open = false;
        }
    }"
    {{ $attributes->except('id') }}
    x-init="
        $watch('open', function (value) {
            if (value) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        });
    "
    @keydown.escape.window="if (open) open = false"
    @open-drawer.window="if ($event.detail === drawerId || ($event.detail && $event.detail.id === drawerId)) open = true"
    @close-drawer.window="if ($event.detail === drawerId || ($event.detail && $event.detail.id === drawerId)) open = false"
>
    @unless ($wireModel)
        @if (isset($trigger))
            <div @click="open = true">{{ $trigger }}</div>
        @endif
    @endunless

    <template x-teleport="body">
        <div id="{{ $drawerId }}" x-show="open" x-cloak class="fixed inset-0 w-screen h-dvh" style="z-index: {{ $zIndex }}">
            <div
                x-show="open"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="open = false"
                class="fixed inset-0 z-40 bg-black/45 backdrop-blur-[2px]"
                aria-hidden="true"
            ></div>

            <div
                x-show="open"
                x-trap.inert.noscroll="open"
                x-gsap-drawer="open"
                role="dialog"
                aria-modal="true"
                @if(isset($header) || $title) aria-labelledby="{{ $titleId }}" @endif
                x-transition:enter="transform transition ease-out duration-250"
                x-transition:enter-start="@if ($position == 'right') translate-x-full @elseif($position == 'left') -translate-x-full @else translate-y-full @endif"
                x-transition:enter-end="translate-x-0 translate-y-0"
                x-transition:leave="transform transition ease-in duration-200"
                x-transition:leave-start="translate-x-0 translate-y-0"
                x-transition:leave-end="@if ($position == 'right') translate-x-full @elseif($position == 'left') -translate-x-full @else translate-y-full @endif"
                @class([
                    "fixed inset-y-0 z-50 w-full {$maxWidthClass} flex flex-col bg-card text-card-foreground border-border shadow-2xl",
                    'right-0 border-l' => $position == 'right',
                    'left-0 border-r' => $position == 'left',
                ])
            >
                <div class="flex items-center justify-between gap-3 px-6 py-4 border-b border-border shrink-0">
                    <div>
                        @if (isset($header) || $title)
                            <h2 id="{{ $titleId }}" class="text-base font-bold text-foreground tracking-tight">
                                {{ $header ?? $title }}
                            </h2>
                        @endif
                        @if ($description)
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ $description }}</p>
                        @endif
                    </div>
                    <button
                        @click="open = false"
                        type="button"
                        aria-label="Tutup panel"
                        class="inline-flex size-8 items-center justify-center rounded-lg text-foreground/50 hover:bg-secondary hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
                    >
                        <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 custom-scrollbar">
                    {{ $content ?? $slot }}
                </div>

                @isset($footer)
                    <div class="px-6 py-4 border-t border-border bg-secondary/40 shrink-0 flex items-center justify-end gap-2.5">
                        {{ $footer }}
                    </div>
                @endisset
            </div>
        </div>
    </template>
</div>
