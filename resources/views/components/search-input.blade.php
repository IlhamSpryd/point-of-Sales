@props([
    'placeholder' => 'Cari...',
    'icon' => 'search',
])

<div class="flex items-center w-full bg-[#F7F7F5] border border-[#E9E9E7] rounded-xl focus-within:border-[#202020] focus-within:ring-1 focus-within:ring-[#202020] focus-within:bg-white transition-all overflow-hidden h-10 shadow-2xs">
    <div class="pl-3 pr-2 text-[#9B9A97] flex items-center justify-center">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">{{ $icon }}</span>
    </div>
    <input 
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'flex-1 w-full h-full pr-3 bg-transparent outline-none text-[#202020] text-xs sm:text-[13px] font-medium placeholder:text-[#9B9A97] placeholder:font-normal']) }}
    >
</div>
