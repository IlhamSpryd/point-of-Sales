@props([
    'disabled' => false,
])

@php
    $name = $attributes->get('name');
    $baseClasses = 'form-input block w-full px-4 py-2.5 bg-white border rounded-xl shadow-sm text-[#37352F] sm:text-sm placeholder-[#9B9A97] focus:outline-none focus:ring-2 transition-all duration-200';
    
    if ($name && $errors->has($name)) {
        $classes = $baseClasses . ' border-rose-300 focus:ring-rose-500 focus:border-rose-500 text-rose-900 placeholder-rose-300';
    } else {
        $classes = $baseClasses . ' border-[#E9E9E7] hover:border-[#C4C3C0] focus:ring-[#37352F] focus:border-[#37352F]';
    }
@endphp

<input {{ $disabled ? 'disabled' : '' }} {{ $attributes->merge(['class' => $classes]) }}>
