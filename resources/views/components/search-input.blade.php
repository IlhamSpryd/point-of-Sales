@props([
    'placeholder' => 'Search...',
    'icon' => 'search',
])

<div class="flex items-center w-full bg-secondary border border-input rounded-lg focus-within:border-ring focus-within:ring-1 focus-within:ring-ring focus-within:bg-card transition-colors overflow-hidden h-9">
    <div class="pl-3 pr-2 text-muted-foreground flex items-center justify-center">
        <x-ui.icon :name="$icon" size="sm" />
    </div>
    <input 
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'flex-1 w-full h-full pr-3 bg-transparent outline-none text-foreground text-xs sm:text-[13px] font-medium placeholder:text-muted-foreground placeholder:font-normal']) }}
    >
</div>
