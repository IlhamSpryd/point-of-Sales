<div class="flex min-h-64 w-full items-center justify-center">
    <x-components.dropdown>
        <x-slot:trigger>
            <x-components.button variant="outline">
                Options
                <svg class="ml-1 h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
            </x-components.button>
        </x-slot:trigger>
        <x-slot:menu>
            <a href="#" class="block rounded-small px-3 py-1.5 text-sm text-foreground/80 transition hover:bg-secondary">Profile</a>
            <a href="#" class="block rounded-small px-3 py-1.5 text-sm text-foreground/80 transition hover:bg-secondary">Settings</a>
            <a href="#" class="block rounded-small px-3 py-1.5 text-sm text-foreground/80 transition hover:bg-secondary">Billing</a>
            <div class="my-1 h-px bg-foreground/10"></div>
            <a href="#" class="block rounded-small px-3 py-1.5 text-sm text-destructive transition hover:bg-secondary">Sign out</a>
        </x-slot:menu>
    </x-components.dropdown>
</div>
