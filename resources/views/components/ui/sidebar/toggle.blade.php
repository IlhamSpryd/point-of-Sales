@props(['class' => ''])

<button 
    type="button"
    x-on:click="sidebarOpen = !sidebarOpen"
    {{ $attributes->merge(['class' => 'inline-flex h-10 items-center justify-center rounded-medium px-3 text-sm font-medium ring-offset-background transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' . $class]) }}
>
    <x-heroicon-o-bars-3 x-show="!sidebarOpen" class="h-4 w-4" />
    <x-heroicon-o-x-mark x-show="sidebarOpen" class="h-4 w-4" />
    <span class="sr-only">Toggle Sidebar</span>
</button>
