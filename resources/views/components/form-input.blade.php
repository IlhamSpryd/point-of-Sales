@props([
    'disabled' => false,
])

@php
    $name = $attributes->get('name');
    $baseClasses = 'form-input block w-full px-3.5 py-2 bg-white dark:bg-zinc-900 border rounded-lg text-apeiron-ink dark:text-zinc-100 sm:text-sm placeholder-zinc-400 focus:outline-none transition-colors duration-150 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed';
    
    if ($name && isset($errors) && $errors->has($name)) {
        $classes = $baseClasses . ' border-rose-300 dark:border-rose-700 focus:ring-1 focus:ring-rose-500 focus:border-rose-500 text-rose-900 placeholder-rose-300';
    } else {
        $classes = $baseClasses . ' border-apeiron-border hover:border-zinc-400 focus:ring-1 focus:ring-apeiron-ink focus:border-apeiron-ink';
    }
@endphp

<input @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>
