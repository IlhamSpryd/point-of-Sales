<x-devdojo-components::studio.layout
    :categories="$categories"
    :current-guide="$page"
    :title="\Illuminate\Support\Str::headline($page).' — DevDojo Components'">

    @php
        $baseUrl = rtrim(route('devdojo-components.showcase'), '/');
        $guideOrder = [
            'introduction' => 'Introduction',
            'installation' => 'Installation',
            'usage' => 'Usage',
            'styling' => 'Styling',
        ];
        $guideKeys = array_keys($guideOrder);
        $guideIndex = array_search($page, $guideKeys, true);
        $prevGuide = $guideIndex !== false && $guideIndex > 0 ? $guideKeys[$guideIndex - 1] : null;
        $nextGuide = $guideIndex !== false && $guideIndex < count($guideKeys) - 1 ? $guideKeys[$guideIndex + 1] : null;
    @endphp

    <div class="mx-auto max-w-3xl pb-16">
        {{-- A lightweight prose treatment applied via child-combinator utilities so
             each guide partial can stay clean, semantic HTML. --}}
        <article @class([
            '[&_h1]:text-balance [&_h1]:text-2xl [&_h1]:font-bold [&_h1]:tracking-tight',
            '[&_.lede]:mt-2 [&_.lede]:text-pretty [&_.lede]:text-[15px] [&_.lede]:leading-7 [&_.lede]:text-foreground/55',
            '[&_h2]:mt-11 [&_h2]:mb-1 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:scroll-mt-24',
            '[&_h3]:mt-7 [&_h3]:mb-1 [&_h3]:text-[15px] [&_h3]:font-semibold',
            '[&_p]:mt-3 [&_p]:text-[15px] [&_p]:leading-7 [&_p]:text-foreground/70',
            '[&_ul]:mt-4 [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-2 [&_ul]:pl-1 [&_ul]:text-[15px] [&_ul]:leading-6 [&_ul]:text-foreground/70',
            '[&_li]:relative [&_li]:pl-5 [&_li]:before:absolute [&_li]:before:left-1 [&_li]:before:top-[0.6rem] [&_li]:before:h-1 [&_li]:before:w-1 [&_li]:before:rounded-full [&_li]:before:bg-foreground/30',
            '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:decoration-foreground/25 [&_a]:underline-offset-4 hover:[&_a]:decoration-foreground',
            '[&_strong]:font-semibold [&_strong]:text-foreground',
            '[&_:not(pre)>code]:rounded-small [&_:not(pre)>code]:bg-secondary [&_:not(pre)>code]:px-1.5 [&_:not(pre)>code]:py-0.5 [&_:not(pre)>code]:font-mono [&_:not(pre)>code]:text-[13px] [&_:not(pre)>code]:text-foreground/85',
        ])>
            @include($content)
        </article>

        {{-- Prev / next guide pager --}}
        <nav class="mt-14 flex items-stretch justify-between gap-4 border-t border-foreground/10 pt-6" aria-label="Adjacent guides">
            @if ($prevGuide)
                <a href="{{ $baseUrl }}/guide/{{ $prevGuide }}"
                    class="group flex flex-col items-start gap-1 rounded-medium px-1 py-1 outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <span class="text-xs text-foreground/45">Previous</span>
                    <span class="flex items-center gap-1.5 text-sm font-medium text-foreground/75 transition-colors group-hover:text-foreground">
                        <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:-translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5" /><path d="m12 19-7-7 7-7" /></svg>
                        {{ $guideOrder[$prevGuide] }}
                    </span>
                </a>
            @else
                <span></span>
            @endif
            @if ($nextGuide)
                <a href="{{ $baseUrl }}/guide/{{ $nextGuide }}"
                    class="group flex flex-col items-end gap-1 rounded-medium px-1 py-1 text-right outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <span class="text-xs text-foreground/45">Next</span>
                    <span class="flex items-center gap-1.5 text-sm font-medium text-foreground/75 transition-colors group-hover:text-foreground">
                        {{ $guideOrder[$nextGuide] }}
                        <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                    </span>
                </a>
            @else
                <a href="{{ $baseUrl }}"
                    class="group flex flex-col items-end gap-1 rounded-medium px-1 py-1 text-right outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <span class="text-xs text-foreground/45">Next</span>
                    <span class="flex items-center gap-1.5 text-sm font-medium text-foreground/75 transition-colors group-hover:text-foreground">
                        Browse components
                        <svg class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                    </span>
                </a>
            @endif
        </nav>
    </div>
</x-devdojo-components::studio.layout>
