<!DOCTYPE html>
<html lang="en" @class(['dark' => $theme === 'dark'])>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview</title>

    @if (file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
        @vite(config('components.showcase.assets', ['resources/css/app.css']))
    @endif

    @if (class_exists(\Livewire\Livewire::class))
        @livewireStyles
    @else
        <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @endif
</head>

<body class="text-foreground antialiased" style="margin:0;background:transparent;">
    {{--
        Inline styles (not Tailwind utilities) so the preview always centers even
        if the host's compiled CSS is stale/missing these classes. The body is
        transparent so the studio's gray canvas shows through behind the
        centered component — the Astryx-style "component on a surface" look.
    --}}
    {{-- x-data gives the subtree a root Alpine scope, so component markup that
         relies on an ancestor scope (x-on handlers in examples, the toast's
         teleport template) initializes just like it does in a real layout. --}}
    <div id="dd-preview-wrap" x-data style="min-height:100vh;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:2.5rem 1.5rem;">
        {!! $rendered !!}
    </div>

    @if (class_exists(\Livewire\Livewire::class))
        @livewireScripts
    @endif

    <script>
        // Report the rendered height to the parent page so docs example
        // iframes can grow to fit their content (never below their declared
        // height — the parent keeps that as a min-height floor). The wrapper's
        // in-flow children are measured rather than the document scrollHeight:
        // the wrapper stretches to 100vh for centering and teleported overlays
        // are fixed-position, so both would otherwise feed back into the
        // reported height and grow the iframe forever.
        (() => {
            const wrap = document.getElementById('dd-preview-wrap');
            const paddingBottom = 40; // matches the wrapper's 2.5rem padding
            const report = () => {
                let bottom = 0;
                for (const child of wrap.children) {
                    bottom = Math.max(bottom, child.getBoundingClientRect().bottom + window.scrollY);
                }
                if (bottom === 0) { return; }
                parent.postMessage({
                    type: 'dd-preview-height',
                    height: Math.ceil(bottom + paddingBottom),
                }, window.location.origin);
            };
            new ResizeObserver(report).observe(wrap);
            window.addEventListener('load', report);
        })();
    </script>
</body>

</html>
