<x-guest-layout>
    @section('title', 'Register Business — Apeiron POS')

    <!-- Heading Block -->
    <div class="mb-6">
        <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-zinc-100 text-[11px] font-medium text-zinc-700 mb-3">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            {{ __('New Workspace Registration') }}
        </div>
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Register your business') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5 leading-relaxed">
            {{ __('Create your organization workspace to access point of sale and inventory management.') }}
        </p>
    </div>

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Registration failed') }}</span>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <!-- SSO Buttons (Google & Apple) for Registration -->
    <div class="grid grid-cols-2 gap-3 mb-6">
        <a href="{{ route('oauth.redirect', ['provider' => 'google', 'intent' => 'register_owner']) }}" id="btn-google-register" aria-label="{{ __('Register with Google') }}" class="w-full h-10 flex items-center justify-center gap-2.5 px-3 rounded-md border border-zinc-200 bg-white text-xs sm:text-sm font-medium text-zinc-800 hover:bg-zinc-50 hover:border-zinc-300 focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs active:scale-[0.98] cursor-pointer">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.38005-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            <span>Google</span>
        </a>

        <a href="{{ route('oauth.redirect', ['provider' => 'apple', 'intent' => 'register_owner']) }}" id="btn-apple-register" aria-label="{{ __('Register with Apple') }}" class="w-full h-10 flex items-center justify-center gap-2.5 px-3 rounded-md border border-zinc-200 bg-white text-xs sm:text-sm font-medium text-zinc-800 hover:bg-zinc-50 hover:border-zinc-300 focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs active:scale-[0.98] cursor-pointer">
            <svg width="18" height="18" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="text-zinc-900">
                <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
            </svg>
            <span>Apple</span>
        </a>
    </div>

    <!-- Divider -->
    <div class="relative flex items-center justify-center mb-6">
        <div class="w-full border-t border-zinc-200"></div>
        <span class="absolute bg-white px-3 text-xs text-zinc-400 uppercase tracking-wider">{{ __('or continue with email') }}</span>
    </div>

    <!-- Registration Form -->
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <label for="business_name" class="block text-xs font-medium text-zinc-700 mb-1.5">
                {{ __('Brand or Business Name') }}
            </label>
            <input
                id="business_name"
                type="text"
                name="business_name"
                value="{{ old('business_name') }}"
                placeholder="e.g. Kopi Nusantara"
                class="w-full h-10 px-3 rounded-md border border-zinc-200 bg-white text-zinc-900 text-sm focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
            />
        </div>

        <div>
            <label for="name" class="block text-xs font-medium text-zinc-700 mb-1.5">
                {{ __('Owner Full Name') }}
            </label>
            <input
                id="name"
                type="text"
                name="name"
                value="{{ old('name') }}"
                required
                placeholder="e.g. Budi Santoso"
                class="w-full h-10 px-3 rounded-md border border-zinc-200 bg-white text-zinc-900 text-sm focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
            />
        </div>

        <div>
            <label for="email" class="block text-xs font-medium text-zinc-700 mb-1.5">
                {{ __('Business Email Address') }}
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                required
                placeholder="owner@domain.com"
                class="w-full h-10 px-3 rounded-md border border-zinc-200 bg-white text-zinc-900 text-sm focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
            />
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="block text-xs font-medium text-zinc-700 mb-1.5">
                {{ __('Password') }}
            </label>
            <div class="relative">
                <input
                    id="password"
                    :type="show ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="••••••••••••"
                    class="w-full h-10 pl-3 pr-10 rounded-md border border-zinc-200 bg-white text-zinc-900 text-sm focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
                />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-zinc-400 hover:text-zinc-600 focus:outline-none focus:text-zinc-900 rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <!-- Eye (Show Password) -->
                    <svg x-show="!show" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <!-- Eye Off (Hide Password) -->
                    <svg x-show="show" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                        <line x1="2" y1="2" x2="22" y2="22"/>
                    </svg>
                </button>
            </div>
        </div>

        <div x-data="{ show: false }">
            <label for="password_confirmation" class="block text-xs font-medium text-zinc-700 mb-1.5">
                {{ __('Confirm Password') }}
            </label>
            <div class="relative">
                <input
                    id="password_confirmation"
                    :type="show ? 'text' : 'password'"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="••••••••••••"
                    class="w-full h-10 pl-3 pr-10 rounded-md border border-zinc-200 bg-white text-zinc-900 text-sm focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
                />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-zinc-400 hover:text-zinc-600 focus:outline-none focus:text-zinc-900 rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <!-- Eye (Show Password) -->
                    <svg x-show="!show" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <!-- Eye Off (Hide Password) -->
                    <svg x-show="show" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                        <line x1="2" y1="2" x2="22" y2="22"/>
                    </svg>
                </button>
            </div>
        </div>

        <button
            type="submit"
            style="background-color: #141413; color: #ffffff;"
            class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99] mt-2"
        >
            <span>{{ __('Create Workspace') }}</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
    </form>

    <div class="mt-6 pt-4 border-t border-zinc-100 text-xs text-center text-zinc-500">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="text-zinc-900 font-medium hover:underline ml-1">
            {{ __('Sign in') }}
        </a>
    </div>

    <x-slot name="panel">
        <div class="hidden md:flex md:col-span-1 lg:col-span-5 editorial-surface text-white p-8 sm:p-10 lg:p-12 xl:p-14 flex-col justify-between select-none relative overflow-hidden bg-[#0A0A0B] min-h-screen">
            <div class="absolute inset-0 bg-cover bg-center opacity-20 mix-blend-luminosity pointer-events-none"
                style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0B] via-[#0A0A0B]/60 to-transparent pointer-events-none"></div>

            <div class="relative z-10 text-xs text-zinc-400 font-medium tracking-wide">
                Workspace Setup
            </div>

            <div class="relative z-10 my-auto max-w-xs space-y-8">
                <div class="space-y-2">
                    <p class="text-lg font-normal tracking-tight text-zinc-100 leading-snug">
                        Point of sale, inventory ledger, and shift reconciliation built for modern hospitality.
                    </p>
                </div>

                <div class="space-y-6 border-l border-zinc-800 pl-5">
                    <div>
                        <div class="text-xs text-white font-medium">
                            1. Business Registration
                        </div>
                        <div class="text-xs text-zinc-400 mt-0.5">
                            Register your business name and owner credentials.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            2. 6-Digit OTP Verification
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Verify your email address using a secure single-use 6-digit OTP code.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            3. Branch & Operations
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Complete the onboarding wizard and configure your first store.
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 flex items-center justify-between text-xs text-zinc-400 pt-6 border-t border-zinc-900">
                <span>Enterprise Point of Sale</span>
                <span>© {{ date('Y') }}</span>
            </div>
        </div>
    </x-slot>
</x-guest-layout>
