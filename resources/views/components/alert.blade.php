{{-- 
  Komponen Alert Sesi (Success/Error/Warning/Info)
  Cara pakai: <x-alert type="success">{{ session('success') }}</x-alert>
--}}
@props(['type' => 'success'])

@php
    $config = match($type) {
        'error', 'danger', 'rose' => [
            'class' => 'bg-rose-50 border border-rose-200 text-rose-800',
            'icon' => 'error',
            'btn' => 'text-rose-500 hover:text-rose-700',
            'role' => 'alert',
            'ariaLive' => 'assertive',
        ],
        'warning', 'amber' => [
            'class' => 'bg-amber-50 border border-amber-200 text-amber-800',
            'icon' => 'warning',
            'btn' => 'text-amber-500 hover:text-amber-700',
            'role' => 'status',
            'ariaLive' => 'polite',
        ],
        'info', 'blue', 'sky' => [
            'class' => 'bg-sky-50 border border-sky-200 text-sky-800',
            'icon' => 'info',
            'btn' => 'text-sky-500 hover:text-sky-700',
            'role' => 'status',
            'ariaLive' => 'polite',
        ],
        default => [ // success
            'class' => 'bg-emerald-50 border border-emerald-200 text-emerald-800',
            'icon' => 'check_circle',
            'btn' => 'text-emerald-500 hover:text-emerald-700',
            'role' => 'status',
            'ariaLive' => 'polite',
        ],
    };
@endphp

<div role="{{ $config['role'] }}" aria-live="{{ $config['ariaLive'] }}" class="mb-6 p-4 rounded-xl {{ $config['class'] }} text-sm font-medium flex items-center justify-between transition-all duration-200" x-data="{ show: true }" x-show="show"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-2">
    <span class="flex items-center gap-2">
        <span class="material-symbols-rounded text-[18px] shrink-0" aria-hidden="true">{{ $config['icon'] }}</span>
        <span>{{ $slot }}</span>
    </span>
    <button @click="show = false" aria-label="Tutup notifikasi" class="{{ $config['btn'] }} transition-colors duration-200 active:scale-90 cursor-pointer p-1 rounded-md">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">close</span>
    </button>
</div>
