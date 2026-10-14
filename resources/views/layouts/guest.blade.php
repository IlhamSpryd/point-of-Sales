<!DOCTYPE html>
<html lang="id" class="admin-ui">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('assets/images/logo.png') }}" type="image/png">    

    
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- GSAP & Alpine.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>


</head>

<body class="font-sans antialiased text-apeiron-ink bg-[#F7F7F5]">

    <div id="swup" class="transition-fade min-h-screen grid lg:grid-cols-2 w-full">
        <!-- KIRI: KONTEN SLOT -->
        <div class="flex flex-col items-center justify-center p-6 sm:p-10 w-full relative bg-white">
            <div class="w-full max-w-md">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>
        </div>

        <!-- KANAN: PANEL VISUAL -->
        @hasSection('panel')
            @yield('panel')
        @else
            <div class="hidden lg:flex flex-col justify-between p-12 text-white bg-cover bg-center relative overflow-hidden bg-apeiron-ink"
                style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');">
                <div class="absolute inset-0 bg-apeiron-ink/70 mix-blend-multiply"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-[#37352F]/90 via-transparent to-transparent"></div>
                <div class="relative z-10 mt-auto">
                    <div class="mb-8 min-h-40">
                        <p class="text-2xl font-medium tracking-tight text-white/90 leading-snug mb-5 max-w-lg">
                            Selamat Datang di <br /> Apeiron System.
                        </p>
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-white flex items-center justify-center border border-white/40 shadow-sm overflow-hidden p-1.5">
                                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System Logo" class="h-full w-full object-contain">
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-white">Apeiron POS</p>
                                <p class="text-xs text-white/50 mt-0.5">Operating System for F&amp;B</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @yield('scripts')

</body>

</html>
