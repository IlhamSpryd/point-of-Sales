<section class="space-y-6">
    <header>
        <h2 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
            {{ __('Hapus Akun') }} <!-- Standarisasi bahasa UjiKom -->
        </h2>

        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Setelah akun Anda dihapus, semua sumber daya dan data akan dihapus secara permanen. Harap unduh data atau informasi apa pun yang ingin Anda pertahankan.') }} <!-- Standarisasi bahasa UjiKom -->
        </p>
    </header>

    <button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        class="inline-flex items-center justify-center px-4 py-2 bg-rose-600 text-white text-sm font-medium rounded-lg hover:bg-rose-700 transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]"
    ><span class="material-symbols-rounded mr-2">delete</span> {{ __('Hapus Akun') }}</button> <!-- Standarisasi bahasa UjiKom -->

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                {{ __('Apakah Anda yakin ingin menghapus akun Anda?') }} <!-- Standarisasi bahasa UjiKom -->
            </h2>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Setelah akun Anda dihapus, semua sumber daya dan data akan dihapus secara permanen. Masukkan kata sandi Anda untuk mengonfirmasi.') }} <!-- Standarisasi bahasa UjiKom -->
            </p>

            <div class="mt-6">
                <x-form-label for="password">{{ __('Kata Sandi') }}</x-form-label> <!-- Standarisasi bahasa UjiKom -->
                <x-form-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full md:w-3/4"
                    placeholder="{{ __('Kata Sandi') }}"
                />
                @error('password', 'userDeletion')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')" class="px-4 py-2 bg-zinc-50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors border border-zinc-200 dark:border-zinc-700">
                    {{ __('Batal') }} <!-- Standarisasi bahasa UjiKom -->
                </button>

                <button type="submit" class="px-4 py-2 bg-rose-600 text-white text-sm font-medium rounded-lg hover:bg-rose-700 transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
                    {{ __('Hapus Akun') }} <!-- Standarisasi bahasa UjiKom -->
                </button>
            </div>
        </form>
    </x-modal>
</section>
