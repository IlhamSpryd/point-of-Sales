<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-secondary">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 2 of 2: Initial Branch — Apeiron</title>
    <x-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-foreground bg-secondary min-h-screen lg:h-screen lg:overflow-hidden flex flex-col justify-between">
    <div class="w-full max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6 flex flex-col flex-1 justify-between">
        {{-- Header & Stepper --}}
        <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-border">
            <div class="flex items-center gap-3">
                <x-brand-logo variant="wordmark" class="h-7 w-auto text-foreground" />
            </div>

            {{-- Minimalist Progress Indicator (Apple / Google Style) --}}
            <div class="w-full sm:w-72">
                <div class="flex items-center justify-between text-xs mb-1.5">
                    <a href="{{ route('onboarding.business-profile') }}" class="text-muted-foreground hover:text-foreground transition-colors">1. Operational Standards</a>
                    <span class="font-semibold text-foreground">2. Initial Branch</span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="h-1 bg-card rounded-full"></div>
                    <div class="h-1 bg-card rounded-full"></div>
                </div>
            </div>
        </header>

        {{-- Main Viewport Container --}}
        <main class="my-auto py-3 sm:py-4">
            <div class="mb-4 text-center sm:text-left">
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">
                    Configure your first branch
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-muted-foreground">
                    Every register transaction, table order, and shift requires an active branch for ledger accounting.
                </p>
            </div>

            <div class="bg-white p-5 sm:p-6 shadow-sm ring-1 ring-ring rounded-2xl">
                @if (session('success'))
                    <div class="mb-3 rounded-xl bg-secondary p-3 border border-border text-xs font-medium text-foreground" role="status">
                        {{ session('success') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-3 rounded-xl bg-destructive-muted p-3 border border-destructive text-xs text-destructive" role="alert">
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('onboarding.initial-branch.store') }}" method="POST" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Branch Name --}}
                        <div>
                            <label for="store_name" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Branch / Outlet Name <span class="text-destructive">*</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_name" id="store_name" required
                                    value="{{ old('store_name', $storeName) }}"
                                    class="block w-full rounded-xl border border-border px-3.5 py-2 text-foreground placeholder:text-muted-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all"
                                    placeholder="e.g., Downtown Flagship">
                            </div>
                        </div>

                        {{-- Branch Code --}}
                        <div>
                            <label for="store_code" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Branch Code <span class="text-destructive">*</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_code" id="store_code" required
                                    value="{{ old('store_code', $storeCode) }}"
                                    class="block w-full uppercase rounded-xl border border-border px-3.5 py-2 text-foreground placeholder:text-muted-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all"
                                    placeholder="MAIN-01">
                            </div>
                        </div>

                        {{-- Branch Timezone --}}
                        <div>
                            <label for="store_timezone" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Branch Timezone <span class="text-destructive">*</span>
                            </label>
                            <div class="mt-1">
                                <select name="store_timezone" id="store_timezone" required
                                    class="block w-full rounded-xl border border-border px-3.5 py-2 text-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all bg-white">
                                    <option value="Asia/Jakarta" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jakarta')>WIB — Asia/Jakarta</option>
                                    <option value="Asia/Makassar" @selected(old('store_timezone', $storeTimezone) === 'Asia/Makassar')>WITA — Asia/Makassar</option>
                                    <option value="Asia/Jayapura" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jayapura')>WIT — Asia/Jayapura</option>
                                </select>
                            </div>
                        </div>

                        {{-- Default Sales Channel --}}
                        <div>
                            <label for="default_channel" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Default Sales Channel <span class="text-destructive">*</span>
                            </label>
                            <div class="mt-1">
                                <select name="default_channel" id="default_channel" required
                                    class="block w-full rounded-xl border border-border px-3.5 py-2 text-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all bg-white">
                                    <option value="dine_in" @selected(old('default_channel', $defaultChannel) === 'dine_in')>Dine-in</option>
                                    <option value="takeaway" @selected(old('default_channel', $defaultChannel) === 'takeaway')>Takeaway</option>
                                    <option value="delivery" @selected(old('default_channel', $defaultChannel) === 'delivery')>Delivery</option>
                                </select>
                            </div>
                        </div>

                        {{-- Daily Business Cutoff --}}
                        <div>
                            <label class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Daily Cutoff (Shift Close) <span class="text-destructive">*</span>
                            </label>
                            <div class="mt-1 grid grid-cols-2 gap-2" x-data="{ cutoff: '{{ old('business_date_cutoff', $businessDateCutoff) }}' }">
                                <label class="flex items-center justify-between p-2.5 border rounded-xl cursor-pointer transition-all text-xs"
                                    :class="cutoff === '00:00' ? 'border-border bg-secondary/80 ring-1 ring-ring' : 'border-border hover:border-border'">
                                    <span class="font-bold text-foreground">00:00 <span class="font-normal text-muted-foreground">(Midnight)</span></span>
                                    <input type="radio" name="business_date_cutoff" value="00:00" x-model="cutoff" class="text-foreground focus:ring-ring">
                                </label>
                                <label class="flex items-center justify-between p-2.5 border rounded-xl cursor-pointer transition-all text-xs"
                                    :class="cutoff === '04:00' ? 'border-border bg-secondary/80 ring-1 ring-ring' : 'border-border hover:border-border'">
                                    <span class="font-bold text-foreground">04:00 <span class="font-normal text-muted-foreground">(Late Night)</span></span>
                                    <input type="radio" name="business_date_cutoff" value="04:00" x-model="cutoff" class="text-foreground focus:ring-ring">
                                </label>
                            </div>
                        </div>

                        {{-- Branch Address --}}
                        <div>
                            <label for="store_address" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Branch Address <span class="text-xs font-normal text-muted-foreground">(Receipts — Optional)</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_address" id="store_address"
                                    value="{{ old('store_address', $storeAddress) }}"
                                    class="block w-full rounded-xl border border-border px-3.5 py-2 text-foreground placeholder:text-muted-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all"
                                    placeholder="e.g., 100 Main Street, Suite 400">
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="pt-2 flex items-center justify-between gap-4">
                        <a href="{{ route('onboarding.business-profile') }}"
                            class="inline-flex items-center text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors">
                            <span>Back</span>
                        </a>

                        <button type="submit"
                            class="flex-1 flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 transition-all cursor-pointer">
                            <span>Complete setup & open POS dashboard</span>
                        </button>
                    </div>
                </form>
            </div>
        </main>

        {{-- Footer Bar --}}
        <footer class="flex items-center justify-between text-xs text-muted-foreground pt-3 border-t border-border">
            <span>&copy; {{ date('Y') }} Apeiron</span>
            <span class="text-[11px] text-muted-foreground">Step 2 of 2</span>
        </footer>
    </div>
</body>
</html>
