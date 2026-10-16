<x-guest-layout>
    @section('title', 'Daftar Akun — Apeiron POS')

    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Daftar Akun Baru') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5">
            {{ __('Lengkapi formulir di bawah ini untuk membuat akun baru.') }}
        </p>
    </div>

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed error-alert">
            <span class="font-semibold block mb-0.5">{{ __('Pendaftaran Gagal') }}</span>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="space-y-4" x-data="{ showPass: false, showConfirm: false }">
        @csrf

        <!-- Name -->
        <div>
            <label for="name" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Nama Lengkap') }}
            </label>
            <input
                id="name"
                type="text"
                name="name"
                value="{{ old('name') }}"
                placeholder="Contoh: Budi Santoso"
                required
                autofocus
                autocomplete="name"
                class="field-input w-full h-10 px-3 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('name') ? 'field-input-error' : '' }}"
            />
            @error('name')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Alamat Email') }}
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                placeholder="name@company.com"
                required
                autocomplete="username"
                class="field-input w-full h-10 px-3 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('email') ? 'field-input-error' : '' }}"
            />
            @error('email')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Kata Sandi') }}
            </label>
            <div class="relative">
                <input
                    id="password"
                    :type="showPass ? 'text' : 'password'"
                    name="password"
                    placeholder="Minimal 8 karakter"
                    required
                    autocomplete="new-password"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showPass = !showPass"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showPass ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'"
                >
                    <span x-text="showPass ? '{{ __('Sembunyikan') }}' : '{{ __('Tampilkan') }}'"></span>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Konfirmasi Kata Sandi') }}
            </label>
            <div class="relative">
                <input
                    id="password_confirmation"
                    :type="showConfirm ? 'text' : 'password'"
                    name="password_confirmation"
                    placeholder="Ulangi kata sandi baru"
                    required
                    autocomplete="new-password"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password_confirmation') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showConfirm = !showConfirm"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showConfirm ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'"
                >
                    <span x-text="showConfirm ? '{{ __('Sembunyikan') }}' : '{{ __('Tampilkan') }}'"></span>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-2">
            <button
                type="submit"
                style="background-color: #202020; color: #ffffff;"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99]"
            >
                {{ __('Daftar Sekarang') }}
            </button>
        </div>
    </form>

    <div class="mt-8 pt-6 border-t border-zinc-100 text-xs text-zinc-500 leading-relaxed">
        {{ __('Sudah memiliki akun?') }}
        <a href="{{ route('login') }}" class="text-zinc-950 font-medium hover:underline underline-offset-4 ml-1">
            {{ __('Masuk ke sistem') }}
        </a>
    </div>

    <x-slot name="panel">
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
                        Point of sale, ledger inventaris, dan rekonsiliasi kasir untuk bisnis F&amp;B modern.
                    </p>
                </div>

                <div class="space-y-6 border-l border-zinc-800 pl-5">
                    <div>
                        <div class="text-xs text-white font-medium">
                            1. Pendaftaran Bisnis
                        </div>
                        <div class="text-xs text-zinc-400 mt-0.5">
                            Daftarkan nama usaha dan identitas pemilik untuk menginisialisasi tenant.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            2. Aktivasi &amp; Kata Sandi
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Verifikasi akun dan tentukan kata sandi master owner melalui tautan email.
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-zinc-500">
                            3. Cabang &amp; Operasional
                        </div>
                        <div class="text-xs text-zinc-500 mt-0.5">
                            Konfigurasi gerai pertama, meja, produk, dan mulai mencatat penjualan.
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 flex items-center justify-between text-xs text-zinc-400 pt-6 border-t border-zinc-900">
                <span>Enterprise Point of Sale</span>
                <span>© {{ date('Y') }}</span>
            </div>
        </div>
    </x-slot>
</x-guest-layout>
