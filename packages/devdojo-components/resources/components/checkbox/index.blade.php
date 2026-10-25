@props([
    'label' => null,
    'description' => null,
    'checked' => false,
    'disabled' => false,
    'clickable' => false,
    'icon' => null,
    'hideCheckbox' => false,
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'flex gap-x-2 group',
        'select-none' => $clickable,
        'items-start' => $description != null,
        'items-center' => $description == null,
    ]);

    $id = ($attributes->get('id')) ? $attributes->get('id') : uniqid('checkbox-');
    $containerAttribute = $clickable ? 'label for="' . $id . '"' : 'div';
    $innerLabelAttribute = $clickable ? 'span' : 'label for="' . $id . '"';
@endphp

<{!! $containerAttribute !!} x-data="{ checked: {{ $checked ? 'true' : 'false' }} }"
    x-init="
        $nextTick(() => { checked = $refs.checkbox.checked });
        const checkFunction = function(){ checked = true };
        $refs.checkbox.check = checkFunction;
        const unCheckFunction = function(){ checked = false };
        $refs.checkbox.uncheck = unCheckFunction;
    " x-bind:data-checked="checked ? true : null" {{ $attributes->only(['class'])->twMerge($classes) }}>
    <input
        type="checkbox"
        id="{{ $id }}"
        class="peer sr-only"
        x-bind:aria-checked="checked"
        x-on:change="checked = $event.target.checked"
        x-ref="checkbox"
        @check="checked = true"
        @uncheck="checked = false"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->withoutTwMergeClasses()->except(['class']) }}
    />

    @if (! $hideCheckbox)
        <span @if (! $clickable && ! $disabled) @click="$refs.checkbox.checked = !$refs.checkbox.checked; $refs.checkbox.dispatchEvent(new Event('change')); $refs.checkbox.focus()" @endif
            {{ $attributes->twMergeFor('checkbox', 'h-5 w-5 flex-shrink-0 cursor-pointer rounded-small border border-foreground/20 bg-background text-primary-foreground flex items-center justify-center transition group-data-checked:border-primary group-data-checked:bg-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/20') }}>
            @if ($icon)
                <x-dynamic-component :component="$icon" {{ $attributes->twMergeFor('checkmark', 'h-3.5 w-3.5 stroke-current duration-150 ease-out opacity-0 scale-50 group-data-checked:opacity-100 group-data-checked:scale-100') }} />
            @else
                <svg {{ $attributes->twMergeFor('checkmark', 'h-3.5 w-3.5 stroke-current duration-150 ease-out opacity-0 scale-50 group-data-checked:opacity-100 group-data-checked:scale-100') }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="3"><path d="M20 6 9 17l-5-5" /></svg>
            @endif
        </span>
    @endif

    @if ($slot ?? false)
        {{ $slot }}
    @endif

    @if ($label || $description)
        <span class="flex flex-col peer-disabled:opacity-60">
            @if ($label)
                <{!! $innerLabelAttribute !!} {{ $attributes->twMergeFor('label', 'text-sm font-medium select-none text-foreground/90 cursor-pointer') }}>
                    {{ $label }}
                </{!! $innerLabelAttribute !!}>
            @endif

            @if ($description)
                <span {{ $attributes->twMergeFor('description', 'text-sm text-foreground/50') }}>
                    {{ $description }}
                </span>
            @endif
        </span>
    @endif
</{!! $containerAttribute !!}>
