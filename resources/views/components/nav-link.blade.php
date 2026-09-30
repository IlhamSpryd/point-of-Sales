@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-apeiron-ink text-sm font-medium leading-5 text-apeiron-ink focus:outline-none transition duration-200 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-apeiron-muted hover:text-apeiron-ink hover:border-primary-300 focus:outline-none focus:text-apeiron-ink focus:border-primary-300 transition duration-200 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
