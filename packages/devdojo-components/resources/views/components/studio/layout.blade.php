@props([
    'categories',
    'current' => null,
    'currentGuide' => null,
    'title' => 'DevDojo Components',
    // 'default' centers reading-width content and shows the "On this page"
    // rail on very wide screens; 'wide' is for the browse grid.
    'width' => 'default',
])

@php
    // Flatten byCategory() so palette groups stay contiguous and in the
    // sidebar's display order (byCategory already encodes that order).
    $paletteItems = \DevDojo\Components\Components::byCategory()
        ->flatMap(fn ($components) => $components)
        ->map(fn (array $component) => [
            'title' => $component['label'],
            'value' => $component['name'],
            'group' => $component['category'],
        ])->values()->all();
    $baseUrl = rtrim(route('devdojo-components.showcase'), '/');

    $faviconMark = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 39 39"><path fill="{color}" d="M13.71 13.71v11.495h11.495V13.71zM0 27.504V39h11.496V27.504zM0 0v11.496h11.496V0z"/><path fill="{color}" fill-opacity=".25" d="M27.41 13.71v11.495h11.496V13.71zM13.701 27.504V39h11.496V27.504zM13.701 0v11.496h11.496V0z"/></svg>');
@endphp

<!DOCTYPE html>
<html lang="en" class="motion-safe:scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="Beautiful, accessible Blade + Alpine components for Laravel — published straight into your app, owned and customized by you.">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">

    <link rel="icon" href="data:image/svg+xml,{{ str_replace(rawurlencode('{color}'), '%23111111', $faviconMark) }}" media="(prefers-color-scheme: light)">
    <link rel="icon" href="data:image/svg+xml,{{ str_replace(rawurlencode('{color}'), '%23fafafa', $faviconMark) }}" media="(prefers-color-scheme: dark)">

    {{-- Set the theme before paint to avoid a flash of the wrong mode. --}}
    <script>
        (() => {
            const stored = localStorage.getItem('dd-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const dark = stored === 'dark' || (!stored && prefersDark);
            if (dark) {
                document.documentElement.classList.add('dark');
            }
            // Keep the browser chrome (mobile address bar) in sync when the
            // stored theme overrides the system preference.
            window.ddSyncThemeColor = (isDark) => {
                document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
                    meta.content = isDark ? '#0a0a0a' : '#ffffff';
                });
            };
            if (stored) { window.ddSyncThemeColor(dark); }
        })();
    </script>

    @if (file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
        @vite(config('components.showcase.assets', ['resources/css/app.css']))
    @endif

    @if (class_exists(\Livewire\Livewire::class))
        @livewireStyles
    @else
        <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @endif

    <style>
        /* Thin, quiet scrollbars that only appear while you're over the area. */
        .studio-scroll {
            scrollbar-width: thin;
            scrollbar-color: transparent transparent;
        }
        .studio-scroll:hover,
        .studio-scroll:focus-within {
            scrollbar-color: color-mix(in oklab, var(--foreground) 20%, transparent) transparent;
        }

        /* Butter-smooth cross-page navigation (progressive enhancement —
           browsers without the View Transitions API just navigate normally).
           The sidebar and top bar keep their own snapshot so the frame feels
           fixed. The active nav pill is NOT a view-transition layer on purpose:
           named layers always paint above the sidebar snapshot, which would
           drag the label along and cover other items mid-flight. Instead the
           .dd-nav-pill background slides via the FLIP script below the aside,
           so only the background moves and labels stay put. */
        @media (prefers-reduced-motion: no-preference) {
            @view-transition {
                navigation: auto;
            }
            aside[aria-label="Sidebar"] {
                view-transition-name: dd-sidebar;
            }
            #dd-mobile-topbar {
                view-transition-name: dd-topbar;
            }
            ::view-transition-old(root),
            ::view-transition-new(root) {
                animation-duration: 180ms;
            }
        }
    </style>
</head>

<body class="bg-background text-foreground antialiased"
    x-data="{
        dark: document.documentElement.classList.contains('dark'),
        query: '',
        mobileNav: false,
        toggle() {
            const apply = () => {
                this.dark = !this.dark;
                document.documentElement.classList.toggle('dark', this.dark);
                localStorage.setItem('dd-theme', this.dark ? 'dark' : 'light');
                window.ddSyncThemeColor(this.dark);
                window.dispatchEvent(new CustomEvent('dd-theme-changed', { detail: { dark: this.dark } }));
            };
            // Crossfade the whole page between themes when the browser can.
            if (document.startViewTransition && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.startViewTransition(apply);
            } else {
                apply();
            }
        },
        matches(haystack) {
            return this.query.trim() === '' || haystack.toLowerCase().includes(this.query.trim().toLowerCase());
        },
        matchesAny(haystacks) {
            return this.query.trim() === '' || haystacks.some(haystack => this.matches(haystack));
        }
    }"
    @command-select.window="window.location = '{{ $baseUrl }}/' + $event.detail.value"
    @keydown.escape.window="mobileNav = false"
    @keydown.window="if ($event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable && $refs.filter?.offsetParent) { $event.preventDefault(); $refs.filter.focus(); }">

    <a href="#studio-main"
        class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-medium focus:bg-primary focus:px-3.5 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:outline-none">
        Skip to content
    </a>

    {{-- Toast container + ⌘K palette (single instances for the whole page).
         The palette is opened via ⌘K / the window event only, so its trigger
         wrapper is hidden — an empty inline-block would still add a line box
         above the mobile top bar. --}}
    <x-components.toast />
    <x-components.command :items="$paletteItems" placeholder="Search components…" class="hidden">
        <x-slot:trigger><span class="hidden"></span></x-slot:trigger>
    </x-components.command>

    {{-- Mobile top bar — the desktop layout uses the sidebar instead. --}}
    <div id="dd-mobile-topbar" class="sticky top-0 z-40 flex items-center justify-between gap-3 border-b border-foreground/10 bg-background/80 px-4 py-3 backdrop-blur-md lg:hidden">
        <a href="{{ $baseUrl }}" class="flex items-center gap-2.5 rounded-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <svg class="size-5" viewBox="0 0 39 39" fill="none" aria-hidden="true">
                <path fill="currentColor" d="M13.71 13.71v11.495h11.495V13.71zM0 27.504V39h11.496V27.504zM0 0v11.496h11.496V0z" />
                <path fill="currentColor" fill-opacity=".2" d="M27.41 13.71v11.495h11.496V13.71zM13.701 27.504V39h11.496V27.504zM13.701 0v11.496h11.496V0z" />
            </svg>
            <p class="text-sm font-semibold tracking-tight">Components</p>
        </a>
        <div class="flex items-center gap-2">
            <button @click="toggle()" type="button" aria-label="Toggle theme"
                class="inline-flex h-9 w-9 items-center justify-center rounded-medium border border-foreground/10 bg-card text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-95">
                <svg x-show="!dark" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" /></svg>
                <svg x-show="dark" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" /></svg>
            </button>
            <button @click="mobileNav = true" type="button" aria-label="Open navigation"
                class="inline-flex h-9 w-9 items-center justify-center rounded-medium border border-foreground/10 bg-card text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-95">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
        </div>
    </div>

    {{-- Mobile navigation drawer --}}
    <div x-show="mobileNav" x-cloak x-trap.inert.noscroll="mobileNav" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
        <div x-show="mobileNav" @click="mobileNav = false"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>
        <div x-show="mobileNav"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
            x-effect="if (mobileNav) $nextTick(() => {
                const active = $el.querySelector('[aria-current=page]');
                if (! active) return;
                const offset = active.getBoundingClientRect().top - $el.getBoundingClientRect().top + $el.scrollTop;
                if (offset > $el.clientHeight - 96) { $el.scrollTop = offset - $el.clientHeight / 2; }
            })"
            class="studio-scroll absolute inset-y-0 left-0 flex w-80 max-w-[85vw] flex-col overflow-y-auto overscroll-contain border-r border-foreground/10 bg-background px-4 pb-8 pt-4">
            <div class="flex items-center justify-between">
                <a href="{{ $baseUrl }}" class="flex items-center gap-2.5 px-2.5 text-foreground">
                    <svg class="size-5" viewBox="0 0 39 39" fill="none" aria-hidden="true">
                        <path fill="currentColor" d="M13.71 13.71v11.495h11.495V13.71zM0 27.504V39h11.496V27.504zM0 0v11.496h11.496V0z" />
                        <path fill="currentColor" fill-opacity=".2" d="M27.41 13.71v11.495h11.496V13.71zM13.701 27.504V39h11.496V27.504zM13.701 0v11.496h11.496V0z" />
                    </svg>
                    <p class="text-sm font-semibold tracking-tight">Components</p>
                </a>
                <button @click="mobileNav = false" type="button" aria-label="Close navigation"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-medium text-foreground/50 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <label class="mt-4 flex items-center gap-2 rounded-medium border border-foreground/10 bg-card px-2.5 py-1.5 text-sm text-foreground/70 transition-colors focus-within:border-foreground/20 focus-within:ring-2 focus-within:ring-ring">
                <svg class="h-4 w-4 shrink-0 text-foreground/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                <input type="text" x-model="query" placeholder="Filter…"
                    class="w-full border-none bg-transparent p-0 text-sm text-foreground placeholder:text-foreground/40 focus:outline-none focus:ring-0" />
            </label>

            @include('devdojo-components::components.studio.nav')
        </div>
    </div>

    {{-- ===================== SIDEBAR (pinned to the viewport edge) ===================== --}}
    <aside aria-label="Sidebar" class="studio-scroll fixed inset-y-0 left-0 z-30 hidden w-64 flex-col overflow-y-auto overscroll-contain border-r border-foreground/10 bg-background px-4 pb-6 pt-5 lg:flex"
        x-init="(() => {
            {{-- Scroll position was restored inline below; only recenter when
                 the active item still isn't in view, and remember every move
                 so the sidebar feels like a fixed frame across pages. --}}
            $el.addEventListener('scroll', () => sessionStorage.setItem('dd-sidebar-scroll', $el.scrollTop), { passive: true });
            const active = $el.querySelector('[aria-current=page]');
            if (! active) return;
            const offset = active.getBoundingClientRect().top - $el.getBoundingClientRect().top;
            if (offset < 48 || offset > $el.clientHeight - 96) { $el.scrollTop += offset - $el.clientHeight / 2; }
        })()">
            <div class="flex items-center justify-between px-2">
                <a href="{{ $baseUrl }}" class="flex items-center gap-2 rounded-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <svg class="size-3.5" viewBox="0 0 39 39" fill="none" aria-hidden="true">
                        <path fill="currentColor" d="M13.71 13.71v11.495h11.495V13.71zM0 27.504V39h11.496V27.504zM0 0v11.496h11.496V0z" />
                        <path fill="currentColor" fill-opacity=".2" d="M27.41 13.71v11.495h11.496V13.71zM13.701 27.504V39h11.496V27.504zM13.701 0v11.496h11.496V0z" />
                    </svg>
                    <p class="text-sm font-semibold tracking-tight">Components</p>
                </a>
                <button @click="toggle()" type="button" aria-label="Toggle theme"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-medium border border-foreground/10 bg-card text-foreground/70 outline-none transition hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-95">
                    <svg x-show="!dark" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" /></svg>
                    <svg x-show="dark" x-cloak class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" /></svg>
                </button>
            </div>

            <label class="mt-4 flex items-center gap-2 rounded-medium border border-foreground/10 bg-card px-2 py-1.5 text-[13px] text-foreground/70 transition-colors focus-within:border-foreground/20 focus-within:ring-2 focus-within:ring-ring">
                <svg class="h-3.5 w-3.5 shrink-0 text-foreground/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                <input type="text" x-model="query" x-ref="filter" placeholder="Filter…"
                    @keydown.escape.stop="query = ''; $el.blur()"
                    class="w-full border-none bg-transparent p-0 text-[13px] text-foreground placeholder:text-foreground/40 focus:outline-none focus:ring-0" />
                <x-components.kbd class="h-[18px] text-[10px]">/</x-components.kbd>
            </label>

            @include('devdojo-components::components.studio.nav')

            <a href="https://devdojo.com" target="_blank" rel="noreferrer"
                class="mt-auto flex items-center gap-1.5 rounded-medium px-2 pt-6 text-[13px] text-foreground/50 outline-none transition hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">
                devdojo.com
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10" /><path d="M7 17 17 7" /></svg>
            </a>
        </aside>

    {{-- Restore the sidebar's scroll position before first paint so it reads
         as one continuous frame across page navigations, then FLIP-slide the
         active pill's background from where it sat on the previous page. Only
         the .dd-nav-pill background moves — labels never travel with it. --}}
    <script>
        (() => {
            const aside = document.currentScript.previousElementSibling;
            if (! aside) return;

            const stored = sessionStorage.getItem('dd-sidebar-scroll');
            if (stored !== null) { aside.scrollTop = parseInt(stored, 10); }

            const pill = aside.querySelector('.dd-nav-pill');
            const from = parseFloat(sessionStorage.getItem('dd-nav-pill-top'));
            sessionStorage.removeItem('dd-nav-pill-top');
            if (pill && ! Number.isNaN(from) && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                const shift = from - pill.getBoundingClientRect().top;
                if (Math.abs(shift) > 1) {
                    pill.animate(
                        [{ transform: `translateY(${shift}px)` }, { transform: 'translateY(0)' }],
                        { duration: 250, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
                    );
                }
            }

            {{-- Remember where the pill is when leaving via a sidebar link. --}}
            aside.addEventListener('click', (event) => {
                if (! event.target.closest('a[href]')) return;
                const current = aside.querySelector('.dd-nav-pill');
                if (current) { sessionStorage.setItem('dd-nav-pill-top', current.getBoundingClientRect().top); }
            });
        })();
    </script>

    {{-- ===================== MAIN (centered in the remaining space) ===================== --}}
    <div class="lg:pl-64">
        <main id="studio-main"
            @class([
                'mx-auto flex w-full gap-12 px-4 pb-6 pt-6 sm:px-6 lg:px-10 lg:pb-10 lg:pt-8',
                'max-w-6xl' => $width === 'wide',
                'max-w-4xl min-[90rem]:max-w-[71rem]' => $width !== 'wide',
            ])>
            <div class="min-w-0 flex-1">
                {{ $slot }}

                <footer class="mt-16 flex flex-wrap items-center justify-between gap-3 border-t border-foreground/10 pt-6 text-[13px] text-foreground/45">
                    <p>
                        Built by <a href="https://devdojo.com" target="_blank" rel="noreferrer" class="rounded-small font-medium text-foreground/60 outline-none transition hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">DevDojo</a> — components you own.
                        <span class="mx-1.5 select-none text-foreground/20">·</span>
                        <a href="{{ $baseUrl }}/llms.txt" title="The whole library as one Markdown document for AI assistants" class="rounded-small font-medium text-foreground/60 outline-none transition hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring">llms.txt</a>
                    </p>
                    <p class="flex items-center gap-1.5">
                        <x-components.kbd>⌘K</x-components.kbd>
                        to search
                    </p>
                </footer>
            </div>

            @if ($width !== 'wide')
                {{-- "On this page" rail — built from [data-toc] headings so every
                     docs page gets it for free. Shown on very wide screens only. --}}
                <nav aria-label="On this page" x-cloak
                    x-data="{
                        items: [],
                        active: '',
                        spy() {
                            const visible = this.items.filter(item => document.getElementById(item.id)?.offsetParent);
                            this.count = visible.length;
                            let current = visible[0]?.id ?? '';
                            for (const item of visible) {
                                if (document.getElementById(item.id).getBoundingClientRect().top <= 120) { current = item.id; }
                            }
                            this.active = current;
                        },
                        count: 0
                    }"
                    x-init="(() => {
                        items = [...document.querySelectorAll('#studio-main [data-toc]')].map(el => ({ id: el.id, label: el.dataset.toc || el.textContent.trim() }));
                        // Hover anchors on every TOC-able heading.
                        document.querySelectorAll('#studio-main [data-toc]').forEach(el => {
                            el.classList.add('group');
                            const anchor = document.createElement('a');
                            anchor.href = '#' + el.id;
                            anchor.ariaLabel = 'Link to this section';
                            anchor.textContent = '#';
                            anchor.className = 'ml-2 font-normal text-foreground/35 !no-underline opacity-0 outline-none transition-opacity hover:text-foreground/60 focus-visible:opacity-100 group-hover:opacity-100';
                            el.appendChild(anchor);
                        });
                        spy();
                        window.addEventListener('scroll', () => spy(), { passive: true });
                        window.addEventListener('dd-toc-refresh', () => spy());
                    })()"
                    x-show="count > 1"
                    class="studio-scroll sticky top-8 hidden max-h-[calc(100vh-6rem)] w-44 shrink-0 self-start overflow-y-auto overscroll-contain min-[90rem]:block">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-foreground/40">On this page</p>
                    <ul class="mt-2.5 flex flex-col border-l border-foreground/10">
                        <template x-for="item in items" :key="item.id">
                            <li x-show="count > 0 && document.getElementById(item.id)?.offsetParent">
                                <a :href="'#' + item.id" x-text="item.label" @click="active = item.id"
                                    class="-ml-px block border-l py-1 pl-3.5 text-[13px] leading-5 outline-none transition-colors focus-visible:text-foreground"
                                    :class="active === item.id ? 'border-foreground/60 font-medium text-foreground' : 'border-transparent text-foreground/50 hover:text-foreground'"></a>
                            </li>
                        </template>
                    </ul>
                </nav>
            @endif
        </main>
    </div>

    <script>
        // Instant-feel navigation: prefetch same-origin pages the moment a link
        // is hovered or touched, so the real click hits a warm cache.
        (() => {
            const seen = new Set();
            const handle = (event) => {
                const link = event.target.closest('a[href]');
                if (! link || link.origin !== location.origin || link.target === '_blank' || link.hasAttribute('download')) return;
                const url = link.href.split('#')[0];
                if (url === location.href.split('#')[0] || seen.has(url)) return;
                seen.add(url);
                const hint = document.createElement('link');
                hint.rel = 'prefetch';
                hint.href = url;
                document.head.appendChild(hint);
            };
            document.addEventListener('mouseover', handle, { passive: true });
            document.addEventListener('touchstart', handle, { passive: true });
        })();

        // Shared copy helper for every code block and install snippet.
        window.ddCopy = function (text) {
            if (navigator.clipboard) {
                return navigator.clipboard.writeText(text).catch(() => {});
            }
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        };
    </script>

    {{-- Syntax highlighting: progressive enhancement, plain <pre> without network. --}}
    <script type="module">
        try {
            const { codeToHtml } = await import('https://esm.sh/shiki@1.29.2');
            const theme = () => document.documentElement.classList.contains('dark') ? 'github-dark' : 'github-light';
            const highlight = async () => {
                for (const pre of document.querySelectorAll('pre[data-studio-code]')) {
                    const code = pre.dataset.rawCode ?? pre.querySelector('code')?.textContent ?? '';
                    const lang = pre.dataset.lang || 'blade';
                    pre.dataset.rawCode = code;
                    const html = await codeToHtml(code, { lang, theme: theme() });
                    const replacement = document.createElement('div');
                    replacement.innerHTML = html;
                    const shikiPre = replacement.querySelector('pre');
                    shikiPre.className = pre.className;
                    shikiPre.dataset.studioCode = '';
                    shikiPre.dataset.rawCode = code;
                    shikiPre.dataset.lang = lang;
                    shikiPre.style.backgroundColor = 'transparent';
                    pre.replaceWith(shikiPre);
                }
            };
            await highlight();
            window.addEventListener('dd-theme-changed', highlight);
            window.addEventListener('dd-code-updated', highlight);
        } catch (e) { /* offline: keep the plain <pre> */ }
    </script>

    @if (class_exists(\Livewire\Livewire::class))
        @livewireScripts
    @endif
</body>

</html>
