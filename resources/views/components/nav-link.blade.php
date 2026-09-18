@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-[#37352F] text-sm font-medium leading-5 text-[#37352F] focus:outline-none transition duration-200 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-[#787774] hover:text-[#37352F] hover:border-[#C4C3C0] focus:outline-none focus:text-[#37352F] focus:border-[#C4C3C0] transition duration-200 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
