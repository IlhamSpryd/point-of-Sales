<div class="w-full">
    <x-components.splitter class="h-72 w-full overflow-hidden rounded-large border border-foreground/10" :min-size="80">
        <x-components.splitter.pane :size="35">
            <div class="flex h-full items-center justify-center bg-card p-6 text-center">
                <div>
                    <p class="font-semibold text-foreground">Sidebar</p>
                    <p class="mt-1 text-sm text-foreground/60">Drag the seam →</p>
                </div>
            </div>
        </x-components.splitter.pane>
        <x-components.splitter.pane :size="65">
            <div class="flex h-full items-center justify-center bg-background p-6 text-center text-sm text-foreground/70">Main panel</div>
        </x-components.splitter.pane>
    </x-components.splitter>
</div>
