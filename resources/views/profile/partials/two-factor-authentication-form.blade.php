<section>
    <header>
        <h2 class="text-lg font-bold text-apeiron-ink">
            {{ __('Two-Factor Authentication (2FA / TOTP)') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Add an extra layer of security to your account using standard authenticator apps (Google Authenticator, Microsoft Authenticator, or 1Password).') }}
        </p>
    </header>

    @php
        $twoFactorEnabled = $user->hasTwoFactorEnabled();
        $setupInitiated = session('status') === 'two-factor-setup-initiated';
        $sessionSecret = session('two_factor_secret');
        $sessionQrUri = session('two_factor_qr_uri');
        $sessionRecoveryCodes = session('two_factor_recovery_codes', []);
    @endphp

    <div class="mt-6">
        @if ($twoFactorEnabled)
            <div class="p-4 rounded-xl border border-emerald-200 bg-emerald-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                        <span class="material-symbols-rounded">verified_user</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-emerald-950">{{ __('2FA is Active') }}</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-emerald-100 text-emerald-800">
                                {{ __('Protected') }}
                            </span>
                        </div>
                        <p class="text-xs text-emerald-700 mt-0.5">
                            {{ __('Enabled on :time', ['time' => $user->two_factor_confirmed_at?->format('M d, Y H:i') ?? '-']) }}
                        </p>
                    </div>
                </div>

                <div>
                    <!-- Form Disable 2FA -->
                    <form method="POST" action="{{ route('two-factor.disable') }}" onsubmit="return confirm('Are you sure you want to disable two-factor authentication?');" class="flex items-center gap-2">
                        @csrf
                        @method('DELETE')
                        @if (! empty($user->password_hash) || ! empty($user->password))
                            <input type="password" name="password" placeholder="Confirm password" required class="text-xs px-3 py-1.5 rounded-lg border border-zinc-300 focus:ring-1 focus:ring-apeiron-primary">
                        @endif
                        <button type="submit" id="btn-disable-2fa" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-rose-200 text-rose-600 bg-rose-50 hover:bg-rose-100 transition-colors cursor-pointer">
                            <span class="material-symbols-rounded text-sm">lock_open</span>
                            <span>{{ __('Disable 2FA') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        @elseif ($setupInitiated && $sessionSecret)
            <div class="p-6 rounded-xl border border-amber-200 bg-amber-50/50 space-y-4">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-rounded text-amber-600 mt-0.5">qr_code_scanner</span>
                    <div>
                        <h3 class="text-sm font-semibold text-amber-950">{{ __('Step 1: Connect Authenticator App') }}</h3>
                        <p class="text-xs text-amber-800 mt-1">
                            {{ __('Scan the URI below with your authenticator app, or enter the manual secret key.') }}
                        </p>
                    </div>
                </div>

                <div class="bg-white p-4 rounded-lg border border-amber-200 text-xs font-mono break-all select-all text-zinc-800">
                    <strong>{{ __('Secret Key:') }}</strong> {{ $sessionSecret }}<br>
                    <span class="text-zinc-500 text-[11px]">{{ $sessionQrUri }}</span>
                </div>

                @if (! empty($sessionRecoveryCodes))
                    <div class="mt-4">
                        <h4 class="text-xs font-semibold text-amber-950 mb-2">{{ __('Store These Emergency Recovery Codes:') }}</h4>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 bg-white p-3 rounded-lg border border-amber-200">
                            @foreach ($sessionRecoveryCodes as $code)
                                <code class="text-xs font-mono font-bold text-zinc-700 bg-zinc-50 px-2 py-1 rounded text-center">{{ $code }}</code>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-amber-700 mt-1.5">
                            {{ __('Store these recovery codes in a secure location. Each code can only be used once if you lose access to your authenticator device.') }}
                        </p>
                    </div>
                @endif

                <div class="pt-4 border-t border-amber-200">
                    <h3 class="text-sm font-semibold text-amber-950 mb-2">{{ __('Step 2: Confirm 6-Digit Code') }}</h3>
                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-col sm:flex-row gap-3">
                        @csrf
                        <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="123456" required class="w-full sm:w-48 text-center tracking-widest text-base font-mono px-3 py-2 rounded-lg border border-zinc-300 focus:ring-2 focus:ring-apeiron-primary">
                        <button type="submit" id="btn-confirm-2fa" class="px-4 py-2 bg-apeiron-primary text-white text-xs font-semibold rounded-lg hover:bg-apeiron-primary-dark transition-all cursor-pointer">
                            {{ __('Confirm & Enable 2FA') }}
                        </button>
                    </form>
                    @error('code')
                        <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @else
            <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-xl border border-zinc-200 bg-white gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg border border-zinc-200 bg-zinc-50 flex items-center justify-center shrink-0 text-zinc-500">
                        <span class="material-symbols-rounded">shield</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-zinc-900">{{ __('Two-Factor Authentication Not Enabled') }}</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-zinc-100 text-zinc-600 border border-zinc-200">
                                {{ __('Disabled') }}
                            </span>
                        </div>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Protect your business account from unauthorized takeovers.') }}
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <button type="submit" id="btn-enable-2fa" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg bg-apeiron-primary text-white hover:bg-apeiron-primary-dark transition-all active:scale-[0.98] cursor-pointer">
                        <span class="material-symbols-rounded text-sm">lock</span>
                        <span>{{ __('Enable 2FA') }}</span>
                    </button>
                </form>
            </div>
        @endif
    </div>
</section>
