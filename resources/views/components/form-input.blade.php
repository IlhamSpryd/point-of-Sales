@props([
    'disabled' => false,
])

@php
    $name = $attributes->get('name');
    $baseClasses = 'form-input block w-full px-4 py-2 bg-white border border-zinc-200 rounded-lg shadow-sm text-zinc-900 sm:text-sm placeholder-zinc-400 focus:outline-none focus:ring-1 transition-colors';
    
    if ($name && $errors->has($name)) {
        $classes = $baseClasses . ' border-red-500 focus:ring-red-500 focus:border-red-500 text-red-900 placeholder-red-300';
    } else {
        $classes = $baseClasses . ' hover:border-zinc-300 focus:ring-zinc-900 focus:border-zinc-900';
    }
@endphp

<input {{ $disabled ? 'disabled' : '' }} {{ $attributes->merge(['class' => $classes]) }}>
