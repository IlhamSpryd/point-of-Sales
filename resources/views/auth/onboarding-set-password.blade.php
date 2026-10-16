@extends('layouts.guest')

@section('title', 'Aktivasi Akun — Apeiron POS')

@section('content')
    @if (session('success'))
        <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl relative text-sm"
            role="status" aria-live="polite">
            <strong class="font-medium mb-1 block">{{ __('Berhasil') }}</strong>
            <span class="block">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl relative text-sm"
            role="alert" aria-live="assertive">
            <strong class="font-medium mb-1 block">{{ __('Pemberitahuan') }}</strong>
            <span class="block">{{ session('error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl relative text-sm error-alert"
            role="alert" aria-live="assertive">
            <strong class="font-medium mb-1 block">{{ __('Aktivasi Gagal') }}</strong>
            <span class="block">{{ $errors->first('password') ?: $errors->first() }}</span>
        </div>
    @endif

    <div class="mb-8 flex flex-col items-start">
        <!-- Logo & Brand Apeiron System -->
        <div class="flex items-end gap-2 mb-6">
            <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System Logo" class="h-16 w-auto object-contain">
            <span class="text-lg tracking-tight font-brand mb-1">
                <span class="font-bold">Apeiron System</span>
            </span>
        </div>
        
        <!-- Header Text -->
        <div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 mb-3">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Tahap Akhir Onboarding
            </span>
            <h1 class="text-2xl text-apeiron-ink tracking-tight font-semibold">
                {{ __('Aktivasi Akun Owner') }}
            </h1>
            @if ($tenantName)
                <p class="text-sm font-medium text-apeiron-ink mt-1">
                    Bisnis: <span class="font-semibold text-emerald-700">{{ $tenantName }}</span>
                </p>
            @endif
            <p class="text-[#787774] text-sm mt-1.5 leading-relaxed">
                {{ __('Tetapkan kata sandi Anda untuk mengaktifkan akun dan mulai menggunakan Apeiron POS.') }}
            </p>
        </div>
    </div>

    <form action="{{ route('onboarding.set-password.store', ['token' => $token]) }}" method="post" class="space-y-4"
        x-data="{ isLoading: false, showPassword: false, showConfirmation: false }"
        @submit="isLoading = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Password Baru --}}
        <div>
            <label for="password" class="block text-sm font-medium mb-1.5 text-apeiron-ink">{{ __('Kata Sandi Baru') }}</label>
            <div class="relative">
                <input :type="showPassword ? 'text' : 'password'" name="password" id="password" required
                    autocomplete="new-password"
                    class="block w-full pl-3.5 pr-10 py-2.5 border border-[#E9E9E7] rounded-xl leading-5 bg-[#F7F7F5] placeholder-[#9B9A97] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink text-sm transition-all duration-200 {{ $errors->has('password') ? 'border-rose-300 ring-1 ring-rose-300' : '' }}"
                    placeholder="{{ __('Minimal 8 karakter') }}">

                <button type="button" @click="showPassword = !showPassword"
                    class="absolute inset-y-0 right-3 flex items-center text-[#9B9A97] hover:text-apeiron-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] rounded transition-colors duration-200"
                    :aria-label="showPassword ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'">
                    <svg x-show="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg x-show="showPassword" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                    </svg>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Konfirmasi Password --}}
        <div>
            <label for="password_confirmation" class="block text-sm font-medium mb-1.5 text-apeiron-ink">{{ __('Konfirmasi Kata Sandi') }}</label>
            <div class="relative">
                <input :type="showConfirmation ? 'text' : 'password'" name="password_confirmation" id="password_confirmation" required
                    autocomplete="new-password"
                    class="block w-full pl-3.5 pr-10 py-2.5 border border-[#E9E9E7] rounded-xl leading-5 bg-[#F7F7F5] placeholder-[#9B9A97] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink text-sm transition-all duration-200"
                    placeholder="{{ __('Ulangi kata sandi baru') }}">

                <button type="button" @click="showConfirmation = !showConfirmation"
                    class="absolute inset-y-0 right-3 flex items-center text-[#9B9A97] hover:text-apeiron-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] rounded transition-colors duration-200"
                    :aria-label="showConfirmation ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'">
                    <svg x-show="!showConfirmation" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg x-show="showConfirmation" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                    </svg>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-3">
            <button type="submit" id="btn-aktivasi"
                class="w-full flex justify-center items-center gap-2 bg-[#37352F] text-white text-sm font-semibold py-3.5 px-5 rounded-xl hover:bg-[#201F1D] focus:outline-none focus:ring-2 focus:ring-[#37352F]/20 focus:ring-offset-2 transition-all duration-200 active:scale-[0.99] shadow-xs cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="isLoading" :class="{ 'opacity-70 cursor-not-allowed': isLoading }">
                <svg x-show="isLoading" x-cloak style="display: none;" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="isLoading ? '{{ __('Mengaktifkan...') }}' : '{{ __('Aktivasi & Tetapkan Password') }}'">{{ __('Aktivasi & Tetapkan Password') }}</span>
                <svg x-show="!isLoading" class="w-4 h-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </div>
    </form>

    <!-- Footer link -->
    <div class="mt-6 text-center text-sm text-[#787774]">
        {{ __('Sudah pernah mengaktifkan akun?') }}
        <a href="{{ route('login') }}" class="font-medium text-apeiron-ink hover:underline ml-1">
            {{ __('Masuk ke POS') }}
        </a>
    </div>

    <div class="mt-8 text-center">
        <p class="text-xs text-[#C4C3C0]">Apeiron System.inc &copy; {{ date('Y') }}</p>
    </div>
@endsection

@section('panel')
    <div class="hidden lg:flex flex-col justify-between p-12 text-white bg-cover bg-center relative overflow-hidden bg-apeiron-ink"
        style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');">
        <div class="absolute inset-0 bg-apeiron-ink/70 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-[#37352F]/90 via-transparent to-transparent"></div>

        <div class="relative z-10 mt-auto">
            <div class="mb-8 min-h-40">
                <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-white/10 backdrop-blur-sm text-white/90 border border-white/20 mb-4">
                    Onboarding Tenant Baru
                </span>
                <p class="text-2xl font-medium tracking-tight text-white/90 leading-snug mb-5 max-w-lg">
                    Selamat datang di era operasional F&amp;B yang terhubung dan transparan.
                </p>
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-white/10 flex items-center justify-center border border-white/20 overflow-hidden backdrop-blur-sm">
                        <span class="text-white font-medium">AS</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Apeiron POS</p>
                        <p class="text-xs text-white/50 mt-0.5">Operating System for F&amp;B</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
