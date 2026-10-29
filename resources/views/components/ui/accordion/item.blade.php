@props([
    'title' => '',
    'open' => false,
    'icon' => null,
])

@php
    $id = \Illuminate\Support\Str::uuid()->toString();
@endphp

<div
    x-data="{ id: '{{ $id }}' }"
    x-init="
        if (@js($open)) {
            if (Array.isArray(activeAccordions)) { activeAccordions.push(id); }
            else { activeAccordions = id; }
        }
    "
    {{ $attributes->twMerge('group/accordion-item') }}
>
    <button
        type="button"
        @click="toggle(id)"
        {{ $attributes->twMergeFor('title', 'flex w-full select-none items-center justify-between gap-3 px-3 py-4 text-left text-sm font-medium text-foreground/80 transition-colors group-hover/accordion-item:text-foreground') }}
    >
        <span>{{ $title }}</span>
        <span
            {{ $attributes->twMergeFor('icon', 'size-3.5 shrink-0 transition-transform duration-200 ease-out') }}
            :class="{ 'rotate-180': isOpen(id) }"
        >
            @if ($icon)
                {!! $icon !!}
            @else
                <svg class="size-full" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
            @endif
        </span>
    </button>

    {{-- Plugin-free height animation via an animatable CSS grid row (0fr → 1fr). --}}
    <div
        class="grid transition-all duration-200 ease-out"
        style="grid-template-rows: 0fr"
        :style="isOpen(id) ? 'grid-template-rows: 1fr' : 'grid-template-rows: 0fr'"
    >
        <div class="min-h-0 overflow-hidden">
            <div {{ $attributes->twMergeFor('content', 'px-3 pb-4 pt-0 text-sm text-foreground/70') }}>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
