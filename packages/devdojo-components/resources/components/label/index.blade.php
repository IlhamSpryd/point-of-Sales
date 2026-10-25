@props([
    'for' => '',
])

<label @if($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'inline-flex items-center gap-x-3']) }}>
    <span class="text-sm font-medium leading-6 text-foreground">
        {{ $slot }}
    </span>
</label>
