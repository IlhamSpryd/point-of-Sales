@props([
    'label' => null,
    'description' => null,
    'checked' => false,
    'disabled' => false,
    'clickable' => false,
    'hideRadio' => false,
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'flex gap-x-2 group',
        'select-none' => $clickable,
        'items-start' => $description != null,
        'items-center' => $description == null,
    ]);

    $id = ($attributes->get('id')) ? $attributes->get('id') : uniqid('radio-');
    $containerAttribute = $clickable ? 'label for="' . $id . '"' : 'div';
    $controlAttribute = $clickable ? 'span' : 'label for="' . $id . '"';
    $innerLabelAttribute = $clickable ? 'span' : 'label for="' . $id . '"';
@endphp

<{!! $containerAttribute !!} {{ $attributes->only(['class'])->twMerge($classes) }}>
    {{-- Native radio drives selection: browsers allow only one checked per name group. --}}
    <input
        type="radio"
        id="{{ $id }}"
        class="peer sr-only"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->withoutTwMergeClasses()->except(['class']) }}
    />

    @if (! $hideRadio)
        <{!! $controlAttribute !!} {{ $attributes->twMergeFor('radio', 'h-5 w-5 flex-shrink-0 cursor-pointer rounded-full border border-foreground/20 bg-background flex items-center justify-center transition peer-checked:border-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/20 peer-checked:[&>span]:opacity-100 peer-checked:[&>span]:scale-100') }}>
            <span {{ $attributes->twMergeFor('dot', 'h-2.5 w-2.5 rounded-full bg-primary opacity-0 scale-50 transition duration-150 ease-out') }}></span>
        </{!! $controlAttribute !!}>
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
