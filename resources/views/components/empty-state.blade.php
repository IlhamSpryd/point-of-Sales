@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
    'action' => null
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center p-8 text-center rounded-xl border border-dashed border-apeiron-border bg-[#F7F7F8] dark:bg-zinc-900/40']) }}>
    <div class="w-12 h-12 bg-white dark:bg-zinc-800 rounded-lg flex items-center justify-center mb-3 border border-apeiron-border">
        <span class="material-symbols-rounded text-2xl text-zinc-400">{{ $icon }}</span>
    </div>
    <h3 class="text-lg font-bold text-apeiron-ink">{{ $title }}</h3>
    @if($description)
        <p class="text-sm text-apeiron-muted mt-1 max-w-sm mx-auto">{{ $description }}</p>
    @endif
    @if($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif
</div>
