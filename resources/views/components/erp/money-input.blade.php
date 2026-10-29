@props([
    'label' => null,
    'currency' => 'Rp',
    'hint' => null,
    'min' => '0',
    'step' => 'any',
])

@php
    $xModel = $attributes->get('x-model') ?? null;
    $errorKey = $attributes->whereStartsWith('wire:model')->first() ?? $xModel ?? $attributes->get('name');
    $hasError = $errorKey && isset($errors) && $errors->has($errorKey);
@endphp

<div class="w-full">
    @if ($label)
        <x-ui.label class="mb-1.5">{{ $label }}</x-ui.label>
    @endif

    <div class="relative flex items-center">
        <span class="pointer-events-none absolute left-3 text-xs font-semibold text-muted-foreground select-none">
            {{ $currency }}
        </span>
        <input
            type="number"
            inputmode="decimal"
            min="{{ $min }}"
            step="{{ $step }}"
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->twMerge('h-9 w-full rounded-medium border border-input bg-background pl-9 pr-3 text-right font-mono tabular-nums text-xs sm:text-sm text-foreground shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/10 disabled:cursor-not-allowed disabled:opacity-50 '.($hasError ? 'border-destructive ring-destructive/20' : '')) }}
        />
    </div>

    @if ($hint && ! $hasError)
        <p class="mt-1 text-[11px] text-muted-foreground">{{ $hint }}</p>
    @endif

    @if ($hasError)
        <p class="mt-1.5 text-xs text-destructive">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
