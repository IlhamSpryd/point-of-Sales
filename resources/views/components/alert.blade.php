{{-- 
  Komponen Alert Sesi (Success/Error/Warning/Info)
  Cara pakai: <x-alert type="success">{{ session('success') }}</x-alert>
--}}
@props(['type' => 'success'])

@php
    $config = match($type) {
        'error', 'danger', 'rose' => [
            'class' => 'bg-destructive-muted border border-destructive-border text-destructive',
            'icon' => 'error',
            'btn' => 'text-destructive hover:text-destructive',
            'role' => 'alert',
            'ariaLive' => 'assertive',
        ],
        'warning', 'amber' => [
            'class' => 'bg-warning-muted border border-warning-border text-warning',
            'icon' => 'warning',
            'btn' => 'text-warning hover:text-warning',
            'role' => 'status',
            'ariaLive' => 'polite',
        ],
        'info', 'blue', 'sky' => [
            'class' => 'bg-info-muted border border-info-border text-info',
            'icon' => 'info',
            'btn' => 'text-info hover:text-info',
            'role' => 'status',
            'ariaLive' => 'polite',
        ],
        default => [ // success
            'class' => 'bg-success-muted border border-success-border text-success',
            'icon' => 'check_circle',
            'btn' => 'text-success hover:text-success',
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
    <button @click="show = false" aria-label="Close notification" class="{{ $config['btn'] }} transition-colors duration-200 active:scale-90 cursor-pointer p-1 rounded-md">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">close</span>
    </button>
</div>
