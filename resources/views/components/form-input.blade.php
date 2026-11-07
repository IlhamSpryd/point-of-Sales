@props([
    'disabled' => false,
])

<input
    {{ $attributes->merge([
        'class' => 'form-input w-full rounded-lg border border-border bg-background text-foreground text-sm px-3 py-2 shadow-xs transition-colors focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed',
    ]) }}
    @disabled($disabled)
/>
