@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-[#37352F] text-start text-base font-medium text-[#37352F] bg-[#F7F7F5] focus:outline-none transition duration-200 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5] hover:border-[#C4C3C0] focus:outline-none focus:text-[#37352F] focus:bg-[#F7F7F5] focus:border-[#C4C3C0] transition duration-200 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
