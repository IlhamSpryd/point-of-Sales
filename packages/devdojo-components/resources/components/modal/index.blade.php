@props([
    'open' => false,
    'closeButton' => true,
    'align' => 'center',
    'zIndex' => 50,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    // Strip wire:model so it isn't spread onto the (non-input) panel div.
    $attributes = $attributes->whereDoesntStartWith('wire:model');
    $openExpression = $wireModel ? "\$wire.entangle('{$wireModel}')" : ($open ? 'true' : 'false');
    $alignClasses = $align === 'top' ? 'items-start justify-center pt-[12vh] p-4' : 'items-center justify-center p-4';
    // A stable id for the header heading so the dialog can reference it via aria-labelledby.
    $titleId = ($attributes->get('id') ? $attributes->get('id') : uniqid('dd-modal-')).'-title';
@endphp

<div x-data="{ modalOpen: {!! $openExpression !!} }" x-init="$watch('modalOpen', function (value) {
        if (value) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'auto';
        }
    });" class="relative h-auto w-auto" @keydown.escape.window="modalOpen = false">
    @unless ($wireModel)
        @if (isset($trigger))
            <div @click="modalOpen = true">{{ $trigger }}</div>
        @else
            <div @click="modalOpen = true">
                @if (empty(trim($slot)))
                    <x-components.button variant="outline">Open</x-components.button>
                @else
                    {!! $slot !!}
                @endif
            </div>
        @endif
    @endunless

    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak class="fixed left-0 top-0 flex h-dvh w-screen items-center justify-center" @open-modal.window="if ($event.detail.id === $el.id) modalOpen = true" @close-modal.window="if ($event.detail.id === $el.id) modalOpen = false" {{ $attributes->withoutTwMergeClasses()->only('id') }} style="z-index: {{ $zIndex }}">
            <div x-show="modalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="absolute inset-0 h-full w-full bg-black/55 backdrop-blur-sm" @click="modalOpen = false"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95" class="flex h-full w-full {{ $alignClasses }}">
                <div role="dialog" aria-modal="true" @isset($header) aria-labelledby="{{ $titleId }}" @endisset {{ $attributes->twMerge('relative w-full bg-card text-card-foreground px-7 py-6 border border-border shadow-pop sm:max-w-lg rounded-large') }}>
                    @isset($header)
                        <h2 id="{{ $titleId }}" {{ $attributes->twMergeFor('header', '-translate-y-1.5 text-lg font-medium mb-2') }}>{{ $header }}</h2>
                    @endisset
                    {{ $content ?? '' }}
                    @isset($footer)
                        <div {{ $attributes->twMergeFor('footer', 'mt-5 flex w-full translate-y-1.5 items-center justify-end gap-2') }}>{{ $footer }}</div>
                    @endisset
                    @if ($closeButton ?? true)
                        <div class="absolute right-0 top-0 z-50 hidden pr-4 pt-4 sm:block">
                            <button class="rounded-small bg-transparent p-1 text-foreground/30 transition hover:bg-secondary hover:text-foreground/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-foreground/20" @click="modalOpen = false" type="button">
                                <span class="sr-only">Close</span>
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
