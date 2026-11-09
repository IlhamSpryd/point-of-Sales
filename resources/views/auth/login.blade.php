@extends('layouts.guest')

@section('title', 'Sign In — Apeiron')

@section('content')
    @if (session('success'))
        <div role="status" aria-live="polite" class="mb-3 pb-2.5 sm:mb-4 sm:pb-3 border-b border-success-border text-xs text-success leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Success') }}</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div role="alert" aria-live="assertive" class="mb-3 pb-2.5 sm:mb-4 sm:pb-3 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Notice') }}</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if (session('info'))
        <div role="status" aria-live="polite" class="mb-3 pb-2.5 sm:mb-4 sm:pb-3 border-b border-border text-xs text-muted-foreground leading-relaxed">
            <span>{{ session('info') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-3 pb-2.5 sm:mb-4 sm:pb-3 border-b border-destructive-border text-xs text-destructive leading-relaxed error-alert">
            <span class="font-semibold block mb-0.5">{{ __('Sign-in failed') }}</span>
            <span>{{ $errors->first('email') ?: ($errors->first('password') ?: $errors->first()) }}</span>
        </div>
    @endif

    <!-- Heading Block -->
    <div class="mb-6 js-anim" id="anim-heading">
        <h1 class="text-2xl sm:text-[26px] font-semibold tracking-tight text-foreground">
            {{ __('Sign in to Apeiron') }}
        </h1>
        <p class="text-sm text-muted-foreground mt-1.5 leading-relaxed">
            {{ __('Enter your credentials to access your workspace.') }}
        </p>
    </div>

    <!-- SSO Buttons (Google & Apple) -->
    <div class="grid grid-cols-2 gap-3.5 mb-5 js-anim" id="anim-social">
        <a href="{{ route('oauth.redirect', ['provider' => 'google', 'intent' => 'login']) }}" id="btn-google-login" aria-label="{{ __('Sign in with Google') }}" class="w-full h-11 flex items-center justify-center gap-2.5 px-3 rounded-xl border border-border bg-card text-xs sm:text-sm font-medium text-foreground hover:bg-muted/70 hover:border-border-strong focus:outline-none focus:ring-2 focus:ring-ring transition-all shadow-2xs active:scale-[0.98] cursor-pointer">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.38005-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            <span>Google</span>
        </a>
        
        <a href="{{ route('oauth.redirect', ['provider' => 'apple', 'intent' => 'login']) }}" id="btn-apple-login" aria-label="{{ __('Sign in with Apple') }}" class="w-full h-11 flex items-center justify-center gap-2.5 px-3 rounded-xl border border-border bg-card text-xs sm:text-sm font-medium text-foreground hover:bg-muted/70 hover:border-border-strong focus:outline-none focus:ring-2 focus:ring-ring transition-all shadow-2xs active:scale-[0.98] cursor-pointer">
            <svg width="18" height="18" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="text-foreground">
                <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
            </svg>
            <span>Apple</span>
        </a>
    </div>

    <!-- Divider -->
    <div class="relative flex items-center justify-center my-6 js-anim" id="anim-divider">
        <div class="w-full border-t border-border"></div>
        <span class="absolute bg-card px-3 text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('or') }}</span>
    </div>

    <!-- Main Login Form -->
    <form action="{{ route('login') }}" method="post" class="space-y-4 sm:space-y-4.5" x-data="loginForm()"
        @submit="handleSubmit($el)" id="login-form">
        @csrf

        {{-- Email or Username --}}
        <div class="js-anim" id="anim-field-email">
            <label for="email" class="block text-xs sm:text-[13px] font-semibold text-foreground mb-1.5">
                {{ __('Email or username') }}
            </label>
            <input
                type="text"
                name="email"
                id="email"
                required
                value="{{ old('email') }}"
                autocomplete="username"
                placeholder="Enter email or username"
                class="field-input w-full h-11 px-3.5 rounded-xl border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all shadow-2xs {{ $errors->has('email') ? 'field-input-error' : '' }}"
            />
            @error('email')
                <p class="mt-1.5 text-xs text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password + Show/Hide Toggle --}}
        <div class="js-anim" id="anim-field-password" x-data="{ show: false }">
            <div class="flex items-baseline justify-between mb-1.5">
                <label for="password" class="text-xs sm:text-[13px] font-semibold text-foreground">
                    {{ __('Password') }}
                </label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-muted-foreground hover:text-foreground font-medium transition-colors">
                        {{ __('Forgot password?') }}
                    </a>
                @endif
            </div>
            <div class="relative">
                <input
                    type="password"
                    :type="show ? 'text' : 'password'"
                    name="password"
                    id="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••••••"
                    class="field-input w-full h-11 pl-3.5 pr-11 rounded-xl border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all shadow-2xs {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    id="btn-toggle-password"
                    @click="show = !show"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-2 text-muted-foreground hover:text-foreground focus:outline-none focus:text-foreground rounded-lg transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <!-- Eye (Show Password) -->
                    <svg x-show="!show" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <!-- Eye Off (Hide Password) -->
                    <svg x-show="show" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
        </div>

        {{-- Remember Me --}}
        <div class="pt-1">
            <label class="inline-flex items-center gap-2.5 cursor-pointer select-none">
                <input
                    type="checkbox"
                    name="remember"
                    id="remember"
                    class="w-4 h-4 rounded-md border-input text-primary focus:ring-foreground accent-primary cursor-pointer"
                />
                <span class="text-xs text-muted-foreground font-medium">{{ __('Remember this device') }}</span>
            </label>
        </div>

        {{-- Submit Button --}}
        <div class="pt-2 js-anim" id="anim-submit">
            <button
                type="submit"
                id="btn-masuk"
                class="w-full h-11 px-4 rounded-xl font-semibold transition-all flex items-center justify-center gap-2 bg-primary hover:bg-foreground text-primary-foreground text-sm cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-sm active:scale-[0.99]"
                :disabled="isLoading"
                :class="{ 'opacity-70 cursor-not-allowed': isLoading }"
                :aria-busy="isLoading"
            >
                <span x-show="isLoading" class="w-4 h-4 animate-spin rounded-full border-2 border-primary-foreground/30 border-t-primary-foreground" aria-hidden="true" x-cloak></span>
                <span x-text="isLoading ? '{{ __('Signing in...') }}' : '{{ __('Sign in') }}'">{{ __('Sign in') }}</span>
            </button>
        </div>
    </form>

    <!-- Footer Register Link -->
    <div class="mt-7 pt-5 border-t border-border text-xs text-muted-foreground text-center leading-relaxed js-anim" id="anim-footer-link">
        {{ __('Need a business account?') }}
        <a href="{{ config('services.marketing.register_url', env('MARKETING_REGISTER_URL', rtrim(env('MARKETING_URL', 'http://localhost:8080'), '/') . '/register')) }}"
            class="text-foreground font-semibold hover:underline underline-offset-4 ml-1">
            {{ __('Register your business') }}
        </a>
    </div>
@endsection

@section('scripts')
    <script>
        window.loginForm = function () {
            return {
                isLoading: false,
                handleSubmit(formEl) {
                    if (this.isLoading) return;
                    this.isLoading = true;
                }
            };
        };

        // Fallback progressive enhancement jika Alpine tertunda
        document.addEventListener('DOMContentLoaded', () => {
            const toggleBtn = document.getElementById('btn-toggle-password');
            const passwordInput = document.getElementById('password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', () => {
                    if (window.Alpine) return;
                    const isPassword = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                });
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            if (window.erpMotion && typeof window.erpMotion.animateAuthScreen === 'function') {
                window.erpMotion.animateAuthScreen();
                @if ($errors->any())
                    window.erpMotion.shakeElement('#login-form');
                @endif
                return;
            }
            if (typeof gsap === 'undefined') return;
            const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const allAnimElems = document.querySelectorAll('.js-anim');

            if (prefersReduced) {
                gsap.set(allAnimElems, { opacity: 1, y: 0 });
                return;
            }
            @if ($errors->any())
                if (window.erpMotion && typeof window.erpMotion.shakeElement === 'function') {
                    window.erpMotion.shakeElement('#login-form');
                }
            @endif
        });
    </script>
@endsection
