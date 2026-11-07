@extends('layouts.guest')

@section('title', 'Verify Email — Apeiron')

@section('content')
    <!-- Status / Error Alerts -->
    @if (session('status') == 'verification-code-sent')
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-success-border text-xs text-success leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Verification code sent') }}</span>
            <span>{{ __('A new 6-digit verification code has been sent to your email inbox.') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Notice') }}</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Verification failed') }}</span>
            <span>{{ $errors->first('code') ?: $errors->first() }}</span>
        </div>
    @endif

    <!-- Heading Block -->
    <div class="mb-6">
        <div class="inline-flex items-center gap-2 px-2 py-0.5 rounded-md border border-border bg-accent text-[11px] font-medium text-foreground mb-3">
            <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
            {{ __('Two-Factor Email Verification') }}
        </div>
        <h1 class="text-2xl font-medium tracking-tight text-foreground">
            {{ __('Verify your email') }}
        </h1>
        <p class="text-sm text-muted-foreground mt-1.5 leading-relaxed">
            {{ __('We sent a 6-digit verification code to') }} <strong class="text-foreground">{{ auth()->user()?->email }}</strong>. {{ __('Enter the code below to activate your workspace.') }}
        </p>
    </div>

    <!-- OTP Input Form -->
    <form method="POST" action="{{ route('verification.otp') }}" class="space-y-4">
        @csrf

        <div>
            <label for="otp-code" class="block text-xs font-medium text-foreground mb-1.5">
                {{ __('6-Digit Verification Code') }}
            </label>
            <input
                id="otp-code"
                type="text"
                name="code"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                required
                autofocus
                placeholder="123456"
                class="w-full text-center tracking-[0.5em] text-2xl font-sans h-12 px-4 rounded-md border border-border bg-card text-foreground focus:outline-none focus:ring-1 focus:ring-foreground transition-all shadow-2xs"
            />
        </div>

        <button
            type="submit"
            class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-primary hover:bg-foreground text-primary-foreground text-sm cursor-pointer shadow-xs active:scale-[0.99]"
        >
            <span>{{ __('Verify & Continue') }}</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
    </form>

    <!-- Resend & Sign Out -->
    <div class="mt-6 pt-4 border-t border-border flex items-center justify-between text-xs">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="text-muted-foreground hover:text-foreground underline font-medium cursor-pointer">
                {{ __('Resend code') }}
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-muted-foreground hover:text-muted-foreground cursor-pointer">
                {{ __('Sign out') }}
            </button>
        </form>
    </div>
@endsection
