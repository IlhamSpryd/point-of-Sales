{{--
    Adaptive Favicon — Apeiron System
    Official Brand Mark Kit v1.0
    Automatically adapts to user's browser / search engine color scheme (Dark Mode / Light Mode):
    - Modern Browsers: SVG vector with media-query adaptive color
    - Dark mode fallback: White logo (#FFFFFF)
    - Light mode fallback: Dark ink logo (#171717)
--}}
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon-dark.png') }}" media="(prefers-color-scheme: dark)" type="image/png">
<link rel="icon" href="{{ asset('favicon.png') }}" media="(prefers-color-scheme: light)" type="image/png">
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
<link rel="apple-touch-icon" href="{{ asset('favicon.png') }}">
<link rel="icon" id="apeiron-app-favicon" href="{{ asset('favicon.png') }}" type="image/png">
<script>
    (function() {
        if (!window.matchMedia) return;
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var darkIcon = "{{ asset('favicon-dark.png') }}";
        var lightIcon = "{{ asset('favicon.png') }}";
        function syncFavicon() {
            var icon = document.getElementById('apeiron-app-favicon');
            if (icon) {
                icon.href = mq.matches ? darkIcon : lightIcon;
            }
        }
        syncFavicon();
        if (mq.addEventListener) {
            mq.addEventListener('change', syncFavicon);
        } else if (mq.addListener) {
            mq.addListener(syncFavicon);
        }
    })();
</script>