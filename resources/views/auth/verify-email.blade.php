@extends('layouts.guest')

@section('title', 'Verify Email — Apeiron')

@section('content')
    <!-- Status / Error Alerts -->
    @if (session('status') == 'verification-code-sent')
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-emerald-200 text-xs text-emerald-700 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Verification code sent') }}</span>
            <span>{{ __('A new 6-digit verification code has been sent to your email inbox.') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-rose-200 text-xs text-rose-600 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Notice') }}</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Verification failed') }}</span>
            <span>{{ $errors->first('code') ?: $errors->first() }}</span>
        </div>
    @endif

    <!-- Heading Block -->
    <div class="mb-6">
        <div class="inline-flex items-center gap-2 px-2 py-0.5 rounded-md border border-zinc-200/80 bg-zinc-50 text-[11px] font-medium text-zinc-700 mb-3">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            {{ __('Two-Factor Email Verification') }}
        </div>
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Verify your email') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5 leading-relaxed">
            {{ __('We sent a 6-digit verification code to') }} <strong class="text-zinc-800">{{ auth()->user()?->email }}</strong>. {{ __('Enter the code below to activate your workspace.') }}
        </p>
    </div>

    <!-- OTP Input Form -->
    <form method="POST" action="{{ route('verification.otp') }}" class="space-y-4">
        @csrf

        <div>
            <label for="otp-code" class="block text-xs font-medium text-zinc-700 mb-1.5">
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
                class="w-full text-center tracking-[0.5em] text-2xl font-mono h-12 px-4 rounded-md border border-zinc-200 bg-white text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 transition-all shadow-2xs"
            />
        </div>

        <button
            type="submit"
            style="background-color: #141413; color: #ffffff;"
            class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99]"
        >
            <span>{{ __('Verify & Continue') }}</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
    </form>

    <!-- Resend & Sign Out -->
    <div class="mt-6 pt-4 border-t border-zinc-100 flex items-center justify-between text-xs">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="text-zinc-600 hover:text-zinc-950 underline font-medium cursor-pointer">
                {{ __('Resend code') }}
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                {{ __('Sign out') }}
            </button>
        </form>
    </div>
@endsection
