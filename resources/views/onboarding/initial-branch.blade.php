<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 2 of 2: Initial Branch — Apeiron POS</title>
    <link rel="icon" href="{{ asset('assets/images/logo.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-slate-800 bg-slate-50">
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        {{-- Header & Stepper --}}
        <div class="sm:mx-auto sm:w-full sm:max-w-xl">
            <div class="flex items-center justify-center gap-3 mb-6">
                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System" class="h-12 w-auto object-contain">
                <span class="text-xl font-bold tracking-tight text-slate-900">Apeiron POS</span>
            </div>

            {{-- Stepper Indicator --}}
            <nav aria-label="Onboarding Progress" class="mb-8">
                <ol role="list" class="flex items-center justify-between border-b border-slate-200 pb-5">
                    <li class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700">
                            ✓
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Step 1</p>
                            <a href="{{ route('onboarding.business-profile') }}" class="text-sm font-medium text-slate-700 hover:text-emerald-700 underline">
                                Business Profile
                            </a>
                        </div>
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white shadow-sm ring-4 ring-emerald-100">
                            2
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Step 2</p>
                            <p class="text-sm font-medium text-slate-900">Initial Branch</p>
                        </div>
                    </li>
                </ol>
            </nav>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900 text-center">
                Configure your first branch
            </h1>
            <p class="mt-2 text-sm text-slate-600 text-center">
                Every register transaction, table order, and shift requires an active branch for ledger accounting.
            </p>
        </div>

        {{-- Main Form Card --}}
        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-xl px-4 sm:px-0">
            <div class="bg-white py-8 px-6 shadow-sm ring-1 ring-slate-900/5 sm:rounded-2xl sm:px-10">
                @if (session('success'))
                    <div class="mb-6 rounded-xl bg-emerald-50 p-4 border border-emerald-200" role="status">
                        <p class="text-sm font-medium text-emerald-800">{{ session('success') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-xl bg-rose-50 p-4 border border-rose-200" role="alert">
                        <p class="text-sm font-semibold text-rose-800 mb-1">There were errors with your submission:</p>
                        <ul class="list-disc list-inside text-xs text-rose-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('onboarding.initial-branch.store') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Branch Name --}}
                    <div>
                        <label for="store_name" class="block text-sm font-semibold text-slate-900">
                            Branch / Outlet Name <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="store_name" id="store_name" required
                                value="{{ old('store_name', $storeName) }}"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="e.g., Downtown Flagship">
                        </div>
                    </div>

                    {{-- Branch Code --}}
                    <div>
                        <label for="store_code" class="block text-sm font-semibold text-slate-900">
                            Branch Code <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="store_code" id="store_code" required
                                value="{{ old('store_code', $storeCode) }}"
                                class="block w-full uppercase rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="MAIN-01">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Used as a receipt prefix and for multi-branch reporting.</p>
                    </div>

                    {{-- Branch Timezone --}}
                    <div>
                        <label for="store_timezone" class="block text-sm font-semibold text-slate-900">
                            Branch Timezone <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="store_timezone" id="store_timezone" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="Asia/Jakarta" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jakarta')>Western Indonesia Time (WIB) — Asia/Jakarta</option>
                                <option value="Asia/Makassar" @selected(old('store_timezone', $storeTimezone) === 'Asia/Makassar')>Central Indonesia Time (WITA) — Asia/Makassar</option>
                                <option value="Asia/Jayapura" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jayapura')>Eastern Indonesia Time (WIT) — Asia/Jayapura</option>
                            </select>
                        </div>
                    </div>

                    {{-- Daily Business Cutoff (Shift Close) --}}
                    <div>
                        <label for="business_date_cutoff" class="block text-sm font-semibold text-slate-900">
                            Daily Business Cutoff (Shift Close) <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5 grid grid-cols-2 gap-3" x-data="{ cutoff: '{{ old('business_date_cutoff', $businessDateCutoff) }}' }">
                            <label class="flex flex-col p-3 border rounded-xl cursor-pointer transition-all"
                                :class="cutoff === '00:00' ? 'border-emerald-600 bg-emerald-50/50 ring-1 ring-emerald-600' : 'border-slate-200 hover:border-slate-300'">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-bold text-slate-900">00:00</span>
                                    <input type="radio" name="business_date_cutoff" value="00:00" x-model="cutoff" class="text-emerald-600 focus:ring-emerald-500">
                                </div>
                                <span class="text-xs text-slate-500 mt-1">Standard (Midnight)</span>
                            </label>
                            <label class="flex flex-col p-3 border rounded-xl cursor-pointer transition-all"
                                :class="cutoff === '04:00' ? 'border-emerald-600 bg-emerald-50/50 ring-1 ring-emerald-600' : 'border-slate-200 hover:border-slate-300'">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-bold text-slate-900">04:00</span>
                                    <input type="radio" name="business_date_cutoff" value="04:00" x-model="cutoff" class="text-emerald-600 focus:ring-emerald-500">
                                </div>
                                <span class="text-xs text-slate-500 mt-1">Late Night Hospitality</span>
                            </label>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Shifts running past midnight remain recorded under the same business date when selecting 04:00.</p>
                    </div>

                    {{-- Default Sales Channel --}}
                    <div>
                        <label for="default_channel" class="block text-sm font-semibold text-slate-900">
                            Default Sales Channel <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="default_channel" id="default_channel" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="dine_in" @selected(old('default_channel', $defaultChannel) === 'dine_in')>Dine-in</option>
                                <option value="takeaway" @selected(old('default_channel', $defaultChannel) === 'takeaway')>Takeaway</option>
                                <option value="delivery" @selected(old('default_channel', $defaultChannel) === 'delivery')>Delivery</option>
                            </select>
                        </div>
                    </div>

                    {{-- Branch Address --}}
                    <div>
                        <label for="store_address" class="block text-sm font-semibold text-slate-900">
                            Branch Address <span class="text-xs font-normal text-slate-500">(Printed on receipts — Optional)</span>
                        </label>
                        <div class="mt-1.5">
                            <textarea name="store_address" id="store_address" rows="2"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="100 Main Street, Suite 400">{{ old('store_address', $storeAddress) }}</textarea>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="pt-4 flex items-center justify-between gap-4">
                        <a href="{{ route('onboarding.business-profile') }}"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                            <span>Back</span>
                        </a>

                        <button type="submit"
                            class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 transition-all cursor-pointer">
                            <span>Complete setup & open POS dashboard</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
