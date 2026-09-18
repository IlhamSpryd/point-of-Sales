{{-- 
  Komponen Alert Sesi (Success/Error)
  Cara pakai: <x-alert type="success">{{ session('success') }}</x-alert>
--}}
@props(['type' => 'success'])

@php
    $classes = $type === 'success' 
        ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' 
        : 'bg-rose-50 border border-rose-200 text-rose-700';
        
    $icon = $type === 'success' ? 'check_circle' : 'warning';
    $btnClasses = $type === 'success' ? 'text-emerald-400 hover:text-emerald-600' : 'text-rose-400 hover:text-rose-600';
@endphp

<div class="mb-6 p-4 rounded-xl {{ $classes }} text-sm font-medium flex items-center justify-between transition-all duration-200" x-data="{ show: true }" x-show="show"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-2">
    <span class="flex items-center gap-2"><span class="material-symbols-rounded text-[18px]">{{ $icon }}</span> {{ $slot }}</span>
    <button @click="show = false" class="{{ $btnClasses }} transition-colors duration-200 active:scale-90">
        <span class="material-symbols-rounded text-[18px]">close</span>
    </button>
</div>
