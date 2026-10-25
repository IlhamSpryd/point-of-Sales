{{--
    Alias $component to $meta before the layout tag opens: Blade's component-tag
    compiler reassigns the literal variable $component to an internal
    AnonymousComponent instance for the entire body of the tag (restored only
    after it closes), which would otherwise shadow the $component array the
    controller passes in.
--}}
@php
    $meta = $component;
@endphp

<x-devdojo-components::studio.layout
    :categories="$categories"
    :current="$meta['name']"
    :title="$meta['label'].' — DevDojo Components'">

    @php
        $previewBase = route('devdojo-components.preview', ['name' => $meta['name']]);
        $baseUrl = rtrim(route('devdojo-components.showcase'), '/');

        $flatComponents = collect($categories)->flatMap(fn ($items) => $items)->values();
        $currentIndex = $flatComponents->search(fn ($item) => $item['name'] === $meta['name']);
        $prevComponent = $currentIndex !== false && $currentIndex > 0 ? $flatComponents[$currentIndex - 1] : null;
        $nextComponent = $currentIndex !== false && $currentIndex < $flatComponents->count() - 1 ? $flatComponents[$currentIndex + 1] : null;
    @endphp

    <div x-data="{
            tab: ['build', 'playground'].includes(new URLSearchParams(window.location.search).get('tab')) ? 'build' : 'docs',
            setTab(value) {
                this.tab = value;
                const params = new URLSearchParams(window.location.search);
                value === 'docs' ? params.delete('tab') : params.set('tab', value);
                history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dd-toc-refresh')));
            }
        }"
        class="flex flex-col gap-6">

        {{-- ================= HEADER ================= --}}
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h1 class="text-balance text-2xl font-bold tracking-tight">{{ $meta['label'] }}</h1>
                        <span class="rounded-full bg-secondary px-2 py-0.5 text-[11px] font-medium text-foreground/55">{{ $meta['category'] }}</span>
                    </div>
                    @if ($meta['description'])
                        <p class="mt-1.5 max-w-2xl text-pretty text-[15px] leading-6 text-foreground/60">{{ $meta['description'] }}</p>
                    @endif
                </div>
                @if (class_exists(\Livewire\Livewire::class))
                    <livewire:devdojo-components.card :name="$meta['name']" :key="'show-'.$meta['name']" />
                @endif
            </div>

            {{-- Install snippet + copy-for-AI --}}
            @php
                $markdown = \DevDojo\Components\Markdown::component($meta, $examples->all());
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                <div class="group flex w-full max-w-md items-center justify-between gap-3 rounded-medium border border-foreground/10 bg-card py-1.5 pl-3 pr-1.5 transition-colors hover:border-foreground/20"
                    x-data="{ copied: false }">
                    <code class="truncate font-mono text-[13px] text-foreground/75"><span class="select-none text-foreground/30">$ </span>php artisan components:add {{ $meta['name'] }}</code>
                    <button type="button"
                        @click="ddCopy('php artisan components:add {{ $meta['name'] }}'); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-small text-foreground/40 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-95" aria-label="Copy install command">
                        <svg x-show="!copied" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></svg>
                        <svg x-show="copied" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="scale-50 opacity-0" x-transition:enter-end="scale-100 opacity-100" class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </button>
                </div>
                {{-- The whole page as Markdown — paste-ready context for AI assistants. --}}
                <button type="button" x-data="{ copied: false }"
                    @click="ddCopy(@js($markdown)); copied = true; setTimeout(() => copied = false, 1500)"
                    title="Copy this page as Markdown — paste-ready context for AI assistants"
                    class="inline-flex h-[38px] items-center gap-1.5 rounded-medium border border-foreground/10 bg-card px-3 text-[13px] font-medium text-foreground/60 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]">
                    <svg x-show="!copied" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" /><path d="M14 2v4a2 2 0 0 0 2 2h4" /><path d="M10 9H8" /><path d="M16 13H8" /><path d="M16 17H8" /></svg>
                    <svg x-show="copied" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="scale-50 opacity-0" x-transition:enter-end="scale-100 opacity-100" class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    <span x-text="copied ? 'Copied!' : 'Copy Markdown'"></span>
                </button>
            </div>
        </div>

        {{-- Tabs (underlined) --}}
        <div class="flex items-center gap-6 border-b border-foreground/10" role="tablist" aria-label="Component views"
            @keydown.arrow-right.prevent="setTab(tab === 'docs' ? 'build' : 'docs'); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())"
            @keydown.arrow-left.prevent="setTab(tab === 'docs' ? 'build' : 'docs'); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())">
            <button type="button" @click="setTab('docs')" id="studio-tab-docs" role="tab" :aria-selected="tab === 'docs'" aria-controls="studio-panel-docs" :tabindex="tab === 'docs' ? 0 : -1"
                :class="tab === 'docs' ? 'border-foreground text-foreground' : 'border-transparent text-foreground/50 hover:text-foreground'"
                class="-mb-px rounded-t-small border-b-2 pb-2.5 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring">Docs</button>
            <button type="button" @click="setTab('build')" id="studio-tab-build" role="tab" :aria-selected="tab === 'build'" aria-controls="studio-panel-build" :tabindex="tab === 'build' ? 0 : -1"
                :class="tab === 'build' ? 'border-foreground text-foreground' : 'border-transparent text-foreground/50 hover:text-foreground'"
                class="-mb-px rounded-t-small border-b-2 pb-2.5 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring">Build</button>
        </div>

        {{-- ================= DOCS TAB ================= --}}
        <div x-show="tab === 'docs'" id="studio-panel-docs" role="tabpanel" aria-labelledby="studio-tab-docs"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
            class="flex flex-col gap-10">

            @forelse ($examples as $example)
                <section class="flex flex-col gap-3.5" x-data="{ view: 'preview' }">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="{{ Str::slug($example['title']) }}" data-toc="{{ $example['title'] }}" class="scroll-mt-24 text-lg font-semibold tracking-tight lg:scroll-mt-8">{{ $example['title'] }}</h2>
                            @if (! empty($example['description']))
                                <p class="mt-1 max-w-2xl text-pretty text-sm leading-6 text-foreground/55">{{ $example['description'] }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-0.5 rounded-medium bg-secondary p-0.5">
                            <button type="button" @click="view = 'preview'"
                                :class="view === 'preview' ? 'bg-card text-foreground shadow-xs' : 'text-foreground/50 hover:text-foreground'"
                                class="rounded-small px-2.5 py-1 text-xs font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-ring">Preview</button>
                            <button type="button" @click="view = 'code'"
                                :class="view === 'code' ? 'bg-card text-foreground shadow-xs' : 'text-foreground/50 hover:text-foreground'"
                                class="rounded-small px-2.5 py-1 text-xs font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-ring">Code</button>
                        </div>
                    </div>

                    <div x-show="view === 'preview'" x-data="{ loaded: false }"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="overflow-hidden rounded-large border border-foreground/10 bg-background bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:18px_18px]">
                        <iframe title="{{ $example['title'] }} preview" loading="lazy" @load="loaded = true" data-auto-height
                            :src="'{{ $previewBase }}?example={{ Str::of($example['file'])->basename('.blade.php') }}&theme=' + (dark ? 'dark' : 'light')"
                            :class="loaded ? 'opacity-100' : 'opacity-0'"
                            class="block w-full transition-[opacity,height] duration-300"
                            style="height: {{ $example['height'] ?? '16rem' }}; min-height: {{ $example['height'] ?? '16rem' }};"></iframe>
                    </div>
                    <div x-show="view === 'code'" x-cloak
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                        <x-devdojo-components::studio.code-block :code="$example['code']" />
                    </div>
                </section>
            @empty
                <section class="flex flex-col gap-3.5" x-data="{ loaded: false }">
                    <h2 id="preview" data-toc="Preview" class="scroll-mt-24 text-lg font-semibold tracking-tight lg:scroll-mt-8">Preview</h2>
                    <div class="overflow-hidden rounded-large border border-foreground/10 bg-background bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:18px_18px]">
                        <iframe title="{{ $meta['label'] }} preview" loading="lazy" @load="loaded = true" data-auto-height
                            :src="'{{ $previewBase }}?theme=' + (dark ? 'dark' : 'light')"
                            :class="loaded ? 'opacity-100' : 'opacity-0'"
                            class="block w-full transition-[opacity,height] duration-300"
                            style="height: 16rem; min-height: 16rem;"></iframe>
                    </div>
                </section>
            @endforelse

            {{-- Reference tables --}}
            @if (count($meta['props']))
                <section class="flex flex-col gap-3.5">
                    <h2 id="props" data-toc="Props" class="scroll-mt-24 text-lg font-semibold tracking-tight lg:scroll-mt-8">Props</h2>
                    <div class="studio-scroll overflow-x-auto rounded-medium border border-foreground/10">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-foreground/10 bg-secondary/50 text-[11px] uppercase tracking-wider text-foreground/45">
                                    <th class="px-4 py-2.5 font-medium">Prop</th>
                                    <th class="px-4 py-2.5 font-medium">Type</th>
                                    <th class="px-4 py-2.5 font-medium">Default</th>
                                    <th class="px-4 py-2.5 font-medium">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($meta['props'] as $prop)
                                    <tr class="border-b border-foreground/10 transition-colors last:border-0 hover:bg-secondary/30">
                                        <td class="px-4 py-3"><code class="rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[12px] text-foreground/80">{{ $prop['name'] }}</code></td>
                                        <td class="px-4 py-3 font-mono text-[12px] text-foreground/60">
                                            {{ $prop['type'] ?? 'text' }}@if (! empty($prop['options'])): {{ implode(' | ', array_map(fn ($o) => is_bool($o) ? var_export($o, true) : $o, $prop['options'])) }}@endif
                                        </td>
                                        <td class="px-4 py-3 font-mono text-[12px] text-foreground/50">{{ is_bool($prop['default'] ?? null) ? var_export($prop['default'], true) : (($prop['default'] ?? '') === '' ? '—' : (is_array($prop['default']) ? json_encode($prop['default']) : $prop['default'])) }}</td>
                                        <td class="px-4 py-3 text-foreground/60">{{ $prop['description'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if (count($meta['slots']))
                <section class="flex flex-col gap-3.5">
                    <h2 id="slots" data-toc="Slots" class="scroll-mt-24 text-lg font-semibold tracking-tight lg:scroll-mt-8">Slots</h2>
                    <div class="studio-scroll overflow-x-auto rounded-medium border border-foreground/10">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-foreground/10 bg-secondary/50 text-[11px] uppercase tracking-wider text-foreground/45">
                                    <th class="px-4 py-2.5 font-medium">Slot</th>
                                    <th class="px-4 py-2.5 font-medium">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($meta['slots'] as $slot)
                                    <tr class="border-b border-foreground/10 transition-colors last:border-0 hover:bg-secondary/30">
                                        <td class="px-4 py-3"><code class="rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[12px] text-foreground/80">{{ $slot['name'] }}</code>@if ($slot['name'] === 'slot') <span class="text-[11px] text-foreground/40">(default)</span>@endif</td>
                                        <td class="px-4 py-3 text-foreground/60">{{ $slot['description'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- Prev / next component pager --}}
            @if ($prevComponent || $nextComponent)
                <nav class="flex items-stretch justify-between gap-4 border-t border-foreground/10 pt-6" aria-label="Adjacent components">
                    @if ($prevComponent)
                        <a href="{{ $baseUrl }}/{{ $prevComponent['name'] }}"
                            class="group flex flex-col items-start gap-1 rounded-medium px-1 py-1 outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <span class="text-xs text-foreground/45">Previous</span>
                            <span class="flex items-center gap-1.5 text-sm font-medium text-foreground/75 transition-colors group-hover:text-foreground">
                                <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:-translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5" /><path d="m12 19-7-7 7-7" /></svg>
                                {{ $prevComponent['label'] }}
                            </span>
                        </a>
                    @else
                        <span></span>
                    @endif
                    @if ($nextComponent)
                        <a href="{{ $baseUrl }}/{{ $nextComponent['name'] }}"
                            class="group flex flex-col items-end gap-1 rounded-medium px-1 py-1 text-right outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <span class="text-xs text-foreground/45">Next</span>
                            <span class="flex items-center gap-1.5 text-sm font-medium text-foreground/75 transition-colors group-hover:text-foreground">
                                {{ $nextComponent['label'] }}
                                <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                            </span>
                        </a>
                    @endif
                </nav>
            @endif
        </div>

        {{-- ================= BUILD TAB (Storybook-style) ================= --}}
        <div x-show="tab === 'build'" x-cloak id="studio-panel-build" role="tabpanel" aria-labelledby="studio-tab-build"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
            x-data="studioPlayground({
                name: @js($meta['name']),
                previewBase: @js($previewBase),
                attrs: @js($attrs),
                slots: @js($slotValues),
                defaults: @js(collect($meta['props'])->mapWithKeys(fn ($p) => [$p['name'] => $p['default'] ?? ''])->all()),
                types: @js(collect($meta['props'])->mapWithKeys(fn ($p) => [$p['name'] => $p['type'] ?? 'text'])->all()),
                {{-- The server resolves a missing query param to studio default ?? prop
                     default (see StudioController::playgroundState), so params matching
                     that baseline are omitted to keep URLs minimal and shareable. --}}
                baseline: @js(collect($meta['props'])->mapWithKeys(fn ($p) => [$p['name'] => $meta['studio']['defaults'][$p['name']] ?? $p['default'] ?? ''])->all()),
                baselineSlots: @js((function (array $meta): array {
                    $slots = collect($meta['slots'])->mapWithKeys(fn ($slot) => [$slot['name'] => (string) ($slot['default'] ?? '')])->all();
                    $slots['slot'] ??= $meta['slots'] === [] ? '' : $meta['label'];

                    return $slots;
                })($meta)),
            })"
            class="flex h-[calc(100vh-14rem)] min-h-[34rem] flex-col overflow-hidden rounded-large border border-foreground/10 bg-card shadow-xs">

            {{-- ===== Canvas ===== --}}
            <div class="flex min-h-0 flex-1 flex-col">
                {{-- Toolbar --}}
                <div class="flex items-center justify-between gap-3 border-b border-foreground/10 px-2.5 py-1.5">
                    <div class="hidden items-center gap-0.5 md:flex">
                        <button type="button" @click="stageWidth = '375px'" :class="stageWidth === '375px' ? 'bg-secondary text-foreground' : 'text-foreground/40 hover:bg-secondary/60 hover:text-foreground'" class="inline-flex h-7 w-7 items-center justify-center rounded-small outline-none transition focus-visible:ring-2 focus-visible:ring-ring" aria-label="Mobile width" title="Preview at 375px">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" /><path d="M12 18h.01" /></svg>
                        </button>
                        <button type="button" @click="stageWidth = '768px'" :class="stageWidth === '768px' ? 'bg-secondary text-foreground' : 'text-foreground/40 hover:bg-secondary/60 hover:text-foreground'" class="inline-flex h-7 w-7 items-center justify-center rounded-small outline-none transition focus-visible:ring-2 focus-visible:ring-ring" aria-label="Tablet width" title="Preview at 768px">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2" /><path d="M12 17h.01" /></svg>
                        </button>
                        <button type="button" @click="stageWidth = '100%'" :class="stageWidth === '100%' ? 'bg-secondary text-foreground' : 'text-foreground/40 hover:bg-secondary/60 hover:text-foreground'" class="inline-flex h-7 w-7 items-center justify-center rounded-small outline-none transition focus-visible:ring-2 focus-visible:ring-ring" aria-label="Full width" title="Preview at full width">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2" /><path d="M8 21h8M12 17v4" /></svg>
                        </button>
                        <span x-show="stageWidth !== '100%'" x-cloak class="ml-1.5 font-mono text-[11px] tabular-nums text-foreground/35" x-text="stageWidth"></span>
                    </div>
                    <div class="md:hidden"></div>
                    <div class="flex items-center gap-1">
                        <a :href="stageSrc" target="_blank" rel="noreferrer" class="inline-flex h-7 w-7 items-center justify-center rounded-small text-foreground/40 outline-none transition hover:bg-secondary/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring" aria-label="Open preview in new tab" title="Open preview in new tab">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /></svg>
                        </a>
                        <button type="button" @click="stageDark = ! stageDark" class="inline-flex h-7 w-7 items-center justify-center rounded-small text-foreground/40 outline-none transition hover:bg-secondary/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring" aria-label="Toggle preview theme" title="Toggle preview theme">
                            <svg x-show="!stageDark" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" /></svg>
                            <svg x-show="stageDark" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" /></svg>
                        </button>
                        <div class="mx-0.5 h-4 w-px bg-foreground/10"></div>
                        <button type="button" @click="reset()" class="inline-flex h-7 items-center gap-1.5 rounded-small px-2 text-xs font-medium text-foreground/40 outline-none transition hover:bg-secondary/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring" aria-label="Reset">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /></svg>
                            Reset
                        </button>
                    </div>
                </div>

                {{-- Dotted surface — flips to the dark palette with the stage theme so a
                     dark component sits on a dark surface. --}}
                <div class="flex min-h-0 flex-1 flex-col items-center overflow-auto bg-background bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] [background-size:18px_18px] p-6 transition-colors duration-300" :class="{ 'dark': stageDark }">
                    {{-- Two stacked iframes double-buffer the preview: the next
                         state loads in the hidden one and crossfades in when
                         ready, so knob changes never flash a blank reload.
                         Constrained widths get a device-like frame so the
                         viewport boundary is visible against the dots. --}}
                    <div class="relative w-full max-w-full flex-1 overflow-hidden rounded-medium border transition-[width,box-shadow,border-color,background-color] duration-300"
                        :class="stageWidth === '100%' ? 'border-transparent bg-transparent' : 'border-foreground/10 bg-background shadow-md'"
                        :style="'width: ' + stageWidth" style="min-height: 14rem;">
                        <iframe x-ref="stage0" title="{{ $meta['label'] }} preview"
                            class="absolute inset-0 h-full w-full transition-opacity duration-200"
                            :class="activeStage === 0 ? 'opacity-100' : 'pointer-events-none opacity-0'"
                            :aria-hidden="activeStage !== 0 ? 'true' : null"></iframe>
                        <iframe x-ref="stage1" title="{{ $meta['label'] }} preview"
                            class="absolute inset-0 h-full w-full opacity-0 transition-opacity duration-200"
                            :class="activeStage === 1 ? 'opacity-100' : 'pointer-events-none opacity-0'"
                            :aria-hidden="activeStage !== 1 ? 'true' : null"></iframe>
                    </div>
                </div>
            </div>

            {{-- ===== Resize handle (drag or arrow keys to size the drawer, double-click to reset) ===== --}}
            <div @pointerdown="startResize($event)" @dblclick="resetDrawer()"
                @keydown.arrow-up.prevent="drawerHeight = Math.min(640, drawerHeight + 24)"
                @keydown.arrow-down.prevent="drawerHeight = Math.max(140, drawerHeight - 24)"
                role="separator" aria-orientation="horizontal" aria-label="Resize controls drawer" tabindex="0"
                class="group flex h-2 shrink-0 cursor-row-resize touch-none items-center justify-center border-t border-foreground/10 bg-card outline-none transition hover:bg-secondary/50 focus-visible:bg-secondary/50">
                <div class="h-0.5 w-8 rounded-full bg-foreground/15 transition group-hover:bg-foreground/30 group-focus-visible:bg-foreground/40"></div>
            </div>

            {{-- ===== Bottom drawer (Controls / Code) ===== --}}
            <div class="flex shrink-0 flex-col overflow-hidden bg-card" :class="drawerAnimating ? 'transition-[height] duration-300 ease-out' : ''" :style="'height: ' + drawerHeight + 'px'">
                <div class="flex items-center justify-between border-b border-foreground/10 pl-2 pr-2">
                    <div class="flex items-center">
                        <button type="button" @click="drawerTab = 'controls'"
                            :class="drawerTab === 'controls' ? 'border-foreground text-foreground' : 'border-transparent text-foreground/50 hover:text-foreground'"
                            class="-mb-px border-b-2 px-3 py-2.5 text-[13px] font-medium outline-none transition focus-visible:text-foreground">Controls</button>
                        <button type="button" @click="drawerTab = 'code'"
                            :class="drawerTab === 'code' ? 'border-foreground text-foreground' : 'border-transparent text-foreground/50 hover:text-foreground'"
                            class="-mb-px border-b-2 px-3 py-2.5 text-[13px] font-medium outline-none transition focus-visible:text-foreground">Code</button>
                    </div>
                    <button type="button" x-show="drawerTab === 'code'" @click="copy()"
                        class="inline-flex h-7 items-center gap-1.5 rounded-small px-2 text-xs font-medium text-foreground/50 outline-none transition hover:bg-secondary/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">
                        <svg x-show="!copied" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></svg>
                        <svg x-show="copied" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="scale-50 opacity-0" x-transition:enter-end="scale-100 opacity-100" class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>

                <div class="studio-scroll min-h-0 flex-1 overflow-y-auto">
                    {{-- Controls --}}
                    <div x-show="drawerTab === 'controls'">
                        @if (count($meta['props']))
                            <div class="px-4 pb-1 pt-3 text-[0.7rem] font-medium uppercase tracking-wider text-foreground/40">Props</div>
                            @foreach ($meta['props'] as $prop)
                                <x-devdojo-components::studio.knob :prop="$prop" />
                            @endforeach
                        @endif

                        <div class="px-4 pb-1 pt-4 text-[0.7rem] font-medium uppercase tracking-wider text-foreground/40">Slots</div>
                        @foreach (array_keys($slotValues) as $slotName)
                            <div class="grid grid-cols-1 items-start gap-2 border-b border-foreground/10 px-4 py-3 last:border-0 sm:grid-cols-[10rem_1fr]">
                                <div class="flex items-center gap-2 pt-1.5">
                                    <code class="rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[12px] text-foreground/80">{{ $slotName }}</code>
                                    @if ($slotName === 'slot')<span class="text-[10px] text-foreground/40">default</span>@endif
                                </div>
                                <textarea x-model="slots['{{ $slotName }}']" @input.debounce.300ms="update()" rows="2"
                                    class="w-full resize-y rounded-medium border border-input bg-card px-2.5 py-1.5 font-mono text-xs text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring"></textarea>
                            </div>
                        @endforeach
                    </div>

                    {{-- Code --}}
                    <div x-show="drawerTab === 'code'" x-cloak>
                        <pre class="m-0 overflow-auto p-4 font-mono text-[13px] leading-relaxed text-foreground/90"><code x-text="code">{{ $initialCode }}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Docs example iframes report their content height (see preview view);
        // grow each one to fit, with its declared height as the floor.
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || event.data?.type !== 'dd-preview-height') { return; }
            for (const frame of document.querySelectorAll('iframe[data-auto-height]')) {
                if (frame.contentWindow === event.source) {
                    frame.style.height = event.data.height + 'px';
                }
            }
        });

        function studioPlayground(config) {
            return {
                attrs: config.attrs,
                slots: config.slots,
                code: '',
                copied: false,
                stageDark: document.documentElement.classList.contains('dark'),
                stageWidth: '100%',
                stageSrc: '',
                activeStage: 0,
                drawerTab: 'controls',
                drawerHeight: 320,
                drawerAnimating: false,
                _initial: JSON.parse(JSON.stringify({ attrs: config.attrs, slots: config.slots })),
                _timer: null,
                _loadToken: 0,

                init() {
                    // Booleans arrive as query strings — normalize before binding knobs.
                    for (const key in this.attrs) {
                        if (config.types[key] === 'boolean') {
                            this.attrs[key] = this.attrs[key] === true || this.attrs[key] === 'true';
                        }
                    }
                    this._initial = JSON.parse(JSON.stringify({ attrs: this.attrs, slots: this.slots }));
                    this.generateCode();
                    this.stageSrc = this.previewSrc();
                    this.$refs.stage0.src = this.stageSrc;
                    this.$watch('stageDark', () => this.refresh());
                },

                update() {
                    this.generateCode();
                    clearTimeout(this._timer);
                    this._timer = setTimeout(() => this.refresh(), 250);
                    this.syncUrl();
                },

                // Double-buffered refresh: load the next state into the hidden
                // iframe and only swap once it has fully rendered.
                refresh() {
                    clearTimeout(this._timer);
                    const next = this.previewSrc();
                    if (next === this.stageSrc) { return; }
                    this.stageSrc = next;
                    const incomingIndex = this.activeStage === 0 ? 1 : 0;
                    const incoming = this.$refs['stage' + incomingIndex];
                    const token = ++this._loadToken;
                    incoming.addEventListener('load', () => {
                        if (token === this._loadToken) { this.activeStage = incomingIndex; }
                    }, { once: true });
                    incoming.src = next;
                },

                resetDrawer() {
                    this.drawerAnimating = true;
                    this.drawerHeight = 320;
                    setTimeout(() => this.drawerAnimating = false, 350);
                },

                previewSrc() {
                    const params = this.stateParams();
                    params.set('theme', this.stageDark ? 'dark' : 'light');
                    return config.previewBase + '?' + params.toString();
                },

                // Stringify for baseline comparison — array props (e.g. the command
                // palette's items) can't be compared with String() directly.
                comparable(value) {
                    if (value === null || value === undefined) return '';
                    return typeof value === 'object' ? JSON.stringify(value) : String(value);
                },

                stateParams() {
                    const params = new URLSearchParams();
                    for (const key in this.attrs) {
                        const value = this.attrs[key];
                        if (value === '' || value === null || value === undefined) continue;
                        // The server re-applies the baseline for absent params, so
                        // only deviations need to travel in the URL.
                        if (this.comparable(value) === this.comparable(config.baseline[key])) continue;
                        params.set('attrs[' + key + ']', typeof value === 'boolean' ? String(value) : value);
                    }
                    for (const key in this.slots) {
                        if (! this.slots[key]) continue;
                        if (this.comparable(this.slots[key]) === this.comparable(config.baselineSlots[key])) continue;
                        params.set('slots[' + key + ']', this.slots[key]);
                    }
                    return params;
                },

                syncUrl() {
                    const params = this.stateParams();
                    params.set('tab', 'build');
                    history.replaceState(null, '', window.location.pathname + '?' + params.toString());
                },

                copy() {
                    ddCopy(this.code);
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                },

                reset() {
                    this.attrs = JSON.parse(JSON.stringify(this._initial.attrs));
                    this.slots = JSON.parse(JSON.stringify(this._initial.slots));
                    this.update();
                },

                // Drag the handle to resize the controls drawer (Storybook-style).
                // Pointer capture keeps the drag alive over the iframe and
                // makes it work with touch and pen input too.
                startResize(event) {
                    event.preventDefault();
                    const handle = event.currentTarget;
                    const startY = event.clientY;
                    const startHeight = this.drawerHeight;
                    const onMove = (e) => {
                        this.drawerHeight = Math.max(140, Math.min(640, startHeight + (startY - e.clientY)));
                    };
                    const onUp = () => {
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        handle.removeEventListener('pointercancel', onUp);
                        document.body.style.userSelect = '';
                    };
                    document.body.style.userSelect = 'none';
                    handle.setPointerCapture(event.pointerId);
                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                    handle.addEventListener('pointercancel', onUp);
                },

                // ----- code generation: MUST mirror DevDojo\Components\CodeGenerator -----

                escapeHtml(value) {
                    return String(value)
                        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                        .replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/'/g, '&#039;');
                },

                phpLiteral(value) {
                    if (Array.isArray(value)) {
                        return '[' + value.map(v => this.phpLiteral(v)).join(', ') + ']';
                    }
                    if (value !== null && typeof value === 'object') {
                        return '[' + Object.entries(value).map(([k, v]) =>
                            "'" + this.phpStringEscape(String(k)) + "' => " + this.phpLiteral(v)).join(', ') + ']';
                    }
                    if (typeof value === 'string') return "'" + this.phpStringEscape(value) + "'";
                    if (typeof value === 'boolean') return value ? 'true' : 'false';
                    if (value === null) return 'null';
                    return String(value);
                },

                // Mirrors PHP's addslashes(): backslashes first, then single quotes.
                phpStringEscape(value) {
                    return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                },

                // A raw double quote inside a :key="literal" bound attribute would
                // terminate the attribute early in Blade's component tag compiler —
                // mirrors CodeGenerator::boundAttribute() dropping such attributes.
                boundAttribute(key, literal) {
                    return literal.includes('"') ? null : ':' + key + '="' + literal + '"';
                },

                attributeLine(key, value) {
                    if (value === '' || value === null || value === undefined || value === false || value === 'false') return null;
                    if (value === true || value === 'true') return key;
                    if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                        return this.boundAttribute(key, this.phpLiteral(value));
                    }
                    if (typeof value === 'string' && (value.startsWith('[') || value.startsWith('{'))) {
                        try { return this.boundAttribute(key, this.phpLiteral(JSON.parse(value))); } catch (e) { /* fall through */ }
                    }
                    return key + '="' + this.escapeHtml(value) + '"';
                },

                generateCode() {
                    const tag = 'x-' + config.name;
                    const attrLines = [];

                    for (const key in this.attrs) {
                        // Omit values equal to the prop default so the snippet stays minimal.
                        if (String(this.attrs[key]) === String(config.defaults[key] ?? '')) continue;
                        const line = this.attributeLine(key, this.attrs[key]);
                        if (line !== null) attrLines.push('    ' + line);
                    }

                    const open = attrLines.length === 0 ? '<' + tag : '<' + tag + '\n' + attrLines.join('\n') + '\n';
                    const defaultSlot = (this.slots['slot'] || '').trim();
                    const named = Object.entries(this.slots)
                        .filter(([name, content]) => name !== 'slot' && (content || '').trim() !== '');

                    if (!defaultSlot && named.length === 0) {
                        this.code = open + (attrLines.length === 0 ? ' />' : '/>');
                        return;
                    }

                    // Concatenated below so Blade's ComponentTagCompiler never sees the
                    // raw slot-tag token in this file's source (see brief note).
                    const slotOpen = '<' + 'x-slot:';
                    const slotClose = '</' + 'x-slot:';

                    const lines = [open + '>'];
                    if (defaultSlot) lines.push('    ' + defaultSlot);
                    for (const [name, content] of named) {
                        lines.push('    ' + slotOpen + name + '>' + content.trim() + slotClose + name + '>');
                    }
                    lines.push('</' + tag + '>');
                    this.code = lines.join('\n');
                }
            };
        }
    </script>
</x-devdojo-components::studio.layout>
