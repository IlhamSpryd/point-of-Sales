@props([
    'label' => null,
    'description' => null,
    'checked' => false,
    'disabled' => false,
    'size' => 'md',
])

@php
    switch ($size ?? 'md') {
        case 'sm':
            $trackClasses = 'h-5 w-9';
            $thumbClasses = 'h-4 w-4 group-data-checked:translate-x-4';
            break;
        case 'lg':
            $trackClasses = 'h-7 w-13';
            $thumbClasses = 'h-6 w-6 group-data-checked:translate-x-6';
            break;
        case 'md':
        default:
            $trackClasses = 'h-6 w-11';
            $thumbClasses = 'h-5 w-5 group-data-checked:translate-x-5';
            break;
    }

    $id = ($attributes->get('id')) ? $attributes->get('id') : uniqid('toggle-');
@endphp

<label for="{{ $id }}" {{ $attributes->only(['class'])->twMerge(\Illuminate\Support\Arr::toCssClasses([
    'group inline-flex gap-x-3',
    'cursor-pointer' => ! $disabled,
    'cursor-not-allowed opacity-60' => $disabled,
    'items-start' => $description != null,
    'items-center' => $description == null,
])) }}
    x-data="{ checked: {{ $checked ? 'true' : 'false' }} }"
    x-init="$nextTick(() => { checked = $refs.toggle.checked });"
    x-bind:data-checked="checked ? true : null">
    <input
        type="checkbox"
        role="switch"
        id="{{ $id }}"
        class="peer sr-only"
        x-ref="toggle"
        x-on:change="checked = $event.target.checked"
        aria-checked="{{ $checked ? 'true' : 'false' }}"
        x-bind:aria-checked="checked ? 'true' : 'false'"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->withoutTwMergeClasses()->except(['class']) }}
    />

    <span {{ $attributes->twMergeFor('track', $trackClasses . ' relative inline-flex flex-shrink-0 items-center rounded-full bg-foreground/15 transition-colors duration-200 ease-out group-data-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/20 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background') }}>
        <span {{ $attributes->twMergeFor('thumb', $thumbClasses . ' pointer-events-none ml-0.5 inline-block transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-out') }}></span>
    </span>

    @if ($label || $description)
        <span class="flex flex-col">
            @if ($label)
                <span {{ $attributes->twMergeFor('label', 'text-sm font-medium select-none text-foreground/90') }}>
                    {{ $label }}
                </span>
            @endif

            @if ($description)
                <span {{ $attributes->twMergeFor('description', 'text-sm text-foreground/50') }}>
                    {{ $description }}
                </span>
            @endif
        </span>
    @endif
</label>
