<div class="flex min-h-64 w-full flex-wrap items-center justify-center gap-3">
    <x-components.popover position="bottom" align="center">
        <x-slot:trigger><x-components.button variant="outline">Bottom</x-components.button></x-slot:trigger>
        <x-slot:content>
            <p class="text-sm font-medium text-foreground">Popover title</p>
            <p class="mt-1 text-sm text-foreground/60">Positioned below and centered on the trigger.</p>
        </x-slot:content>
    </x-components.popover>
    <x-components.popover position="right" align="center">
        <x-slot:trigger><x-components.button variant="outline">Right</x-components.button></x-slot:trigger>
        <x-slot:content>
            <p class="text-sm text-foreground/70">I appear to the right.</p>
        </x-slot:content>
    </x-components.popover>
    <x-components.popover position="top" align="center">
        <x-slot:trigger><x-components.button variant="outline">Top</x-components.button></x-slot:trigger>
        <x-slot:content>
            <p class="text-sm text-foreground/70">I appear above.</p>
        </x-slot:content>
    </x-components.popover>
</div>
