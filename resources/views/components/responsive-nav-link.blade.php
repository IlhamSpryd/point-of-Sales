@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-yovel-ink text-start text-base font-medium text-yovel-ink bg-yovel-bg focus:outline-none transition duration-200 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-yovel-muted hover:text-yovel-ink hover:bg-yovel-bg hover:border-primary-300 focus:outline-none focus:text-yovel-ink focus:bg-yovel-bg focus:border-primary-300 transition duration-200 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
