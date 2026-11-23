@props([
    'open' => false,
    'name' => null,
    'show' => false,
    'title' => null,
    'description' => null,
    'closeButton' => true,
    'align' => 'center',
    'maxWidth' => 'lg',
    'sheet' => false,
    'zIndex' => 60,
    'onClose' => null,
    'role' => 'dialog',
    'instant' => false,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    // Strip wire:model so it isn't spread onto the (non-input) panel div.
    $attributes = $attributes->whereDoesntStartWith('wire:model');
    $cleanWireModel = ($wireModel && preg_match('/^[a-zA-Z0-9_.]+$/', (string) $wireModel)) ? (string) $wireModel : null;
    $initialOpenState = (bool) ($open || $show);
    $safeOnClose = ($onClose && preg_match('/^\$wire\.[a-zA-Z0-9_]+(?:\([\'"a-zA-Z0-9_.,\s\-]*\))?;?$/', trim((string) $onClose)))
        ? rtrim(trim((string) $onClose), ';') . ';'
        : null;

    $maxWidthClass = match ($maxWidth) {
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        '4xl' => 'sm:max-w-4xl',
        '5xl' => 'sm:max-w-5xl',
        '6xl' => 'sm:max-w-6xl',
        '7xl' => 'sm:max-w-7xl',
        'full' => 'sm:max-w-full',
        default => 'sm:max-w-lg',
    };

    $alignClasses = $sheet
        ? 'items-end sm:items-center justify-center p-0 sm:p-4'
        : ($align === 'top' ? 'items-start justify-center pt-[8vh] p-4' : 'items-center justify-center p-4');

    $panelRadiusClasses = $sheet
        ? 'rounded-t-[1.75rem] sm:rounded-2xl pb-safe'
        : 'rounded-2xl';

    $modalId = $attributes->get('id') ?? $name ?? ($wireModel ? 'dd-modal-'.$wireModel : uniqid('dd-modal-'));
    $titleId = $modalId.'-title';
    $descId = $modalId.'-desc';
@endphp

<div
    x-data="{
        modalOpen: @if($cleanWireModel) $wire.entangle(@js($cleanWireModel), true) @else @js($initialOpenState) @endif,
        modalId: @js($modalId),
        closeModal() {
            this.modalOpen = false;
            @if ($safeOnClose)
                {!! $safeOnClose !!}
            @endif
        }
    }"
    x-modelable="modalOpen"
    x-init="
        $watch('modalOpen', function (value) {
            if (value) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        });
        if (typeof $cleanup === 'function') {
            $cleanup(function () {
                document.body.style.overflow = '';
            });
        }
    "
    class="relative h-auto w-auto"
    @keydown.escape.window="if (modalOpen) { closeModal() }"
    @open-modal.window="if ($event.detail === modalId || ($event.detail && $event.detail.id === modalId)) modalOpen = true"
    @close-modal.window="if ($event.detail === modalId || ($event.detail && $event.detail.id === modalId)) { closeModal() }"
    @close="closeModal()"
>
    @unless ($wireModel || $name)
        @if (isset($trigger))
            <div @click="modalOpen = true">{{ $trigger }}</div>
        @endif
    @endunless

    <template x-teleport="body">
        <div
            id="{{ $modalId }}"
            x-show="modalOpen"
            x-cloak
            class="fixed inset-0 flex h-dvh w-screen items-center justify-center"
            style="z-index: {{ $zIndex }}"
        >
            <div
                x-show="modalOpen"
                @if ($instant)
                    {{-- R-007 / WCAG AAA: mode kasir — tanpa animasi agar tidak menghambat alur transaksi. --}}
                    x-transition:enter=""
                    x-transition:enter-start="opacity-100"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave=""
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-100"
                @else
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                @endif
                class="absolute inset-0 h-full w-full bg-overlay-scrim backdrop-blur-[2px]"
                @click="closeModal()"
                aria-hidden="true"
            ></div>

            <div
                x-show="modalOpen"
                x-trap.inert.noscroll="modalOpen"
                x-gsap-modal="modalOpen"
                @if ($instant)
                    x-transition:enter=""
                    x-transition:enter-start="opacity-100"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave=""
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-100"
                @else
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95"
                @endif
                class="relative z-10 flex h-full w-full overflow-y-auto {{ $alignClasses }}"
            >
                <div
                    role="{{ $role }}"
                    aria-modal="true"
                    @if(isset($header) || $title) aria-labelledby="{{ $titleId }}" @endif
                    @if($description) aria-describedby="{{ $descId }}" @endif
                    {{ $attributes->withoutTwMergeClasses()->except('id')->twMerge("relative w-full {$maxWidthClass} {$panelRadiusClasses} bg-popover text-popover-foreground px-6 py-5 sm:px-7 sm:py-6 border border-border-strong shadow-dialog max-h-[92dvh] overflow-y-auto custom-scrollbar") }}
                >
                    @if (isset($header) || $title)
                        <div class="pr-8 mb-4">
                            <h2 id="{{ $titleId }}" {{ $attributes->twMergeFor('header', 'text-base sm:text-lg font-bold text-foreground tracking-tight') }}>
                                {{ $header ?? $title }}
                            </h2>
                            @if ($description)
                                <p id="{{ $descId }}" class="mt-1 text-xs text-muted-foreground">{{ $description }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="min-h-0 flex-1">
                        {{ $content ?? $slot }}
                    </div>

                    @isset($footer)
                        <div {{ $attributes->twMergeFor('footer', 'mt-6 pt-4 border-t border-border-strong flex w-full flex-wrap items-center justify-end gap-2.5') }}>
                            {{ $footer }}
                        </div>
                    @endisset

                    @if ($closeButton ?? true)
                        <div class="absolute right-3.5 top-3.5 z-50">
                            <button
                                class="inline-flex size-8 items-center justify-center rounded-lg bg-transparent text-muted-foreground transition hover:bg-secondary hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
                                @click="closeModal()"
                                type="button"
                                aria-label="Close dialog"
                            >
                                <span class="sr-only">Close</span>
                                <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
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
