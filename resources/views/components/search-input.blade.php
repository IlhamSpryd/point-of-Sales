@props([
    'placeholder' => 'Cari...',
    'icon' => 'search',
])

<div class="relative w-full group">
    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
        <span class="material-symbols-rounded text-[20px] text-yovel-muted group-focus-within:text-yovel-ink transition-colors duration-300">{{ $icon }}</span>
    </div>
    <input 
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'block w-full h-10 pl-10 pr-4 bg-white border border-yovel-border rounded-xl text-sm text-yovel-ink placeholder-yovel-muted focus:outline-none focus:border-yovel-ink focus:ring-4 focus:ring-yovel-surface shadow-sm transition-all duration-300 ease-out']) }}
    >
</div>
