<section>
    <header>
        <h2 class="text-lg font-bold text-yovel-ink">
            {{ __('Perbarui Kata Sandi') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Pastikan akun Anda menggunakan kata sandi acak yang panjang demi keamanan.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <x-form-label for="update_password_current_password">{{ __('Kata Sandi Saat Ini') }}</x-form-label>
            <x-form-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="current-password" />
            @error('current_password', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="update_password_password">{{ __('Kata Sandi Baru') }}</x-form-label>
            <x-form-input id="update_password_password" name="password" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="new-password" />
            @error('password', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="update_password_password_confirmation">{{ __('Konfirmasi Kata Sandi') }}</x-form-label>
            <x-form-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full md:w-2/3" autocomplete="new-password" />
            @error('password_confirmation', 'updatePassword')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <x-button type="submit" variant="primary">
                <span class="material-symbols-rounded text-[18px]">key</span> {{ __('Perbarui Kata Sandi') }}
            </x-button>

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
                    class="text-sm font-medium text-emerald-600 flex items-center"
                >
                    <span class="material-symbols-rounded mr-1.5 text-[16px]">check_circle</span> {{ __('Tersimpan.') }}
                </p>
            @endif
        </div>
    </form>
</section>
