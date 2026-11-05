@extends('layouts.guest')

@section('title', 'Set Your Password — Apeiron')

@section('content')
    @if (session('success'))
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-success-border text-xs text-success leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Success') }}</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Notice') }}</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed error-alert">
            <span class="font-semibold block mb-0.5">{{ __('Activation failed') }}</span>
            <span>{{ $errors->first('password') ?: $errors->first() }}</span>
        </div>
    @endif

    <!-- Heading Block -->
    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-foreground">
            {{ __('Set your password') }}
        </h1>
        <p class="text-sm text-muted-foreground mt-1.5">
            {{ __('Create a master password to activate your owner account and start workspace setup.') }}
        </p>
        @if ($tenantName)
            <div class="mt-3 py-1.5 px-3 rounded-md bg-accent border border-border text-xs text-foreground inline-block font-medium">
                Business: <span class="font-semibold text-foreground">{{ $tenantName }}</span>
            </div>
        @endif
    </div>

    <form action="{{ route('onboarding.set-password.store', ['token' => $token]) }}" method="post" class="space-y-4"
        x-data="{ isLoading: false, showPassword: false, showConfirmation: false }"
        @submit="isLoading = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        {{-- New Password --}}
        <div>
            <label for="password" class="block text-[13px] font-medium text-foreground mb-1.5">{{ __('New password') }}</label>
            <div class="relative">
                <input
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    id="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('At least 10 characters') }}"
                    class="field-input w-full h-10 pl-3 pr-10 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-muted-foreground hover:text-muted-foreground focus:outline-none focus:text-foreground rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="showPassword ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="showPassword ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <svg x-show="!showPassword" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="showPassword" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                        <line x1="2" y1="2" x2="22" y2="22"/>
                    </svg>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-destructive" role="alert">{{ $message }}</p>
            @enderror

            {{-- Password Requirements Hint --}}
            <div class="mt-2 p-2.5 rounded-md bg-accent border border-border text-xs text-muted-foreground space-y-1">
                <p class="font-medium text-foreground">{{ __('Password requirements:') }}</p>
                <ul class="list-disc list-inside space-y-0.5 text-[11px] text-muted-foreground">
                    <li>{{ __('Minimum 10 characters') }}</li>
                    <li>{{ __('Must contain uppercase and lowercase letters (e.g., A-Z, a-z)') }}</li>
                    <li>{{ __('Must contain at least one number (0-9)') }}</li>
                </ul>
            </div>
        </div>

        {{-- Confirm New Password --}}
        <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-foreground mb-1.5">{{ __('Confirm new password') }}</label>
            <div class="relative">
                <input
                    :type="showConfirmation ? 'text' : 'password'"
                    name="password_confirmation"
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Re-enter your new password') }}"
                    class="field-input w-full h-10 pl-3 pr-10 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('password_confirmation') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showConfirmation = !showConfirmation"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-muted-foreground hover:text-muted-foreground focus:outline-none focus:text-foreground rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="showConfirmation ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="showConfirmation ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <svg x-show="!showConfirmation" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="showConfirmation" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                        <line x1="2" y1="2" x2="22" y2="22"/>
                    </svg>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-2">
            <button
                type="submit"
                id="btn-aktivasi"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-primary hover:bg-foreground text-primary-foreground text-sm cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-xs active:scale-[0.99]"
                :disabled="isLoading"
                :class="{ 'opacity-70 cursor-not-allowed': isLoading }"
            >
                <span x-show="isLoading" class="w-3.5 h-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true" x-cloak></span>
                <span x-text="isLoading ? '{{ __('Activating...') }}' : '{{ __('Activate account') }}'">{{ __('Activate account') }}</span>
            </button>
        </div>
    </form>

    <!-- Footer link -->
    <div class="mt-8 pt-6 border-t border-border text-xs text-muted-foreground leading-relaxed">
        {{ __('Already activated your account?') }}
        <a href="{{ route('login') }}" class="text-foreground font-medium underline-offset-4 hover:underline ml-1">
            {{ __('Sign in') }}
        </a>
    </div>
@endsection

@section('panel')
    <div class="hidden md:flex md:col-span-1 lg:col-span-5 editorial-surface text-primary-foreground p-8 sm:p-10 lg:p-12 xl:p-14 flex-col justify-between select-none relative overflow-hidden bg-foreground min-h-screen">
        <div class="absolute inset-0 bg-cover bg-center opacity-20 mix-blend-luminosity pointer-events-none"
            style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');"></div>
        <div class="absolute inset-0 pointer-events-none"></div>

        <div class="relative z-10 text-xs text-muted-foreground font-medium tracking-wide">
            Workspace Setup
        </div>

        <div class="relative z-10 my-auto max-w-xs space-y-8">
            <div class="space-y-2">
                <p class="text-lg font-normal tracking-tight text-background leading-snug">
                    Complete your setup to access your business workspace.
                </p>
            </div>

            <div class="space-y-6 border-l border-shell-border pl-5">
                <div>
                    <div class="text-xs text-primary-foreground font-medium">
                        1. Account Credentials
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        Set a secure master password for the owner account.
                    </div>
                </div>

                <div>
                    <div class="text-xs text-muted-foreground">
                        2. Business Profile
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        Confirm your brand name, currency, and primary timezone.
                    </div>
                </div>

                <div>
                    <div class="text-xs text-muted-foreground">
                        3. Initial Branch
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        Configure your first operational branch and registers.
                    </div>
                </div>
            </div>
        </div>

        <div class="relative z-10 flex items-center justify-between text-xs text-muted-foreground pt-6 border-t border-shell-border">
            <span>Apeiron Systems</span>
            <span>© {{ date('Y') }}</span>
        </div>
    </div>
@endsection
