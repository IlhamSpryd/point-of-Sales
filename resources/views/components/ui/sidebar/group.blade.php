@props([
    'label' => null
])

<div {{ $attributes->merge(['class' => 'p-3']) }}>
    @if($label)
        <h3 class="duration-200 data-state-collapsed-icon:h-0 data-state-collapsed-icon:opacity-0 overflow-hidden transition-[height,opacity] ease-out duration-300 flex h-8 shrink-0 items-center rounded-medium px-2 text-xs font-medium text-sidebar-foreground/50 outline-none transition-[margin,opacity] ease-linear focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0 ">
            {{ $label }}
        </h3>
    @endif
    <div class="space-y-1">
        {{ $slot }}
    </div>
</div>


{{-- <div data-sidebar="group-label" class="duration-200 flex h-8 shrink-0 items-center rounded-medium px-2 text-xs font-medium text-sidebar-foreground/70 outline-none ring-sidebar-ring transition-[margin,opa] ease-linear focus-visible:ring-2 [&amp;>svg]:size-4 [&amp;>svg]:shrink-0 group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0">Favorites</div> --}}
