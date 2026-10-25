@props([
    'type' => 'text',
    'label' => null,
])

@php
    $xModel = $attributes->get('x-model') ?? null;
    $errorKey = $attributes->whereStartsWith('wire:model')->first() ?? $xModel ?? $attributes->get('name');

    $hasError = $errorKey && isset($errors) && $errors->has($errorKey);

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'text-foreground bg-background placeholder:text-foreground/30 selection:bg-primary selection:text-primary-foreground border-input dark:bg-input/30 h-9 w-full min-w-0 rounded-medium border px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-primary/10 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        'border-destructive ring-destructive/20' => $hasError,
    ]);
@endphp

<div class="w-full">
    @if ($label)
        <x-ui.label class="mb-1.5">{{ $label }}</x-ui.label>
    @endif

    <input
        type="{{ $type ?? 'text' }}"
        @if($hasError) aria-invalid="true" @endif
        {{ $attributes->twMerge($classes) }}
    />

    @if ($hasError)
        <p class="text-destructive mt-1.5 text-sm">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
