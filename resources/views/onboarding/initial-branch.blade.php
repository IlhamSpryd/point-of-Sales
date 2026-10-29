<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 2 of 2: Initial Branch — Apeiron</title>
    <link rel="icon" href="{{ asset('assets/images/logo.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-slate-800 bg-slate-50 min-h-screen lg:h-screen lg:overflow-hidden flex flex-col justify-between">
    <div class="w-full max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6 flex flex-col flex-1 justify-between">
        {{-- Header & Stepper --}}
        <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80">
            <div class="flex items-center gap-3">
                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System" class="h-8 w-auto object-contain">
                <span class="text-lg font-bold tracking-tight text-slate-900">Apeiron</span>
            </div>

            {{-- Minimalist Progress Indicator (Apple / Google Style) --}}
            <div class="w-full sm:w-72">
                <div class="flex items-center justify-between text-xs mb-1.5">
                    <a href="{{ route('onboarding.business-profile') }}" class="text-slate-500 hover:text-slate-900 transition-colors">1. Operational Standards</a>
                    <span class="font-semibold text-slate-900">2. Initial Branch</span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="h-1 bg-zinc-900 rounded-full"></div>
                    <div class="h-1 bg-zinc-900 rounded-full"></div>
                </div>
            </div>
        </header>

        {{-- Main Viewport Container --}}
        <main class="my-auto py-3 sm:py-4">
            <div class="mb-4 text-center sm:text-left">
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
                    Configure your first branch
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-600">
                    Every register transaction, table order, and shift requires an active branch for ledger accounting.
                </p>
            </div>

            <div class="bg-white p-5 sm:p-6 shadow-sm ring-1 ring-slate-900/5 rounded-2xl">
                @if (session('success'))
                    <div class="mb-3 rounded-xl bg-zinc-50 p-3 border border-zinc-200 text-xs font-medium text-zinc-900" role="status">
                        {{ session('success') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-3 rounded-xl bg-rose-50 p-3 border border-rose-200 text-xs text-rose-700" role="alert">
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
                            <label for="store_name" class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Branch / Outlet Name <span class="text-rose-500">*</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_name" id="store_name" required
                                    value="{{ old('store_name', $storeName) }}"
                                    class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 placeholder:text-slate-400 focus:border-zinc-950 focus:outline-none focus:ring-1 focus:ring-zinc-950 text-sm shadow-xs transition-all"
                                    placeholder="e.g., Downtown Flagship">
                            </div>
                        </div>

                        {{-- Branch Code --}}
                        <div>
                            <label for="store_code" class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Branch Code <span class="text-rose-500">*</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_code" id="store_code" required
                                    value="{{ old('store_code', $storeCode) }}"
                                    class="block w-full uppercase rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 placeholder:text-slate-400 focus:border-zinc-950 focus:outline-none focus:ring-1 focus:ring-zinc-950 text-sm shadow-xs transition-all"
                                    placeholder="MAIN-01">
                            </div>
                        </div>

                        {{-- Branch Timezone --}}
                        <div>
                            <label for="store_timezone" class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Branch Timezone <span class="text-rose-500">*</span>
                            </label>
                            <div class="mt-1">
                                <select name="store_timezone" id="store_timezone" required
                                    class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 focus:border-zinc-950 focus:outline-none focus:ring-1 focus:ring-zinc-950 text-sm shadow-xs transition-all bg-white">
                                    <option value="Asia/Jakarta" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jakarta')>WIB — Asia/Jakarta</option>
                                    <option value="Asia/Makassar" @selected(old('store_timezone', $storeTimezone) === 'Asia/Makassar')>WITA — Asia/Makassar</option>
                                    <option value="Asia/Jayapura" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jayapura')>WIT — Asia/Jayapura</option>
                                </select>
                            </div>
                        </div>

                        {{-- Default Sales Channel --}}
                        <div>
                            <label for="default_channel" class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Default Sales Channel <span class="text-rose-500">*</span>
                            </label>
                            <div class="mt-1">
                                <select name="default_channel" id="default_channel" required
                                    class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 focus:border-zinc-950 focus:outline-none focus:ring-1 focus:ring-zinc-950 text-sm shadow-xs transition-all bg-white">
                                    <option value="dine_in" @selected(old('default_channel', $defaultChannel) === 'dine_in')>Dine-in</option>
                                    <option value="takeaway" @selected(old('default_channel', $defaultChannel) === 'takeaway')>Takeaway</option>
                                    <option value="delivery" @selected(old('default_channel', $defaultChannel) === 'delivery')>Delivery</option>
                                </select>
                            </div>
                        </div>

                        {{-- Daily Business Cutoff --}}
                        <div>
                            <label class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Daily Cutoff (Shift Close) <span class="text-rose-500">*</span>
                            </label>
                            <div class="mt-1 grid grid-cols-2 gap-2" x-data="{ cutoff: '{{ old('business_date_cutoff', $businessDateCutoff) }}' }">
                                <label class="flex items-center justify-between p-2.5 border rounded-xl cursor-pointer transition-all text-xs"
                                    :class="cutoff === '00:00' ? 'border-zinc-950 bg-zinc-50/80 ring-1 ring-zinc-950' : 'border-slate-200 hover:border-slate-300'">
                                    <span class="font-bold text-slate-900">00:00 <span class="font-normal text-slate-500">(Midnight)</span></span>
                                    <input type="radio" name="business_date_cutoff" value="00:00" x-model="cutoff" class="text-zinc-900 focus:ring-zinc-950">
                                </label>
                                <label class="flex items-center justify-between p-2.5 border rounded-xl cursor-pointer transition-all text-xs"
                                    :class="cutoff === '04:00' ? 'border-zinc-950 bg-zinc-50/80 ring-1 ring-zinc-950' : 'border-slate-200 hover:border-slate-300'">
                                    <span class="font-bold text-slate-900">04:00 <span class="font-normal text-slate-500">(Late Night)</span></span>
                                    <input type="radio" name="business_date_cutoff" value="04:00" x-model="cutoff" class="text-zinc-900 focus:ring-zinc-950">
                                </label>
                            </div>
                        </div>

                        {{-- Branch Address --}}
                        <div>
                            <label for="store_address" class="block text-xs font-semibold text-slate-900 uppercase tracking-wide">
                                Branch Address <span class="text-xs font-normal text-slate-400">(Receipts — Optional)</span>
                            </label>
                            <div class="mt-1">
                                <input type="text" name="store_address" id="store_address"
                                    value="{{ old('store_address', $storeAddress) }}"
                                    class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 placeholder:text-slate-400 focus:border-zinc-950 focus:outline-none focus:ring-1 focus:ring-zinc-950 text-sm shadow-xs transition-all"
                                    placeholder="e.g., 100 Main Street, Suite 400">
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="pt-2 flex items-center justify-between gap-4">
                        <a href="{{ route('onboarding.business-profile') }}"
                            class="inline-flex items-center text-xs font-semibold text-slate-600 hover:text-slate-900 transition-colors">
                            <span>Back</span>
                        </a>

                        <button type="submit"
                            class="flex-1 flex items-center justify-center rounded-xl bg-[#141413] px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-zinc-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 transition-all cursor-pointer">
                            <span>Complete setup & open POS dashboard</span>
                        </button>
                    </div>
                </form>
            </div>
        </main>

        {{-- Footer Bar --}}
        <footer class="flex items-center justify-between text-xs text-slate-400 pt-3 border-t border-slate-200/80">
            <span>&copy; {{ date('Y') }} Apeiron</span>
            <span class="text-[11px] text-slate-400">Step 2 of 2</span>
        </footer>
    </div>
</body>
</html>
