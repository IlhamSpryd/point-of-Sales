{{-- 
  Komponen Alert Sesi (Success/Error)
  Cara pakai: <x-alert type="success">{{ session('success') }}</x-alert>
--}}
@props(['type' => 'success'])

@php
    $classes = $type === 'success' 
        ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-400' 
        : 'bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 text-rose-700 dark:text-rose-400';
        
    $icon = $type === 'success' ? 'check_circle' : 'warning';
    $btnClasses = $type === 'success' ? 'text-emerald-500 hover:text-emerald-700' : 'text-rose-500 hover:text-rose-700';
@endphp

<div class="mb-6 p-4 rounded-lg {{ $classes }} text-sm font-medium flex items-center justify-between" x-data="{ show: true }" x-show="show">
    <span><span class="material-symbols-rounded mr-2">{{ $icon }}</span> {{ $slot }}</span>
    <button @click="show = false" class="{{ $btnClasses }}"><span class="material-symbols-rounded">close</span></button>
</div>
