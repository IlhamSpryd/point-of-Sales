<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', 'Login - ' . config('app.name', 'Laravel'))</title>
    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- GSAP & Alpine.js — urutan: GSAP dulu, Alpine defer -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="antialiased text-zinc-900 dark:text-zinc-100 bg-white dark:bg-zinc-900">

    {{--
    SWUP WRAPPER — id="swup", class="transition-fade" WAJIB ada.
    Dipakai sistem page-transition global. Jangan ubah.
    --}}
    <div id="swup" class="transition-fade min-h-screen grid lg:grid-cols-2 w-full">

        <!-- KIRI: KONTEN SLOT -->
        <div class="flex flex-col items-center justify-center p-6 sm:p-10 w-full relative">
            <div class="w-full max-w-105">
                @yield('content')
            </div>
        </div>

        <!-- KANAN: PANEL VISUAL -->
        @yield('panel')

    </div>{{-- end #swup --}}

    @yield('scripts')

</body>

</html>
