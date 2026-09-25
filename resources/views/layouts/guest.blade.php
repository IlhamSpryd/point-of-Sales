<!DOCTYPE html>
<html lang="id" class="admin-ui">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">
    

    
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- GSAP & Alpine.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>


</head>

<body class="font-sans antialiased text-yovel-ink bg-[#F7F7F5]">

    <div id="swup" class="transition-fade min-h-screen grid lg:grid-cols-2 w-full">
        <!-- KIRI: KONTEN SLOT -->
        <div class="flex flex-col items-center justify-center p-6 sm:p-10 w-full relative bg-white">
            <div class="w-full max-w-md">
                @yield('content')
            </div>
        </div>

        <!-- KANAN: PANEL VISUAL -->
        @yield('panel')
    </div>

    @yield('scripts')

</body>

</html>
