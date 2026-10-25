@props([
    'label' => null,
    'min' => 0,
    'max' => 100,
    'step' => 1,
    'showValue' => false,
])

@php
    $initial = $attributes->get('value') ?? $min;
    $sliderClasses = 'w-full cursor-pointer accent-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

@if ($label || $showValue)
    <div class="w-full" @if ($showValue) x-data="{ val: '{{ $initial }}' }" @endif>
        <div class="mb-2 flex items-center justify-between gap-2">
            @if ($label)
                <x-components.label>{{ $label }}</x-components.label>
            @endif
            @if ($showValue)
                <span class="text-sm tabular-nums text-foreground/60" x-text="val"></span>
            @endif
        </div>

        <input
            type="range"
            min="{{ $min }}"
            max="{{ $max }}"
            step="{{ $step }}"
            @if ($showValue) x-on:input="val = $event.target.value" @endif
            {{ $attributes->twMerge($sliderClasses) }}
        />
    </div>
@else
    <input
        type="range"
        min="{{ $min }}"
        max="{{ $max }}"
        step="{{ $step }}"
        {{ $attributes->twMerge($sliderClasses) }}
    />
@endif
