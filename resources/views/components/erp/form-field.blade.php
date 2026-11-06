@props([
    'label' => null,
    'for' => null,
    'name' => null,
    'required' => false,
    'error' => null,
    'hint' => null,
    'description' => null,
])

@php
    $id = $for ?? $name ?? $attributes->get('id');
    $hintText = $hint ?? $description;
    
    // Resolve error: explicit prop first, then fallback to Blade $errors bag if name is provided
    $resolvedError = $error;
    if (!$resolvedError && $name && isset($errors) && $errors->has($name)) {
        $resolvedError = $errors->first($name);
    }
    
    $hintId = $id ? $id . '-hint' : null;
    $errorId = $id ? $id . '-error' : null;
@endphp

<div {{ $attributes->twMerge('w-full flex flex-col gap-1.5') }}>
    @if ($label)
        <label @if($id) for="{{ $id }}" @endif class="inline-flex items-center gap-1 text-xs sm:text-sm font-semibold text-foreground select-none">
            <span>{{ $label }}</span>
            @if ($required)
                <span class="text-destructive font-bold" aria-hidden="true">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </label>
    @endif

    <div class="relative w-full">
        {{ $slot }}
    </div>

    @if ($hintText && !$resolvedError)
        <p @if($hintId) id="{{ $hintId }}" @endif class="text-xs text-muted-foreground leading-normal">
            {{ $hintText }}
        </p>
    @endif

    @if ($resolvedError)
        <p @if($errorId) id="{{ $errorId }}" @endif class="text-xs font-medium text-destructive leading-normal flex items-center gap-1" role="alert" aria-live="polite">
            <x-ui.icon name="error" size="xs" class="shrink-0 text-destructive" />
            <span>{{ $resolvedError }}</span>
        </p>
    @endif
</div>
