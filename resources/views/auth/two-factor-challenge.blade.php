@extends('layouts.guest')

@section('title', 'Tantangan Verifikasi 2FA — Apeiron POS')

@section('content')
    @if (session('warning'))
        <div role="alert" class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 leading-relaxed flex items-start gap-2.5">
            <span class="material-symbols-rounded text-base text-amber-700 shrink-0 mt-0.5">security</span>
            <div>
                <span class="font-bold block mb-0.5">Verifikasi Lapis Kedua Diperlukan</span>
                <span>{{ session('warning') }}</span>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed error-alert">
            <span class="font-semibold block mb-0.5">Verifikasi Gagal</span>
            <span>{{ $errors->first('code') ?: $errors->first() }}</span>
        </div>
    @endif

    <div class="mb-8 js-anim" id="anim-heading" x-data="{ recovery: false }">
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            <span x-show="!recovery">Autentikasi Dua Faktor</span>
            <span x-show="recovery" x-cloak>Kode Pemulihan Darurat</span>
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5" x-show="!recovery">
            Buka aplikasi authenticator Anda (Google Authenticator, Microsoft Authenticator, dll.) dan masukkan kode 6-digit.
        </p>
        <p class="text-sm text-zinc-500 mt-1.5" x-show="recovery" x-cloak>
            Masukkan salah satu kode pemulihan darurat yang Anda simpan saat mengaktifkan 2FA.
        </p>

        <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-6 space-y-6">
            @csrf

            <div x-show="!recovery">
                <label for="code" class="block text-xs font-semibold text-zinc-700 uppercase tracking-wider mb-2">Kode Otentikasi (6 Digit)</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                       placeholder="123456" maxlength="6"
                       class="w-full text-center tracking-[0.5em] font-mono text-2xl px-4 py-3 rounded-xl border border-zinc-200 bg-white text-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:border-zinc-900 transition-all shadow-sm">
            </div>

            <div x-show="recovery" x-cloak>
                <label for="recovery_code" class="block text-xs font-semibold text-zinc-700 uppercase tracking-wider mb-2">Kode Pemulihan Darurat</label>
                <input type="text" id="recovery_code" name="code"
                       placeholder="XXXXX-XXXXX"
                       class="w-full text-center tracking-wider font-mono text-lg px-4 py-3 rounded-xl border border-zinc-200 bg-white text-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:border-zinc-900 transition-all shadow-sm">
            </div>

            <div class="flex items-center justify-between text-xs pt-2">
                <button type="button" @click="recovery = !recovery" class="text-zinc-600 hover:text-zinc-950 underline font-medium cursor-pointer transition-colors">
                    <span x-show="!recovery">Gunakan kode pemulihan darurat</span>
                    <span x-show="recovery" x-cloak>Gunakan kode aplikasi authenticator</span>
                </button>
            </div>

            <button type="submit" id="btn-verify-2fa" class="w-full h-11 flex items-center justify-center gap-2 rounded-xl bg-zinc-900 text-white font-medium text-sm hover:bg-zinc-800 transition-all shadow-sm active:scale-[0.99] cursor-pointer">
                <span>Konfirmasi Verifikasi</span>
                <span class="material-symbols-rounded text-base">arrow_forward</span>
            </button>
        </form>
    </div>
@endsection
