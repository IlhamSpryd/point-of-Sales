@props([
    'placeholder' => 'Cari...',
    'icon' => 'search',
])

<div class="flex items-center w-full bg-gray-50 border border-gray-200 rounded-xl focus-within:border-gray-900 focus-within:ring-1 focus-within:ring-gray-900 focus-within:bg-white transition-colors overflow-hidden h-11 shadow-[inset_0_1px_2px_rgba(0,0,0,0.05)]">
    <div class="pl-3 pr-2 text-gray-400 flex items-center justify-center">
        <span class="material-symbols-rounded text-[20px]">{{ $icon }}</span>
    </div>
    <input 
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'flex-1 w-full h-full pr-3 bg-transparent outline-none text-gray-900 text-[14px] font-medium placeholder:text-gray-400 placeholder:font-normal']) }}
    >
</div>
