@props([
    'title' => '',
    'description' => null,
    'open' => false,
    'maxWidth' => 'md',
    'id' => null,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $attributes = $attributes->whereDoesntStartWith('wire:model');
    $openExpr = $wireModel ? "\$wire.entangle('{$wireModel}')" : ($open ? 'true' : 'false');

    $panelWidth = match ($maxWidth) {
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        'full' => 'max-w-full',
        default => 'max-w-md',
    };

    $slideId = $id ?? uniqid('slide-over-');
@endphp

<div
    x-data="{
        isOpen: {!! $openExpr !!},
        slideId: @js($slideId),
        close() {
            this.isOpen = false;
        }
    }"
    x-init="
        $watch('isOpen', value => {
            if (value) {
                document.body.classList.add('overflow-hidden');
            } else {
                document.body.classList.remove('overflow-hidden');
            }
        });
    "
    @keydown.escape.window="if (isOpen) close()"
    @open-slide-over.window="if ($event.detail === slideId || ($event.detail && $event.detail.id === slideId)) isOpen = true"
    @close-slide-over.window="if ($event.detail === slideId || ($event.detail && $event.detail.id === slideId)) isOpen = false"
    {{ $attributes }}
>
    @if (isset($trigger))
        <div @click="isOpen = true">
            {{ $trigger }}
        </div>
    @endif

    <template x-teleport="body">
        <div x-show="isOpen" x-cloak class="fixed inset-0 z-50 overflow-hidden" role="dialog" aria-modal="true" :aria-labelledby="slideId + '-title'">
            {{-- Backdrop Overlay --}}
            <div
                x-show="isOpen"
                x-transition:enter="transition-opacity ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="close()"
                class="fixed inset-0 bg-overlay-scrim backdrop-blur-[2px] transition-opacity"
                aria-hidden="true"></div>

            {{-- Slide-Over Panel Container --}}
            <div class="fixed inset-y-0 right-0 pl-10 max-w-full flex">
                <div
                    x-show="isOpen"
                    x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transform transition ease-in-out duration-200 sm:duration-300"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    class="w-screen {{ $panelWidth }} bg-card border-l border-border shadow-2xl flex flex-col justify-between">
                    
                    {{-- Header --}}
                    <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-3 bg-muted">
                        <div>
                            <h3 id="{{ $slideId }}-title" class="text-base font-bold text-foreground tracking-tight">
                                {{ $title }}
                            </h3>
                            @if ($description)
                                <p class="text-xs text-muted-foreground mt-0.5 leading-normal">
                                    {{ $description }}
                                </p>
                            @endif
                        </div>
                        <button
                            type="button"
                            @click="close()"
                            aria-label="Tutup panel"
                            class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted active:scale-95 transition-all cursor-pointer">
                            <span class="material-symbols-rounded text-[20px]" aria-hidden="true">close</span>
                        </button>
                    </div>

                    {{-- Scrollable Content Body --}}
                    <div class="flex-1 overflow-y-auto p-5 sm:p-6 space-y-4">
                        {{ $slot }}
                    </div>

                    {{-- Sticky Action Footer --}}
                    @if (isset($footer))
                        <div class="px-5 py-3.5 border-t border-border bg-muted flex items-center justify-end gap-2.5">
                            {{ $footer }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
