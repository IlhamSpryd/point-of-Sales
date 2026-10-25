<div class="flex min-h-72 w-full items-center justify-center">
    <x-components.modal>
        <x-slot:trigger>
            <x-components.button>Open modal</x-components.button>
        </x-slot:trigger>
        <x-slot:header>Delete project</x-slot:header>
        <x-slot:content>
            <p class="text-foreground/70">This action cannot be undone. This will permanently delete the project and all of its data.</p>
        </x-slot:content>
        <x-slot:footer>
            <x-components.button variant="ghost" x-on:click="modalOpen = false">Cancel</x-components.button>
            <x-components.button variant="destructive" x-on:click="modalOpen = false">Delete</x-components.button>
        </x-slot:footer>
    </x-components.modal>
</div>
