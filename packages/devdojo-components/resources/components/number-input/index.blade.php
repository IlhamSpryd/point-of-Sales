@props([
    'label' => null,
    'min' => null,
    'max' => null,
    'step' => 1,
    'value' => null,
    'placeholder' => null,
    'disabled' => false,
])

@php
    $xModel = $attributes->get('x-model') ?? null;
    $errorKey = $attributes->whereStartsWith('wire:model')->first() ?? $xModel;
@endphp

<div class="w-full">
    @if ($label)
        <x-components.label class="mb-1.5">{{ $label }}</x-components.label>
    @endif

    <div
        x-data="{
            stepBy(direction) {
                const input = $refs.input;
                if (input.disabled) { return; }
                direction > 0 ? input.stepUp() : input.stepDown();
                // Native stepUp/stepDown clamps to min/max but fires no events,
                // so Livewire/Alpine bindings need a nudge.
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }"
        {{ $attributes->only('class')->twMerge('flex h-9 w-full items-stretch overflow-hidden rounded-medium border border-input bg-background shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-primary/10 dark:bg-input/30 '.($errorKey && $errors->has($errorKey) ? 'border-destructive ring-destructive/20' : '')) }}>
        <button type="button" @click="stepBy(-1)" tabindex="-1" aria-label="Decrease" @disabled($disabled)
            class="flex w-9 shrink-0 items-center justify-center border-r border-input text-foreground/50 outline-none transition hover:bg-secondary hover:text-foreground disabled:pointer-events-none disabled:opacity-40">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14" /></svg>
        </button>
        <input
            x-ref="input"
            type="number"
            inputmode="decimal"
            @if($min !== null) min="{{ $min }}" @endif
            @if($max !== null) max="{{ $max }}" @endif
            @if($step !== null) step="{{ $step }}" @endif
            @if($value !== null) value="{{ $value }}" @endif
            @if($placeholder !== null) placeholder="{{ $placeholder }}" @endif
            @disabled($disabled)
            @if($errorKey && $errors->has($errorKey)) aria-invalid="true" @endif
            {{ $attributes->withoutTwMergeClasses()->except(['class']) }}
            class="w-full min-w-0 flex-1 border-none bg-transparent px-2 text-center text-base text-foreground outline-none [appearance:textfield] selection:bg-primary selection:text-primary-foreground placeholder:text-foreground/30 focus:outline-none focus:ring-0 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
        />
        <button type="button" @click="stepBy(1)" tabindex="-1" aria-label="Increase" @disabled($disabled)
            class="flex w-9 shrink-0 items-center justify-center border-l border-input text-foreground/50 outline-none transition hover:bg-secondary hover:text-foreground disabled:pointer-events-none disabled:opacity-40">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14M12 5v14" /></svg>
        </button>
    </div>

    @if ($errorKey && $errors->has($errorKey))
        <p class="text-destructive mt-1.5 text-sm">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
