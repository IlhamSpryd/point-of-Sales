@props([
    'label' => null,
    'placeholder' => null,
])

@php
    $xModel = $attributes->get('x-model') ?? null;
    $errorKey = $attributes->whereStartsWith('wire:model')->first() ?? $xModel;

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'text-foreground bg-background border-input dark:bg-input/30 h-9 w-full min-w-0 cursor-pointer appearance-none rounded-medium border pl-3 pr-9 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-primary/10 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        'border-destructive ring-destructive/20' => $errorKey && $errors->has($errorKey),
    ]);
@endphp

<div class="w-full">
    @if ($label)
        <x-components.label class="mb-1.5">{{ $label }}</x-components.label>
    @endif

    <div class="relative">
        <select
            @if ($errorKey && $errors->has($errorKey)) aria-invalid="true" @endif
            {{ $attributes->twMerge($classes) }}
        >
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            {{ $slot }}
        </select>

        <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-foreground/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
    </div>

    @if ($errorKey && $errors->has($errorKey))
        <p class="text-destructive mt-1.5 text-sm">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
