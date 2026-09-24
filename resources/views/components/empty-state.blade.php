@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
    'action' => null
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center p-8 text-center rounded-2xl border border-dashed border-yovel-border bg-yovel-bg']) }}>
    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-sm mb-4 border border-yovel-border">
        <span class="material-symbols-rounded text-3xl text-yovel-muted">{{ $icon }}</span>
    </div>
    <h3 class="text-lg font-bold text-yovel-ink">{{ $title }}</h3>
    @if($description)
        <p class="text-sm text-yovel-muted mt-1 max-w-sm mx-auto">{{ $description }}</p>
    @endif
    @if($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif
</div>
