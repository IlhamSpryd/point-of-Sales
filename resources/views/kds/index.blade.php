<!DOCTYPE html>
<html lang="en" data-theme="kds-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KDS - Apeiron System</title>

    <!-- Self-Hosted Fonts Preload (Offline Resilience for Kitchen TV Display) -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/material-symbols-rounded.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- Livewire Styles untuk standalone KDS -->
    @livewireStyles
</head>
<body class="bg-apeiron-bg font-sans antialiased text-apeiron-ink p-4 lg:p-8 h-screen overflow-hidden flex flex-col">
    
    <!-- KDS Header / Nav -->
    <header class="flex items-center justify-between mb-6 shrink-0">
        <div class="flex items-center gap-4">
            <a href="{{ route('home') }}" class="flex h-11 w-11 items-center justify-center rounded-xl bg-apeiron-surface border border-apeiron-border shadow-sm hover:bg-apeiron-bg transition-colors" wire:navigate>
                <span class="material-symbols-rounded text-[20px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight leading-none">Kitchen Display</h1>
                <p class="text-sm font-medium text-apeiron-muted mt-1">Real-time Kitchen Status</p>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <div class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-apeiron-surface border border-apeiron-border rounded-lg shadow-sm text-sm font-bold">
                <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                Connected
            </div>
            <div class="px-3 py-1.5 bg-apeiron-ink text-apeiron-bg rounded-lg text-sm font-bold tabular-nums shadow-sm">
                {{ now()->format('H:i') }}
            </div>
        </div>
    </header>

    <!-- KDS Board Container -->
    <main class="flex-1 overflow-hidden min-h-0">
        {{ $slot }}
    </main>

    <!-- Livewire Scripts -->
    @livewireScripts
</body>
</html>
