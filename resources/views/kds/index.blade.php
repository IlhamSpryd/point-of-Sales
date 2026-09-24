<!DOCTYPE html>
<html lang="id" data-theme="kds-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KDS - Yovel Coffee</title>

    <!-- Google Material Symbols (Rounded) -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
    
    <!-- Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- Livewire Styles untuk standalone KDS -->
    @livewireStyles
</head>
<body class="bg-yovel-bg font-sans antialiased text-yovel-ink p-4 lg:p-8 h-screen overflow-hidden flex flex-col">
    
    <!-- KDS Header / Nav -->
    <header class="flex items-center justify-between mb-6 shrink-0">
        <div class="flex items-center gap-4">
            <a href="{{ route('dashboard') }}" class="flex h-11 w-11 items-center justify-center rounded-xl bg-yovel-surface border border-yovel-border shadow-sm hover:bg-yovel-bg transition-colors" wire:navigate>
                <span class="material-symbols-rounded text-[20px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight leading-none">Kitchen Display</h1>
                <p class="text-sm font-medium text-yovel-muted mt-1">Status Dapur Real-time</p>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <div class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-yovel-surface border border-yovel-border rounded-lg shadow-sm text-sm font-bold">
                <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                Koneksi Stabil
            </div>
            <div class="px-3 py-1.5 bg-yovel-ink text-yovel-bg rounded-lg text-sm font-bold tabular-nums shadow-sm">
                {{ now()->format('H:i') }}
            </div>
        </div>
    </header>

    <!-- KDS Board Container -->
    <main class="flex-1 overflow-hidden min-h-0">
        <livewire:kds.board />
    </main>

    <!-- Livewire Scripts -->
    @livewireScripts
</body>
</html>
