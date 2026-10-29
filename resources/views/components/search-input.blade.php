@props([
    'placeholder' => 'Cari...',
    'icon' => 'search',
])

<div class="flex items-center w-full bg-[#F7F7F8] dark:bg-zinc-900 border border-apeiron-border rounded-lg focus-within:border-zinc-800 dark:focus-within:border-zinc-400 focus-within:ring-1 focus-within:ring-zinc-800 dark:focus-within:ring-zinc-400 focus-within:bg-white dark:focus-within:bg-zinc-900 transition-colors overflow-hidden h-9">
    <div class="pl-3 pr-2 text-zinc-400 flex items-center justify-center">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">{{ $icon }}</span>
    </div>
    <input 
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'flex-1 w-full h-full pr-3 bg-transparent outline-none text-apeiron-ink dark:text-zinc-100 text-xs sm:text-[13px] font-medium placeholder:text-zinc-400 placeholder:font-normal']) }}
    >
</div>
