<x-guest-layout>
    @section('title', 'Register Business — Apeiron POS')

    <!-- Heading Block -->
    <div class="mb-8">
        <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-zinc-100 text-[11px] font-medium text-zinc-700 mb-3">
            <span class="w-1.5 h-1.5 rounded-full bg-zinc-500"></span>
            {{ __('Enterprise Onboarding') }}
        </div>
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Register your business') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5 leading-relaxed">
            {{ __('New business workspace registration is managed through our official onboarding portal.') }}
        </p>
    </div>

    <!-- Informational Card -->
    <div class="p-5 rounded-lg border border-zinc-200 bg-zinc-50/50 space-y-4 mb-6">
        <div class="flex items-start gap-3">
            <div class="p-2 rounded-md bg-white border border-zinc-200 text-zinc-800 shadow-2xs mt-0.5">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect width="20" height="14" x="2" y="5" rx="2"/>
                    <line x1="2" x2="22" y1="10" y2="10"/>
                </svg>
            </div>
            <div class="text-xs text-zinc-600 space-y-1">
                <p class="font-medium text-zinc-900">{{ __('Point of Sale Terminal Protection') }}</p>
                <p class="leading-relaxed">
                    {{ __('Direct public self-registration is disabled on this operational terminal to maintain tenant security and role-based access integrity.') }}
                </p>
            </div>
        </div>

        <div class="pt-3 border-t border-zinc-200/70 text-xs text-zinc-500 space-y-2">
            <p class="font-medium text-zinc-800">{{ __('Registration steps:') }}</p>
            <ol class="list-decimal list-inside space-y-1 pl-1">
                <li>{{ __('Submit your business and owner details on the portal') }}</li>
                <li>{{ __('Activate your account via the secure email link') }}</li>
                <li>{{ __('Complete your business profile and initial branch setup') }}</li>
            </ol>
        </div>
    </div>

    <!-- Actions -->
    <div class="space-y-3">
        <a
            href="{{ config('services.marketing.register_url', env('MARKETING_REGISTER_URL', rtrim(env('MARKETING_URL', 'http://localhost:8080'), '/') . '/register')) }}"
            style="background-color: #141413; color: #ffffff;"
            class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99]"
        >
            <span>{{ __('Continue to registration portal') }}</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </a>

        <a
            href="{{ route('login') }}"
            class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 border border-zinc-200 bg-white hover:bg-zinc-50 text-zinc-800 text-sm cursor-pointer shadow-2xs active:scale-[0.99]"
        >
            {{ __('Return to sign in') }}
        </a>
    </div>

    <!-- Footer Help -->
    <div class="mt-8 pt-6 border-t border-zinc-100 text-xs text-zinc-500 leading-relaxed">
        {{ __('Already received an activation email?') }}
        <span class="text-zinc-600 block mt-0.5">
            {{ __('Check your inbox for your master password activation link.') }}
        </span>
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
                            Register your business name and owner credentials on the official onboarding portal.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            2. Activation & Credentials
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Verify your account and configure your master password via email activation.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            3. Branch & Operations
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Configure your first branch, tables, products, and begin recording sales.
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
