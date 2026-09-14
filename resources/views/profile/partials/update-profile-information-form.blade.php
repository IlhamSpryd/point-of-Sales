<section>
    <header>
        <h2 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
            {{ __('Informasi Profil') }} <!-- Standarisasi bahasa UjiKom -->
        </h2>

        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __("Perbarui informasi profil dan alamat surel (email) akun Anda.") }} <!-- Standarisasi bahasa UjiKom -->
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-form-label for="name">{{ __('Nama') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
            <x-form-input id="name" name="name" type="text" class="mt-1 block w-full md:w-2/3" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            @error('name')
                <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="email">{{ __('Alamat Email') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
            <x-form-input id="email" name="email" type="email" class="mt-1 block w-full md:w-2/3" :value="old('email', $user->email)" required autocomplete="username" />
            @error('email')
                <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
            @enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-2">
                    <p class="text-sm text-zinc-800 dark:text-zinc-300">
                        {{ __('Alamat email Anda belum terverifikasi.') }} <!-- Standarisasi bahasa UjiKom -->

                        <button form="send-verification" class="underline text-sm text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-500">
                            {{ __('Klik di sini untuk mengirim ulang email verifikasi.') }} <!-- Standarisasi bahasa UjiKom -->
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-emerald-600 dark:text-emerald-400">
                            {{ __('Tautan verifikasi baru telah dikirim ke alamat email Anda.') }} <!-- Standarisasi bahasa UjiKom -->
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-medium rounded-lg hover:bg-zinc-800 dark:hover:bg-zinc-100 transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
                <span class="material-symbols-rounded mr-2">save</span> {{ __('Simpan Perubahan') }} <!-- Standarisasi bahasa UjiKom -->
            </button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-x-2"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-2"
                    x-init="setTimeout(() => show = false, 3000)"
                    class="text-sm font-medium text-emerald-600 dark:text-emerald-400 flex items-center"
                >
                    <span class="material-symbols-rounded mr-1.5">check_circle</span> {{ __('Tersimpan.') }} <!-- Standarisasi bahasa UjiKom -->
                </p>
            @endif
        </div>
    </form>
</section>
