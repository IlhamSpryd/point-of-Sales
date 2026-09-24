@props([
    'disabled' => false,
])

@php
    $name = $attributes->get('name');
    $baseClasses = 'form-input block w-full px-4 py-2.5 bg-white border rounded-xl shadow-sm text-yovel-ink sm:text-sm placeholder-yovel-muted focus:outline-none focus:ring-2 transition-all duration-200';
    
    if ($name && $errors->has($name)) {
        $classes = $baseClasses . ' border-rose-300 focus:ring-rose-500 focus:border-rose-500 text-rose-900 placeholder-rose-300';
    } else {
        $classes = $baseClasses . ' border-yovel-border hover:border-primary-300 focus:ring-yovel-ink focus:border-yovel-ink';
    }
@endphp

<input {{ $disabled ? 'disabled' : '' }} {{ $attributes->merge(['class' => $classes]) }}>
