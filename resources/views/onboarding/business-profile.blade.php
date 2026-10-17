<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 1 of 2: Business Profile — Apeiron POS</title>
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
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white shadow-sm ring-4 ring-emerald-100">
                            1
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Step 1</p>
                            <p class="text-sm font-medium text-slate-900">Business Profile & Standards</p>
                        </div>
                    </li>
                    <li class="flex items-center gap-3 opacity-60">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600">
                            2
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Step 2</p>
                            <p class="text-sm font-medium text-slate-600">Initial Branch</p>
                        </div>
                    </li>
                </ol>
            </nav>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900 text-center">
                Set up your business profile
            </h1>
            <p class="mt-2 text-sm text-slate-600 text-center">
                Confirm your brand name, business category, and base currency before opening your cash register.
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

                @if (session('info'))
                    <div class="mb-6 rounded-xl bg-blue-50 p-4 border border-blue-200" role="status">
                        <p class="text-sm font-medium text-blue-800">{{ session('info') }}</p>
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

                <form action="{{ route('onboarding.business-profile.store') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Business Name --}}
                    <div>
                        <label for="business_name" class="block text-sm font-semibold text-slate-900">
                            Business / Brand Name <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="business_name" id="business_name" required
                                value="{{ old('business_name', $businessName) }}"
                                autocomplete="organization"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="e.g., Apex Coffee Roasters">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">The brand name displayed on sales reports and transaction receipts.</p>
                    </div>

                    {{-- Business Category --}}
                    <div>
                        <label for="business_category" class="block text-sm font-semibold text-slate-900">
                            Business Category <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="business_category" id="business_category" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="fnb_cafe" @selected(old('business_category', $businessCategory) === 'fnb_cafe')>Cafe & Coffee Shop</option>
                                <option value="fnb_restaurant" @selected(old('business_category', $businessCategory) === 'fnb_restaurant')>Restaurant & Dining</option>
                                <option value="fnb_bakery" @selected(old('business_category', $businessCategory) === 'fnb_bakery')>Bakery & Pastry</option>
                                <option value="fnb_cloud_kitchen" @selected(old('business_category', $businessCategory) === 'fnb_cloud_kitchen')>Cloud Kitchen / Food Stall</option>
                                <option value="retail_other" @selected(old('business_category', $businessCategory) === 'retail_other')>Retail & Other Services</option>
                            </select>
                        </div>
                    </div>

                    {{-- Owner Phone (WhatsApp / Mobile) --}}
                    <div>
                        <label for="owner_phone" class="block text-sm font-semibold text-slate-900">
                            Owner Phone Number <span class="text-xs font-normal text-slate-500">(Optional)</span>
                        </label>
                        <div class="mt-1.5 flex rounded-xl shadow-sm">
                            <span class="inline-flex items-center px-3.5 rounded-l-xl border border-r-0 border-slate-300 bg-slate-50 text-slate-600 text-sm font-medium">
                                +62
                            </span>
                            <input type="tel" name="owner_phone" id="owner_phone"
                                value="{{ old('owner_phone', $ownerPhone) }}"
                                autocomplete="tel"
                                class="block w-full flex-1 rounded-none rounded-r-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm transition-all"
                                placeholder="81234567890">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Used for critical security notices and operational support.</p>
                    </div>

                    {{-- Primary Timezone --}}
                    <div>
                        <label for="tenant_timezone" class="block text-sm font-semibold text-slate-900">
                            Primary Timezone <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="tenant_timezone" id="tenant_timezone" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="Asia/Jakarta" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jakarta')>Western Indonesia Time (WIB) — Asia/Jakarta</option>
                                <option value="Asia/Makassar" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Makassar')>Central Indonesia Time (WITA) — Asia/Makassar</option>
                                <option value="Asia/Jayapura" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jayapura')>Eastern Indonesia Time (WIT) — Asia/Jayapura</option>
                            </select>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">New branches will inherit this timezone by default.</p>
                    </div>

                    {{-- Currency & Country --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="default_currency" class="block text-sm font-semibold text-slate-900">
                                Currency <span class="text-rose-500" aria-hidden="true">*</span>
                            </label>
                            <input type="text" name="default_currency" id="default_currency" readonly required
                                value="{{ old('default_currency', $defaultCurrency) }}"
                                class="mt-1.5 block w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-slate-700 text-sm font-medium cursor-not-allowed">
                        </div>
                        <div>
                            <label for="country_code" class="block text-sm font-semibold text-slate-900">
                                Country <span class="text-rose-500" aria-hidden="true">*</span>
                            </label>
                            <input type="text" name="country_code" id="country_code" readonly required
                                value="{{ old('country_code', $countryCode) }}"
                                class="mt-1.5 block w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-slate-700 text-sm font-medium cursor-not-allowed">
                        </div>
                    </div>

                    {{-- Submit Button --}}
                    <div class="pt-4">
                        <button type="submit"
                            class="w-full flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 transition-all cursor-pointer">
                            <span>Save & continue to initial branch</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Logout Option --}}
            <div class="mt-6 text-center">
                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="text-xs text-slate-500 hover:text-slate-800 underline focus:outline-none">
                        Sign out temporarily (your progress is saved)
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
