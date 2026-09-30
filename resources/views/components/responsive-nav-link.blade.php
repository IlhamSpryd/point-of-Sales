@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-apeiron-ink text-start text-base font-medium text-apeiron-ink bg-apeiron-bg focus:outline-none transition duration-200 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-apeiron-muted hover:text-apeiron-ink hover:bg-apeiron-bg hover:border-primary-300 focus:outline-none focus:text-apeiron-ink focus:bg-apeiron-bg focus:border-primary-300 transition duration-200 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
