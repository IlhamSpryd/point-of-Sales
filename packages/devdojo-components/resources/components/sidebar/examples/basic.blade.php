<div class="flex h-full w-full overflow-hidden rounded-large border border-foreground/10">
    <x-components.sidebar>
        <x-components.sidebar.header>
            <span class="px-2 text-sm font-semibold text-sidebar-foreground">Acme Inc</span>
        </x-components.sidebar.header>
        <x-components.sidebar.content>
            <x-components.sidebar.group label="Platform">
                <x-components.sidebar.nav-item href="#" active>Dashboard</x-components.sidebar.nav-item>
                <x-components.sidebar.nav-item href="#">Projects</x-components.sidebar.nav-item>
                <x-components.sidebar.nav-item href="#">Settings</x-components.sidebar.nav-item>
            </x-components.sidebar.group>
        </x-components.sidebar.content>
        <x-components.sidebar.footer>
            <span class="px-2 text-xs text-sidebar-foreground/60">v1.0.0</span>
        </x-components.sidebar.footer>
    </x-components.sidebar>
    <div class="flex flex-1 items-center justify-center bg-background p-6 text-sm text-foreground/60">
        Main content area
    </div>
</div>
