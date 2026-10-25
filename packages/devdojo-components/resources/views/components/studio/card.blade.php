@props(['component'])

@php
    $baseUrl = rtrim(route('devdojo-components.showcase'), '/');

    // Fallback preview: render with the metadata slot defaults and studio
    // defaults (the same curated state the preview endpoint uses), so
    // components that rely on slot children don't render empty in the grid.
    // Components with a studio container need a stage, so their defaults are
    // skipped — they should declare an explicit studio.card instead.
    $slotDefaults = collect($component['slots'] ?? [])
        ->mapWithKeys(fn (array $slot) => [$slot['name'] => (string) ($slot['default'] ?? '')])
        ->filter(fn (string $value) => trim($value) !== '')
        ->all();

    $studioAttrs = ($component['studio']['container'] ?? null) ? [] : ($component['studio']['defaults'] ?? []);

    $previewMarkup = $component['studio']['card']
        ?? \DevDojo\Components\CodeGenerator::generate($component['name'], $studioAttrs, $slotDefaults);

    // One misbehaving component must not 500 the whole index grid.
    try {
        $previewHtml = \Illuminate\Support\Facades\Blade::render($previewMarkup);
    } catch (\Throwable $e) {
        $previewHtml = '<p class="text-xs text-foreground/40">Preview unavailable</p>';
    }
@endphp

<div class="flex h-full flex-col overflow-hidden rounded-large border border-foreground/10 bg-card shadow-xs transition-all duration-200 hover:border-foreground/20 hover:shadow-sm">
    <div class="flex items-start justify-between gap-3 border-b border-foreground/10 px-4 py-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <a href="{{ $baseUrl }}/{{ $component['name'] }}"
                    class="truncate rounded-small text-sm font-semibold text-foreground outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring">
                    {{ $component['label'] }}
                </a>
                <code class="hidden rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[11px] text-foreground/60 sm:inline-block">x-{{ $component['name'] }}</code>
            </div>
            @if ($component['description'])
                <p class="mt-0.5 line-clamp-1 text-[13px] leading-5 text-foreground/55">{{ $component['description'] }}</p>
            @endif
        </div>

        @if (class_exists(\Livewire\Livewire::class))
            <livewire:devdojo-components.card :name="$component['name']" :key="'card-'.$component['name']" />
        @else
            {{-- Fallback when Livewire isn't installed: copy the add command. --}}
            <button type="button"
                x-data="{ copied: false }"
                @click="ddCopy('php artisan components:add {{ $component['name'] }}'); copied = true; setTimeout(() => copied = false, 1500)"
                class="inline-flex h-7 shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-medium border border-foreground/10 bg-background px-2.5 font-mono text-xs text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]">
                <template x-if="!copied">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></svg>
                </template>
                <template x-if="copied">
                    <svg class="h-3.5 w-3.5 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                </template>
                <span x-text="copied ? 'Copied!' : 'add {{ $component['name'] }}'"></span>
            </button>
        @endif
    </div>

    <div class="flex min-h-[9.5rem] flex-1 items-center justify-center bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:18px_18px] p-5">
        <div class="flex w-full max-w-md flex-col items-center">
            {!! $previewHtml !!}
        </div>
    </div>

    <a href="{{ $baseUrl }}/{{ $component['name'] }}"
        class="group flex items-center justify-between border-t border-foreground/10 px-4 py-2.5 text-[13px] font-medium text-foreground/60 outline-none transition hover:bg-secondary/60 hover:text-foreground focus-visible:bg-secondary/60 focus-visible:text-foreground">
        Learn more
        <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
    </a>
</div>
