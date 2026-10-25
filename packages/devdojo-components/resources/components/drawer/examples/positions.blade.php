<div class="flex min-h-72 w-full flex-wrap items-center justify-center gap-3">
    <x-components.drawer position="right">
        <x-slot:trigger><x-components.button>Open right drawer</x-components.button></x-slot:trigger>
        <x-slot:header>Edit profile</x-slot:header>
        <x-slot:content>
            <div class="grid gap-4">
                <x-components.input label="Name" value="Tony Lea" />
                <x-components.input label="Email" type="email" value="tony@devdojo.com" />
                <x-components.toggle label="Public profile" checked />
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-components.button variant="ghost" x-on:click="open = false">Cancel</x-components.button>
            <x-components.button x-on:click="open = false">Save changes</x-components.button>
        </x-slot:footer>
    </x-components.drawer>

    <x-components.drawer position="left">
        <x-slot:trigger><x-components.button variant="outline">Open left drawer</x-components.button></x-slot:trigger>
        <x-slot:header>Navigation</x-slot:header>
        <x-slot:content>
            <nav class="grid gap-1">
                <a href="#" class="rounded-medium px-3 py-2 text-sm text-foreground/80 hover:bg-secondary">Dashboard</a>
                <a href="#" class="rounded-medium px-3 py-2 text-sm text-foreground/80 hover:bg-secondary">Projects</a>
                <a href="#" class="rounded-medium px-3 py-2 text-sm text-foreground/80 hover:bg-secondary">Settings</a>
            </nav>
        </x-slot:content>
    </x-components.drawer>
</div>
