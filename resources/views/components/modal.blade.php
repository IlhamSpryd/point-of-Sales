@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    'sheet' => false, // true = bottom-sheet style on mobile, centered on desktop
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];

$panelPositionClasses = $sheet
    ? 'items-end sm:items-center'
    : 'items-center';

$panelShapeClasses = $sheet
    ? 'rounded-t-[1.75rem] sm:rounded-2xl'
    : 'rounded-2xl';

$panelEnterClasses = $sheet
    ? 'opacity-0 translate-y-full sm:translate-y-8 sm:scale-95'
    : 'opacity-0 translate-y-8 sm:scale-95';

$panelEnterEndClasses = $sheet
    ? 'opacity-100 translate-y-0 sm:scale-100'
    : 'opacity-100 translate-y-0 sm:scale-100';
@endphp

<div
    x-data="{
        show: @js($show),
        focusables() {
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                .filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="$watch('show', value => {
        if (value) {
            document.body.classList.add('overflow-y-hidden');
            {{ $attributes->has('focusable') ? 'setTimeout(() => firstFocusable().focus(), 100)' : '' }}
        } else {
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 overflow-y-auto z-50 flex {{ $panelPositionClasses }} justify-center"
    style="display: {{ $show ? 'flex' : 'none' }};"
>
    {{-- Overlay --}}
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all bg-primary-700/40 backdrop-blur-sm"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    ></div>

    {{-- Panel --}}
    <div
        x-show="show"
        class="relative bg-white {{ $panelShapeClasses }} overflow-hidden shadow-[0_-12px_40px_-10px_rgba(0,0,0,0.12)] sm:shadow-xl border border-yovel-border transform transition-all w-full sm:w-full {{ $maxWidth }} mx-auto"
        x-transition:enter="ease-[cubic-bezier(0.16,1,0.3,1)] duration-300"
        x-transition:enter-start="{{ $panelEnterClasses }}"
        x-transition:enter-end="{{ $panelEnterEndClasses }}"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="{{ $panelEnterEndClasses }}"
        x-transition:leave-end="{{ $panelEnterClasses }}"
    >
        {{ $slot }}
    </div>
</div>
