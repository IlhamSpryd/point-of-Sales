<x-guest-layout>
    @section('title', 'Reset Password — Apeiron')

    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-foreground">
            {{ __('Reset your password') }}
        </h1>
        <p class="text-sm text-muted-foreground mt-1.5 leading-relaxed">
            {{ __('Set a new password for your account.') }}
        </p>
    </div>

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4" x-data="{ showPass: false, showConfirm: false }">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-[13px] font-medium text-foreground mb-1.5">
                {{ __('Email address') }}
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email', $request->email) }}"
                required
                autofocus
                autocomplete="username"
                class="field-input w-full h-10 px-3 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('email') ? 'field-input-error' : '' }}"
            />
            @error('email')
                <p class="mt-1.5 text-xs text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-[13px] font-medium text-foreground mb-1.5">
                {{ __('New password') }}
            </label>
            <div class="relative">
                <input
                    id="password"
                    :type="showPass ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('At least 10 characters') }}"
                    class="field-input w-full h-10 pl-3 pr-10 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showPass = !showPass"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-muted-foreground hover:text-muted-foreground focus:outline-none focus:text-foreground rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="showPass ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="showPass ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <svg x-show="!showPass" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="showPass" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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

        <!-- Confirm Password -->
        <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-foreground mb-1.5">
                {{ __('Confirm new password') }}
            </label>
            <div class="relative">
                <input
                    id="password_confirmation"
                    :type="showConfirm ? 'text' : 'password'"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Re-enter your new password') }}"
                    class="field-input w-full h-10 pl-3 pr-10 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('password_confirmation') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showConfirm = !showConfirm"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1.5 text-muted-foreground hover:text-muted-foreground focus:outline-none focus:text-foreground rounded-md transition-colors cursor-pointer flex items-center justify-center"
                    :aria-label="showConfirm ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    :title="showConfirm ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <svg x-show="!showConfirm" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg x-show="showConfirm" style="display: none;" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-primary hover:bg-foreground text-primary-foreground text-sm cursor-pointer shadow-xs active:scale-[0.99]"
            >
                {{ __('Reset password') }}
            </button>
        </div>
    </form>

    <div class="mt-8 pt-6 border-t border-border text-xs text-muted-foreground leading-relaxed">
        <a href="{{ route('login') }}" class="text-foreground font-medium underline-offset-4 hover:underline">
            {{ __('Back to sign in') }}
        </a>
    </div>
</x-guest-layout>
