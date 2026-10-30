<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', config('app.name'))</title>
    <x-favicon />

    
    <!-- Self-Hosted Fonts Preload -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased text-apeiron-ink bg-white min-h-screen overflow-x-hidden selection:bg-zinc-900 selection:text-white">

    {{-- Aksesibilitas: Lewati ke konten utama (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-[#202020] focus:text-white focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-[#37352F]">
        Lewati ke konten utama
    </a>

    <div id="swup" class="transition-fade min-h-screen grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 w-full bg-white overflow-x-hidden">
        <!-- KIRI: KONTEN UTAMA (7 cols di lg, 1 col di md, single col di mobile) -->
        <div class="md:col-span-1 lg:col-span-7 flex flex-col justify-between p-6 sm:p-10 lg:p-12 xl:p-16 w-full relative bg-white min-h-screen">
            <!-- Form Content Mount (Strictly clamped to max-w-sm to prevent horizontal stretching) -->
            <div id="main-content" class="my-auto py-4 sm:py-6 w-full max-w-sm mx-auto" style="max-width: 384px;">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>

            <!-- Footer Bar -->
            <div class="w-full max-w-sm mx-auto md:max-w-none flex items-center justify-between text-xs text-zinc-400 pt-8 mt-auto border-t border-zinc-100" style="max-width: 384px;">
                <span>© {{ date('Y') }} Apeiron Systems</span>
                <div class="flex items-center gap-4">
                    <a href="#privacy" onclick="event.preventDefault()" class="hover:text-zinc-600 transition-colors">Privacy</a>
                    <a href="#terms" onclick="event.preventDefault()" class="hover:text-zinc-600 transition-colors">Terms</a>
                </div>
            </div>
        </div>

        <!-- KANAN: EDITORIAL BRAND PANEL (5 cols di lg, 1 col di md, disembunyikan di mobile < 768px) -->
        @hasSection('panel')
            @yield('panel')
        @else
            <div class="hidden md:flex md:col-span-1 lg:col-span-5 editorial-surface text-white p-8 sm:p-10 lg:p-12 xl:p-14 flex-col justify-between select-none relative overflow-hidden bg-[#0A0A0B] min-h-screen" id="anim-panel-right" aria-hidden="true">
                <div class="absolute inset-0 bg-cover bg-center opacity-20 mix-blend-luminosity pointer-events-none"
                    style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0B] via-[#0A0A0B]/60 to-transparent pointer-events-none"></div>

                <div class="relative z-10 text-xs text-zinc-400 font-medium tracking-wide">
                    Apeiron Systems
                </div>

                <div class="relative z-10 my-auto max-w-sm space-y-4">
                    <p class="text-[19px] lg:text-[21px] font-normal tracking-tight text-zinc-100 leading-[1.45]">
                        Point of sale, inventory ledger, and shift reconciliation built for multi-branch hospitality.
                    </p>
                    <p class="text-xs text-zinc-400 leading-relaxed">
                        Every order, stock movement, and cash drawer event is recorded with strict branch isolation.
                    </p>
                </div>

                <div class="relative z-10 flex items-center justify-between text-xs text-zinc-400 pt-6 border-t border-zinc-900">
                    <span>Enterprise Point of Sale</span>
                    <span>© {{ date('Y') }}</span>
                </div>
            </div>
        @endif
    </div>

    <x-ui.toast />
    <x-erp.confirm-dialog />

    @yield('scripts')

</body>

</html>
