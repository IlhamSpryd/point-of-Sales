<button 
    @click="toggle()" 
    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-medium text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-sidebar-accent h-7 w-7 -ml-1"
    data-sidebar="toggle">
    {{ $slot }}
</button>