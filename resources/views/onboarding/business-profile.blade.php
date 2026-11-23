<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-secondary">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 1 of 2: Operational Standards — Apeiron</title>
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
                    <span class="font-semibold text-foreground">1. Operational Standards</span>
                    <span class="text-muted-foreground">2. Initial Branch</span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="h-1 bg-card rounded-full"></div>
                    <div class="h-1 bg-muted rounded-full"></div>
                </div>
            </div>
        </header>

        {{-- Main Viewport Container (Centered, 2-Column Split on PC) --}}
        <main class="my-auto py-4 sm:py-6">
            <div class="mb-5 text-center sm:text-left">
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">
                    Set up operational standards
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-muted-foreground">
                    Confirm your registered workspace details and select your operating parameters.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                {{-- Left Column: Registered Account Details (Google / Apple Style) --}}
                <div class="lg:col-span-5">
                    <div class="overflow-hidden rounded-2xl border border-border bg-card shadow-xs">
                        <div class="bg-secondary/75 px-4 py-3 border-b border-border flex items-center justify-between">
                            <h2 class="text-xs font-semibold text-foreground uppercase tracking-wide">Registered Account Details</h2>
                            <span class="text-[11px] text-muted-foreground font-normal">Verified via signup</span>
                        </div>
                        <dl class="divide-y border-border text-sm">
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-xs font-medium text-muted-foreground">Business / Brand</dt>
                                <dd class="text-xs sm:text-sm font-semibold text-foreground truncate max-w-[200px] text-right">{{ $businessName }}</dd>
                            </div>
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-xs font-medium text-muted-foreground">Account Owner</dt>
                                <dd class="text-xs sm:text-sm text-foreground truncate max-w-[200px] text-right">{{ $user->name }}</dd>
                            </div>
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-xs font-medium text-muted-foreground">Email Address</dt>
                                <dd class="text-xs sm:text-sm text-foreground truncate max-w-[200px] text-right">{{ $user->email }}</dd>
                            </div>
                            @if (!empty($ownerPhone))
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-xs font-medium text-muted-foreground">Phone / WhatsApp</dt>
                                <dd class="text-xs sm:text-sm text-foreground font-sans text-right">{{ $ownerPhone }}</dd>
                            </div>
                            @endif
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-xs font-medium text-muted-foreground">Region & Currency</dt>
                                <dd class="text-xs sm:text-sm text-foreground text-right">
                                    {{ $countryCode === 'ID' ? 'Indonesia' : $countryCode }} &bull; {{ $defaultCurrency }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                {{-- Right Column: Form Card --}}
                <div class="lg:col-span-7 bg-card p-5 sm:p-6 shadow-sm ring-1 ring-ring rounded-2xl">
                    @if (session('success'))
                        <div class="mb-4 rounded-xl bg-secondary p-3 border border-border text-xs font-medium text-foreground" role="status">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('info'))
                        <div class="mb-4 rounded-xl bg-accent/10 p-3 border border-accent text-xs font-medium text-accent" role="status">
                            {{ session('info') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-4 rounded-xl bg-destructive-muted p-3 border border-destructive text-xs text-destructive" role="alert">
                            <ul class="list-disc list-inside space-y-0.5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('onboarding.business-profile.store') }}" method="POST" class="space-y-4">
                        @csrf

                        {{-- Pre-populated hidden values (preserves payload integrity without re-entry friction) --}}
                        <input type="hidden" name="business_name" value="{{ $businessName }}">
                        <input type="hidden" name="owner_phone" value="{{ $ownerPhone }}">
                        <input type="hidden" name="country_code" value="{{ $countryCode }}">
                        <input type="hidden" name="default_currency" value="{{ $defaultCurrency }}">

                        {{-- Business Category --}}
                        <div>
                            <label for="business_category" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Business Category <span class="text-destructive" aria-hidden="true">*</span>
                            </label>
                            <div class="mt-1.5">
                                <select name="business_category" id="business_category" required
                                    class="block w-full rounded-xl border border-border px-3.5 py-2.5 text-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all bg-card">
                                    <option value="fnb_cafe" @selected(old('business_category', $businessCategory) === 'fnb_cafe')>Cafe & Coffee Shop</option>
                                    <option value="fnb_restaurant" @selected(old('business_category', $businessCategory) === 'fnb_restaurant')>Restaurant & Dining</option>
                                    <option value="fnb_bakery" @selected(old('business_category', $businessCategory) === 'fnb_bakery')>Bakery & Pastry</option>
                                    <option value="fnb_cloud_kitchen" @selected(old('business_category', $businessCategory) === 'fnb_cloud_kitchen')>Cloud Kitchen / Food Stall</option>
                                    <option value="retail_other" @selected(old('business_category', $businessCategory) === 'retail_other')>Retail & Other Services</option>
                                </select>
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">Configures initial catalog templates, order type flows, and kitchen ticket handling.</p>
                        </div>

                        {{-- Primary Operating Timezone --}}
                        <div>
                            <label for="tenant_timezone" class="block text-xs font-semibold text-foreground uppercase tracking-wide">
                                Primary Operating Timezone <span class="text-destructive" aria-hidden="true">*</span>
                            </label>
                            <div class="mt-1.5">
                                <select name="tenant_timezone" id="tenant_timezone" required
                                    class="block w-full rounded-xl border border-border px-3.5 py-2.5 text-foreground focus:border-border focus:outline-none focus:ring-1 focus:ring-ring text-sm shadow-xs transition-all bg-card">
                                    <option value="Asia/Jakarta" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jakarta')>Western Indonesia Time (WIB) — Asia/Jakarta</option>
                                    <option value="Asia/Makassar" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Makassar')>Central Indonesia Time (WITA) — Asia/Makassar</option>
                                    <option value="Asia/Jayapura" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jayapura')>Eastern Indonesia Time (WIT) — Asia/Jayapura</option>
                                </select>
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">New branch cash registers and daily shift cutoffs will follow this timezone.</p>
                        </div>

                        {{-- Submit Button --}}
                        <div class="pt-2">
                            <button type="submit"
                                class="w-full flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 transition-all cursor-pointer">
                                <span>Save & continue to initial branch</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        {{-- Footer Bar --}}
        <footer class="flex items-center justify-between text-xs text-muted-foreground pt-3 border-t border-border">
            <span>&copy; {{ date('Y') }} Apeiron</span>
            <form action="{{ route('logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="hover:text-foreground underline focus:outline-none cursor-pointer">
                    Sign out temporarily (progress is saved)
                </button>
            </form>
        </footer>
    </div>
</body>
</html>
