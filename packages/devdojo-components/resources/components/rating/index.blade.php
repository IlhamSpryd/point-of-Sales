@props([
    'value' => 0,
    'max' => 5,
    'readonly' => false,
    'name' => null,
    'size' => 'md',
])

@php
    $wireModel = $attributes->get('wire:model')
        ?? $attributes->get('wire:model.live')
        ?? $attributes->get('wire:model.blur')
        ?? $attributes->get('wire:model.lazy');

    $star = match ($size) {
        'sm' => 'size-5',
        'lg' => 'size-8',
        default => 'size-6',
    };
@endphp

<div
    x-data="{
        rating: {{ (int) $value }},
        hover: 0,
        readonly: {{ $readonly ? 'true' : 'false' }},
        max: {{ (int) $max }},
        set(v) {
            if (this.readonly) return;
            this.rating = (this.rating === v) ? 0 : v;
            this.$refs.input.value = this.rating;
            this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
            @if ($wireModel) $wire.set(@js($wireModel), this.rating); @endif
        }
    }"
    role="radiogroup"
    {{ $attributes->whereDoesntStartWith('wire:model')->twMerge('inline-flex items-center gap-0.5') }}
>
    <input type="hidden" @if ($name) name="{{ $name }}" @endif x-ref="input" :value="rating" />

    <template x-for="star in max" :key="star">
        <button
            type="button"
            @click="set(star)"
            @mouseenter="if (! readonly) hover = star"
            @mouseleave="hover = 0"
            :disabled="readonly"
            :aria-checked="rating >= star"
            class="cursor-pointer p-0.5 transition-colors disabled:cursor-default"
            :class="(hover || rating) >= star ? 'text-amber-400' : 'text-foreground/25'"
        >
            <svg class="{{ $star }}" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" /></svg>
        </button>
    </template>
</div>
