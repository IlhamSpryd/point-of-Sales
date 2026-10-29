<x-guest-layout>
    <div class="mb-6 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-apeiron-ink text-white mb-3 shadow-md">
            <span class="material-symbols-rounded text-2xl">badge</span>
        </div>
        <h2 class="text-2xl font-bold tracking-tight text-apeiron-ink">Undangan Bergabung</h2>
        <p class="text-sm text-[#787774] mt-1">
            Anda diundang untuk bergabung dengan <strong class="text-apeiron-ink">{{ $invitation->tenant->name ?? 'Apeiron' }}</strong>
        </p>
    </div>

    @if (session('error'))
        <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-rose-500 text-base">error</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <div class="bg-[#F7F7F5] border border-[#E5E7EB] rounded-xl p-4 mb-6 space-y-2 text-xs">
        <div class="flex justify-between">
            <span class="text-[#787774]">Nama Karyawan:</span>
            <span class="font-semibold text-apeiron-ink">{{ $invitation->name }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-[#787774]">Email:</span>
            <span class="font-semibold text-apeiron-ink">{{ $invitation->email }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-[#787774]">Peran / Jabatan:</span>
            <span class="font-semibold text-apeiron-ink">{{ $invitation->role->name ?? 'Staff' }}</span>
        </div>
    </div>

    <!-- Social Sign In Option -->
    <div class="space-y-2.5 mb-6">
        <a href="{{ route('oauth.redirect', ['provider' => 'google', 'intent' => 'accept_invitation', 'invitation_token' => $token]) }}"
           class="w-full h-11 flex items-center justify-center gap-2.5 rounded-xl border border-[#E5E7EB] bg-white text-sm font-medium text-apeiron-ink hover:bg-[#F7F7F5] transition-colors shadow-sm active:scale-[0.99]">
            <svg class="w-4 h-4" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            Aktifkan dengan Google
        </a>

        <a href="{{ route('oauth.redirect', ['provider' => 'apple', 'intent' => 'accept_invitation', 'invitation_token' => $token]) }}"
           class="w-full h-11 flex items-center justify-center gap-2.5 rounded-xl border border-[#E5E7EB] bg-white text-sm font-medium text-apeiron-ink hover:bg-[#F7F7F5] transition-colors shadow-sm active:scale-[0.99]">
            <svg class="w-4 h-4 fill-current" viewBox="0 0 170 170">
                <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.69-3.04-7.67-7.81-11.96-14.34-5.78-8.91-10.27-18.91-13.47-30-3.2-11.09-4.8-21.84-4.8-32.24 0-14.12 3.69-25.76 11.06-34.92 7.37-9.16 16.5-13.82 27.39-13.98 4.8 0 10.02 1.25 15.66 3.75 5.64 2.5 9.4 3.79 11.28 3.87 1.48 0 5.46-1.33 11.93-3.99 6.47-2.66 11.85-3.83 16.14-3.51 15.35.82 27.13 6.94 35.35 18.36-13.62 8.27-20.31 19.57-20.08 33.9.23 11.22 4.41 20.67 12.56 28.34 3.77 3.61 7.97 6.32 12.6 8.13-2.61 7.74-5.69 15.42-9.24 23.04zM119.22 31.84c0-7.72 2.76-14.88 8.28-21.49 5.53-6.61 12.38-10.35 20.57-11.23.22 1.09.33 2.17.33 3.26 0 7.72-2.88 15.11-8.63 22.18-5.75 7.07-12.72 10.98-20.91 11.73-.22-1.3-.33-2.5-.33-3.61z"/>
            </svg>
            Aktifkan dengan Apple
        </a>
    </div>

    <div class="relative flex py-2 items-center mb-6">
        <div class="flex-grow border-t border-[#E5E7EB]"></div>
        <span class="flex-shrink mx-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider">Atau Setel Kata Sandi</span>
        <div class="flex-grow border-t border-[#E5E7EB]"></div>
    </div>

    <!-- Password Activation Form -->
    <form method="POST" action="{{ route('invitations.process-accept', ['token' => $token]) }}" class="space-y-4">
        @csrf

        <div>
            <x-form-label for="password">Kata Sandi Baru</x-form-label>
            <x-form-input id="password" type="password" name="password" required autocomplete="new-password"
                          placeholder="Minimal 8 karakter" class="{{ $errors->has('password') ? 'input-error' : '' }}" />
            @error('password')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="password_confirmation">Konfirmasi Kata Sandi</x-form-label>
            <x-form-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                          placeholder="Ulangi kata sandi" class="{{ $errors->has('password_confirmation') ? 'input-error' : '' }}" />
            @error('password_confirmation')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full mt-4 h-11 bg-apeiron-ink text-white font-semibold rounded-xl hover:bg-black transition-all shadow-sm active:scale-[0.99] flex items-center justify-center gap-2">
            <span class="material-symbols-rounded text-lg">check_circle</span>
            Aktifkan Akun & Masuk
        </button>
    </form>
</x-guest-layout>
