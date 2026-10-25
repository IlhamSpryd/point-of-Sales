<section>
    <header>
        <h2 class="text-lg font-bold text-apeiron-ink">
            {{ __('Akun Tertaut (Google & Apple SSO)') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Tautkan akun Google atau Apple Anda untuk memungkinkan masuk dengan aman sekali klik tanpa perlu memasukkan kata sandi.') }}
        </p>
    </header>

    @php
        $identities = $user->oauthIdentities()->get();
        $googleIdentity = $identities->firstWhere('provider', 'google');
        $appleIdentity = $identities->firstWhere('provider', 'apple');
    @endphp

    <div class="mt-6 space-y-4">
        <!-- GOOGLE OAUTH CARD -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-xl border border-zinc-200 bg-white gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-zinc-200 bg-zinc-50 flex items-center justify-center shrink-0">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.38005-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-zinc-900">Google</h3>
                        @if ($googleIdentity)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span> {{ __('Tertaut') }}
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-zinc-100 text-zinc-600 border border-zinc-200">
                                {{ __('Belum Tertaut') }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        @if ($googleIdentity)
                            {{ $googleIdentity->provider_email }} &bull; {{ __('Terakhir digunakan: :time', ['time' => $googleIdentity->last_used_at ? $googleIdentity->last_used_at->diffForHumans() : __('Belum pernah')]) }}
                        @else
                            {{ __('Gunakan akun Google untuk masuk dengan cepat dan aman.') }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center shrink-0">
                @if ($googleIdentity)
                    <form method="POST" action="{{ route('profile.oauth.unlink', 'google') }}" onsubmit="return confirm('Apakah Anda yakin ingin melepas tautan akun Google ini?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" id="btn-unlink-google" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-rose-200 text-rose-600 bg-rose-50 hover:bg-rose-100 hover:border-rose-300 transition-colors cursor-pointer">
                            <span class="material-symbols-rounded text-sm">link_off</span>
                            <span>{{ __('Lepas Tautan') }}</span>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('profile.oauth.link', 'google') }}">
                        @csrf
                        <button type="submit" id="btn-link-google" class="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-medium rounded-lg border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50 hover:border-zinc-400 shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.38005-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                            </svg>
                            <span>{{ __('Tautkan Akun Google') }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <!-- APPLE OAUTH CARD -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-xl border border-zinc-200 bg-white gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-zinc-200 bg-zinc-50 flex items-center justify-center shrink-0">
                    <svg width="20" height="20" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" class="text-zinc-900">
                        <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-zinc-900">Apple</h3>
                        @if ($appleIdentity)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span> {{ __('Tertaut') }}
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-zinc-100 text-zinc-600 border border-zinc-200">
                                {{ __('Belum Tertaut') }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        @if ($appleIdentity)
                            {{ $appleIdentity->provider_email }} &bull; {{ __('Terakhir digunakan: :time', ['time' => $appleIdentity->last_used_at ? $appleIdentity->last_used_at->diffForHumans() : __('Belum pernah')]) }}
                        @else
                            {{ __('Gunakan Apple ID untuk masuk secara instan ke POS.') }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center shrink-0">
                @if ($appleIdentity)
                    <form method="POST" action="{{ route('profile.oauth.unlink', 'apple') }}" onsubmit="return confirm('Apakah Anda yakin ingin melepas tautan Apple ID ini?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" id="btn-unlink-apple" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-rose-200 text-rose-600 bg-rose-50 hover:bg-rose-100 hover:border-rose-300 transition-colors cursor-pointer">
                            <span class="material-symbols-rounded text-sm">link_off</span>
                            <span>{{ __('Lepas Tautan') }}</span>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('profile.oauth.link', 'apple') }}">
                        @csrf
                        <button type="submit" id="btn-link-apple" class="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-medium rounded-lg border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50 hover:border-zinc-400 shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
                            <svg width="14" height="14" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" class="text-zinc-900">
                                <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
                            </svg>
                            <span>{{ __('Tautkan Akun Apple') }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</section>
