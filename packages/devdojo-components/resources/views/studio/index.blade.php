<x-devdojo-components::studio.layout :categories="$categories" width="wide">
    @php
        $baseUrl = rtrim(route('devdojo-components.showcase'), '/');
        $totalComponents = collect($categories)->flatMap(fn ($components) => $components)->count();
        $allHaystacks = collect($categories)
            ->flatMap(fn ($components) => $components)
            ->map(fn ($component) => $component['label'].' '.$component['name'].' '.$component['description'])
            ->values()->all();
    @endphp

    <div class="border-b border-foreground/10 pb-8">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary px-2.5 py-1 text-[11px] font-medium text-foreground/60">
            <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
            v1 · Blade + Alpine · {{ $totalComponents }} components
        </span>
        <h1 class="mt-3 text-balance text-2xl font-bold tracking-tight sm:text-3xl">DevDojo Components</h1>
        <p class="mt-2 max-w-2xl text-pretty text-[15px] leading-7 text-foreground/60">
            Beautiful, accessible Blade + Alpine components you own. Browse the collection, tune the props in a live
            playground, then publish any component straight into <code class="rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[12px]">resources/views/components</code>.
        </p>
        <div class="mt-5 flex flex-wrap items-center gap-2.5">
            <a href="{{ $baseUrl }}/guide/introduction"
                class="inline-flex h-8 items-center rounded-medium bg-primary px-3.5 text-[13px] font-medium text-primary-foreground outline-none transition hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background active:scale-[0.98]">
                Get started
            </a>
            <a href="{{ $baseUrl }}/guide/installation"
                class="inline-flex h-8 items-center rounded-medium border border-foreground/10 bg-card px-3.5 text-[13px] font-medium text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]">
                Installation
            </a>
            <button type="button" @click="$dispatch('command-open')"
                class="inline-flex h-8 items-center gap-2 rounded-medium border border-foreground/10 bg-card pl-3 pr-1.5 text-[13px] text-foreground/45 outline-none transition hover:border-foreground/20 hover:text-foreground/70 focus-visible:ring-2 focus-visible:ring-ring sm:ml-auto">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                Search components…
                <x-components.kbd class="ml-4">⌘K</x-components.kbd>
            </button>
        </div>
    </div>

    <div class="flex flex-col gap-12 pt-8">
        @foreach ($categories as $category => $components)
            @php
                $categoryHaystacks = collect($components)
                    ->map(fn ($component) => $component['label'].' '.$component['name'].' '.$component['description'])
                    ->values()->all();
            @endphp
            <section id="{{ Str::slug($category) }}" class="scroll-mt-28 lg:scroll-mt-10"
                x-show="matchesAny(@js($categoryHaystacks))">
                <h2 class="text-[13px] font-semibold uppercase tracking-wider text-foreground/45">
                    {{ $category }}
                    <span class="ml-1 font-normal tabular-nums text-foreground/30" x-show="query.trim() === ''">{{ count($components) }}</span>
                </h2>
                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                    @foreach ($components as $component)
                        <div x-show="matches(@js($component['label'].' '.$component['name'].' '.$component['description']))"
                            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="scale-[0.98] opacity-0" x-transition:enter-end="scale-100 opacity-100"
                            x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="scale-[0.98] opacity-0">
                            <x-devdojo-components::studio.card :component="$component" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Empty state while filtering --}}
        <div x-show="query.trim() !== '' && ! matchesAny(@js($allHaystacks))" x-cloak
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="scale-[0.99] opacity-0" x-transition:enter-end="scale-100 opacity-100"
            class="flex flex-col items-center gap-1.5 rounded-large border border-dashed border-foreground/15 px-6 py-20 text-center">
            <svg class="h-5 w-5 text-foreground/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
            <p class="mt-2 text-sm font-medium text-foreground">No components match &ldquo;<span x-text="query.trim()"></span>&rdquo;</p>
            <p class="text-sm text-foreground/50">Try a different name, or browse the sidebar categories.</p>
            <button type="button" @click="query = ''"
                class="mt-3 inline-flex h-8 items-center rounded-medium border border-foreground/10 bg-card px-3 text-[13px] font-medium text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]">
                Clear filter
            </button>
        </div>
    </div>
</x-devdojo-components::studio.layout>
