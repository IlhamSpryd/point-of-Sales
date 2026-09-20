<!DOCTYPE html>
<html lang="id" class="admin-ui">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- GSAP & Alpine.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; }
    </style>
</head>

<body class="font-sans antialiased text-[#37352F] bg-[#F7F7F5] flex h-dvh flex-col items-center justify-center p-4">

    <div id="swup" class="transition-fade w-full max-w-md bg-white rounded-3xl p-8 shadow-sm border border-[#E9E9E7] animate-slide-up-fade">
        <div class="flex justify-center mb-8">
            <a href="/" class="flex flex-col items-center gap-2">
                <div class="w-16 h-16 bg-[#37352F] rounded-2xl flex items-center justify-center shadow-md">
                    <span class="text-white font-bold text-2xl tracking-tighter">Y</span>
                </div>
                <span class="text-lg tracking-tight font-brand font-bold">Yovel<span class="font-normal text-[#787774] ml-1">Coffee</span></span>
            </a>
        </div>

        {{ $slot }}
    </div>

    @yield('scripts')

</body>

</html>
