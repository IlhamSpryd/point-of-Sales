@props([
    'type' => 'text',
    'label' => null,
])

@php
    $xModel = $attributes->get('x-model') ?? null;
    $errorKey = $attributes->whereStartsWith('wire:model')->first() ?? $xModel;

    $classes = \Illuminate\Support\Arr::toCssClasses([
        'text-foreground bg-background placeholder:text-foreground/30 selection:bg-primary selection:text-primary-foreground border-input dark:bg-input/30 h-9 w-full min-w-0 rounded-medium border px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-primary/10 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        'border-destructive ring-destructive/20' => $errorKey && $errors->has($errorKey),
    ]);
@endphp

<div class="w-full">
    @if ($label)
        <x-components.label class="mb-1.5">{{ $label }}</x-components.label>
    @endif

    <input
        type="{{ $type ?? 'text' }}"
        @if($errorKey && $errors->has($errorKey)) aria-invalid="true" @endif
        {{ $attributes->twMerge($classes) }}
    />

    @if ($errorKey && $errors->has($errorKey))
        <p class="text-destructive mt-1.5 text-sm">{{ $errors->first($errorKey) }}</p>
    @endif
</div>
