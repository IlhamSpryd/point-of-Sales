<div class="flex h-72 w-full items-center justify-center overflow-hidden rounded-large border border-foreground/10 bg-card">
    <x-components.infinite-canvas :scale="100" dot-pattern>
        <div class="rounded-large border border-foreground/10 bg-background px-6 py-4 text-center shadow-xs">
            <p class="font-semibold text-foreground">Pan around me</p>
            <p class="mt-1 text-sm text-foreground/60">Scroll or trackpad to glide across the canvas.</p>
        </div>
    </x-components.infinite-canvas>
</div>
