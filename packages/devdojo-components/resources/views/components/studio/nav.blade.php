{{--
    Shared sidebar navigation — included by the desktop <aside> and the mobile
    drawer so both stay in sync. Expects $baseUrl, $categories, $current and
    $currentGuide from the including scope.
--}}
@php
    // Guide icons are Phosphor (the set KatanaUI's docs use): hand-waving,
    // floppy-disk, puzzle-piece and paint-brush.
    $guides = [
        ['page' => 'introduction', 'label' => 'Introduction', 'icon' => '<path d="M220.17,100,202.86,70a28,28,0,0,0-38.24-10.25,27.69,27.69,0,0,0-9,8.34L138.2,38a28,28,0,0,0-48.48,0A28,28,0,0,0,48.15,74l1.59,2.76A27.67,27.67,0,0,0,38,80.41a28,28,0,0,0-10.24,38.25l40,69.32a87.47,87.47,0,0,0,53.43,41,88.56,88.56,0,0,0,22.92,3,88,88,0,0,0,76.06-132Zm-6.66,62.64A72,72,0,0,1,81.62,180l-40-69.32a12,12,0,0,1,20.78-12L81.63,132a8,8,0,1,0,13.85-8L62,66A12,12,0,1,1,82.78,54L114,108a8,8,0,1,0,13.85-8L103.57,58h0a12,12,0,1,1,20.78-12l33.42,57.9a48,48,0,0,0-5.54,60.6,8,8,0,0,0,13.24-9A32,32,0,0,1,172.78,112a8,8,0,0,0,2.13-10.4L168.23,90A12,12,0,1,1,189,78l17.31,30A71.56,71.56,0,0,1,213.51,162.62ZM184.25,31.71A8,8,0,0,1,194,26a59.62,59.62,0,0,1,36.53,28l.33.57a8,8,0,1,1-13.85,8l-.33-.57a43.67,43.67,0,0,0-26.8-20.5A8,8,0,0,1,184.25,31.71ZM80.89,237a8,8,0,0,1-11.23,1.33A119.56,119.56,0,0,1,40.06,204a8,8,0,0,1,13.86-8,103.67,103.67,0,0,0,25.64,29.72A8,8,0,0,1,80.89,237Z"/>'],
        ['page' => 'installation', 'label' => 'Installation', 'icon' => '<path d="M219.31,72,184,36.69A15.86,15.86,0,0,0,172.69,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V83.31A15.86,15.86,0,0,0,219.31,72ZM168,208H88V152h80Zm40,0H184V152a16,16,0,0,0-16-16H88a16,16,0,0,0-16,16v56H48V48H172.69L208,83.31ZM160,72a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h56A8,8,0,0,1,160,72Z"/>'],
        ['page' => 'usage', 'label' => 'Usage', 'icon' => '<path d="M220.27,158.54a8,8,0,0,0-7.7-.46,20,20,0,1,1,0-36.16A8,8,0,0,0,224,114.69V72a16,16,0,0,0-16-16H171.78a35.36,35.36,0,0,0,.22-4,36.11,36.11,0,0,0-11.36-26.24,36,36,0,0,0-60.55,23.62,36.56,36.56,0,0,0,.14,6.62H64A16,16,0,0,0,48,72v32.22a35.36,35.36,0,0,0-4-.22,36.12,36.12,0,0,0-26.24,11.36,35.7,35.7,0,0,0-9.69,27,36.08,36.08,0,0,0,33.31,33.6,35.68,35.68,0,0,0,6.62-.14V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V165.31A8,8,0,0,0,220.27,158.54ZM208,208H64V165.31a8,8,0,0,0-11.43-7.23,20,20,0,1,1,0-36.16A8,8,0,0,0,64,114.69V72h46.69a8,8,0,0,0,7.23-11.43,20,20,0,1,1,36.16,0A8,8,0,0,0,161.31,72H208v32.23a35.68,35.68,0,0,0-6.62-.14A36,36,0,0,0,204,176a35.36,35.36,0,0,0,4-.22Z"/>'],
        ['page' => 'styling', 'label' => 'Styling', 'icon' => '<path d="M232,32a8,8,0,0,0-8-8c-44.08,0-89.31,49.71-114.43,82.63A60,60,0,0,0,32,164c0,30.88-19.54,44.73-20.47,45.37A8,8,0,0,0,16,224H92a60,60,0,0,0,57.37-77.57C182.3,121.31,232,76.08,232,32ZM92,208H34.63C41.38,198.41,48,183.92,48,164a44,44,0,1,1,44,44Zm32.42-94.45q5.14-6.66,10.09-12.55A76.23,76.23,0,0,1,155,121.49q-5.9,4.94-12.55,10.09A60.54,60.54,0,0,0,124.42,113.55Zm42.7-2.68a92.57,92.57,0,0,0-22-22c31.78-34.53,55.75-45,69.9-47.91C212.17,55.12,201.65,79.09,167.12,110.87Z"/>'],
    ];

    $allHaystacks = collect($categories)
        ->flatMap(fn ($components) => $components)
        ->map(fn ($component) => $component['label'].' '.$component['name'].' '.($component['description'] ?? ''))
        ->values()->all();
@endphp

{{-- Getting Started guides --}}
<nav class="mt-5 flex flex-col gap-px" aria-label="Getting started">
    <p class="px-2 pb-1.5 text-[0.7rem] font-medium uppercase tracking-wider text-foreground/40">Getting Started</p>
    @foreach ($guides as $guide)
        <a href="{{ $baseUrl }}/guide/{{ $guide['page'] }}"
            @if ($currentGuide === $guide['page']) aria-current="page" @endif
            @class([
                'relative flex items-center gap-2 rounded-medium px-2 py-[5px] text-[13px] font-medium outline-none transition-colors duration-100 focus-visible:ring-2 focus-visible:ring-ring',
                'text-foreground' => $currentGuide === $guide['page'],
                'text-foreground/55 hover:bg-secondary/60 hover:text-foreground' => $currentGuide !== $guide['page'],
            ])>
            @if ($currentGuide === $guide['page'])
                <span class="dd-nav-pill absolute inset-0 rounded-medium bg-secondary" aria-hidden="true"></span>
            @endif
            <svg class="relative h-4 w-4 shrink-0 {{ $currentGuide === $guide['page'] ? 'text-foreground/80' : 'text-foreground/45' }}" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">{!! $guide['icon'] !!}</svg>
            <span class="relative">{{ $guide['label'] }}</span>
        </a>
    @endforeach
</nav>

{{-- Component categories --}}
<nav class="mt-6 flex flex-col gap-6" aria-label="Components">
    @foreach ($categories as $category => $components)
        @php
            $categoryHaystacks = collect($components)
                ->map(fn ($component) => $component['label'].' '.$component['name'].' '.($component['description'] ?? ''))
                ->values()->all();
        @endphp
        <div x-show="matchesAny(@js($categoryHaystacks))">
            <p class="px-2 text-[0.7rem] font-medium uppercase tracking-wider text-foreground/40">{{ $category }}</p>
            <div class="mt-1.5 flex flex-col gap-px">
                @foreach ($components as $component)
                    <a href="{{ $baseUrl }}/{{ $component['name'] }}"
                        x-show="matches(@js($component['label'].' '.$component['name'].' '.($component['description'] ?? '')))"
                        @if ($current === $component['name']) aria-current="page" @endif
                        @class([
                            'relative flex items-center rounded-medium px-2 py-[5px] text-[13px] font-medium outline-none transition-colors duration-100 focus-visible:ring-2 focus-visible:ring-ring',
                            'text-foreground' => $current === $component['name'],
                            'text-foreground/55 hover:bg-secondary/60 hover:text-foreground' => $current !== $component['name'],
                        ])>
                        @if ($current === $component['name'])
                            <span class="dd-nav-pill absolute inset-0 rounded-medium bg-secondary" aria-hidden="true"></span>
                        @endif
                        <span class="relative">{{ $component['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Empty state while filtering --}}
    <div x-show="query.trim() !== '' && ! matchesAny(@js($allHaystacks))" x-cloak
        class="flex flex-col gap-2 px-2 py-2">
        <p class="text-[13px] text-foreground/45">No components found.</p>
        <button type="button" @click="query = ''"
            class="self-start rounded-small text-[13px] font-medium text-foreground/70 underline decoration-foreground/25 underline-offset-4 outline-none transition hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">
            Clear filter
        </button>
    </div>
</nav>
