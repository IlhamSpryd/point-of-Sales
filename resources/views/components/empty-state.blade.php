@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
    'action' => null
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center p-8 text-center rounded-xl border border-dashed border-border bg-muted']) }}>
    <div class="w-12 h-12 bg-card rounded-lg flex items-center justify-center mb-3 border border-border">
        <x-ui.icon :name="$icon" size="xl" class="text-muted-foreground" />
    </div>
    <h3 class="text-lg font-bold text-foreground">{{ $title }}</h3>
    @if($description)
        <p class="text-sm text-muted-foreground mt-1 max-w-sm mx-auto">{{ $description }}</p>
    @endif
    @if($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif
</div>
