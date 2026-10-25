<div class="w-full">
    <x-components.splitter direction="vertical" class="h-72 w-full overflow-hidden rounded-large border border-foreground/10" :min-size="60">
        <x-components.splitter.pane>
            <div class="flex h-full items-center justify-center bg-card p-6 text-center text-sm text-foreground/70">Top panel</div>
        </x-components.splitter.pane>
        <x-components.splitter.pane>
            <div class="flex h-full items-center justify-center bg-background p-6 text-center text-sm text-foreground/70">Bottom panel</div>
        </x-components.splitter.pane>
    </x-components.splitter>
</div>
