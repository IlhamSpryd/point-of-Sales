<section>
    <header>
        <h2 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
            {{ __('Perbarui Kata Sandi') }} <!-- Standarisasi bahasa UjiKom -->
        </h2>

        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Pastikan akun Anda menggunakan kata sandi acak yang panjang demi keamanan.') }} <!-- Standarisasi bahasa UjiKom -->
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <x-form-label for="update_password_current_password">{{ __('Kata Sandi Saat Ini') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
            <x-form-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="current-password" />
            @error('current_password', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="update_password_password">{{ __('Kata Sandi Baru') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
            <x-form-input id="update_password_password" name="password" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="new-password" />
            @error('password', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="update_password_password_confirmation">{{ __('Konfirmasi Kata Sandi') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
            <x-form-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="new-password" />
            @error('password_confirmation', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-medium rounded-lg hover:bg-zinc-800 dark:hover:bg-zinc-100 transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
                <span class="material-symbols-rounded mr-2">key</span> {{ __('Perbarui Kata Sandi') }} <!-- Standarisasi bahasa UjiKom -->
            </button>

            @if (session('status') === 'password-updated')
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
