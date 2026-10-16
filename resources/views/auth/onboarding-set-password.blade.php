@extends('layouts.guest')

@section('title', 'Aktivasi Akun — Apeiron POS')

@section('content')
    @if (session('success'))
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-emerald-200 text-xs text-emerald-700 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Berhasil') }}</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-rose-200 text-xs text-rose-600 leading-relaxed">
            <span class="font-semibold block mb-0.5">{{ __('Pemberitahuan') }}</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed error-alert">
            <span class="font-semibold block mb-0.5">{{ __('Aktivasi Gagal') }}</span>
            <span>{{ $errors->first('password') ?: $errors->first() }}</span>
        </div>
    @endif

    <!-- Heading Block -->
    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Aktivasi Akun Owner') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5">
            {{ __('Tetapkan kata sandi Anda untuk mengaktifkan akun dan melanjutkan ke pengaturan workspace.') }}
        </p>
        @if ($tenantName)
            <div class="mt-3 py-1.5 px-3 rounded-md bg-zinc-50 border border-zinc-200 text-xs text-zinc-700 inline-block font-medium">
                Bisnis: <span class="font-semibold text-zinc-950">{{ $tenantName }}</span>
            </div>
        @endif
    </div>

    <form action="{{ route('onboarding.set-password.store', ['token' => $token]) }}" method="post" class="space-y-4"
        x-data="{ isLoading: false, showPassword: false, showConfirmation: false }"
        @submit="isLoading = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Password Baru --}}
        <div>
            <label for="password" class="block text-[13px] font-medium text-zinc-800 mb-1.5">{{ __('Kata Sandi Baru') }}</label>
            <div class="relative">
                <input
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    id="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Minimal 8 karakter') }}"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showPassword ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'"
                >
                    <span x-text="showPassword ? '{{ __('Sembunyikan') }}' : '{{ __('Tampilkan') }}'"></span>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        {{-- Konfirmasi Password --}}
        <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-zinc-800 mb-1.5">{{ __('Konfirmasi Kata Sandi') }}</label>
            <div class="relative">
                <input
                    :type="showConfirmation ? 'text' : 'password'"
                    name="password_confirmation"
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Ulangi kata sandi baru') }}"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password_confirmation') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showConfirmation = !showConfirmation"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showConfirmation ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'"
                >
                    <span x-text="showConfirmation ? '{{ __('Sembunyikan') }}' : '{{ __('Tampilkan') }}'"></span>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-2">
            <button
                type="submit"
                id="btn-aktivasi"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-xs active:scale-[0.99]"
                :disabled="isLoading"
                :class="{ 'opacity-70 cursor-not-allowed': isLoading }"
            >
                <span x-show="isLoading" class="w-3.5 h-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true" x-cloak></span>
                <span x-text="isLoading ? '{{ __('Mengaktifkan...') }}' : '{{ __('Aktivasi Akun') }}'">{{ __('Aktivasi Akun') }}</span>
            </button>
        </div>
    </form>

    <!-- Footer link -->
    <div class="mt-8 pt-6 border-t border-zinc-100 text-xs text-zinc-500 leading-relaxed">
        {{ __('Sudah pernah mengaktifkan akun?') }}
        <a href="{{ route('login') }}" class="text-zinc-950 font-medium hover:underline underline-offset-4 ml-1">
            {{ __('Masuk ke sistem') }}
        </a>
    </div>
@endsection

@section('panel')
    <div class="hidden md:flex md:col-span-1 lg:col-span-5 editorial-surface text-white p-8 sm:p-10 lg:p-12 xl:p-14 flex-col justify-between select-none relative overflow-hidden bg-[#0A0A0B] min-h-screen">
        <div class="absolute inset-0 bg-cover bg-center opacity-20 mix-blend-luminosity pointer-events-none"
            style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-[#0A0A0B] via-[#0A0A0B]/60 to-transparent pointer-events-none"></div>

        <div class="relative z-10 text-xs text-zinc-400 font-medium tracking-wide">
            Pengaturan Workspace
        </div>

        <div class="relative z-10 my-auto max-w-xs space-y-8">
            <div class="space-y-2">
                <p class="text-lg font-normal tracking-tight text-zinc-100 leading-snug">
                    Selesaikan pengaturan akun untuk mengakses ruang kerja bisnis Anda.
                </p>
            </div>

            <div class="space-y-6 border-l border-zinc-800 pl-5">
                <div>
                    <div class="text-xs text-white font-medium">
                        1. Kredensial Akun
                    </div>
                    <div class="text-xs text-zinc-400 mt-0.5">
                        Tetapkan kata sandi master untuk akun pemilik (Owner).
                    </div>
                </div>

                <div>
                    <div class="text-xs text-zinc-500">
                        2. Profil Bisnis
                    </div>
                    <div class="text-xs text-zinc-500 mt-0.5">
                        Konfirmasi nama usaha, mata uang, dan zona waktu.
                    </div>
                </div>

                <div>
                    <div class="text-xs text-zinc-500">
                        3. Lokasi Cabang Pertama
                    </div>
                    <div class="text-xs text-zinc-500 mt-0.5">
                        Daftarkan cabang operasional utama Anda.
                    </div>
                </div>
            </div>
        </div>

        <div class="relative z-10 flex items-center justify-between text-xs text-zinc-400 pt-6 border-t border-zinc-900">
            <span>Apeiron Systems</span>
            <span>© {{ date('Y') }}</span>
        </div>
    </div>
@endsection
