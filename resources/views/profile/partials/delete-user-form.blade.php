<section class="space-y-6">
    <header>
        <h2 class="text-lg font-bold text-yovel-ink">
            {{ __('Hapus Akun') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Setelah akun Anda dihapus, semua sumber daya dan data akan dihapus secara permanen. Harap unduh data atau informasi apa pun yang ingin Anda pertahankan.') }}
        </p>
    </header>

    <x-button
        variant="danger"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    ><span class="material-symbols-rounded text-[18px]">delete</span> {{ __('Hapus Akun') }}</x-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-bold text-yovel-ink">
                {{ __('Apakah Anda yakin ingin menghapus akun Anda?') }}
            </h2>

            <p class="mt-1 text-sm text-[#787774]">
                {{ __('Setelah akun Anda dihapus, semua sumber daya dan data akan dihapus secara permanen. Masukkan kata sandi Anda untuk mengonfirmasi.') }}
            </p>

            <div class="mt-6">
                <x-form-label for="password">{{ __('Kata Sandi') }}</x-form-label>
                <x-form-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full md:w-3/4"
                    placeholder="{{ __('Kata Sandi') }}"
                />
                @error('password', 'userDeletion')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-button type="button" variant="secondary" x-on:click="$dispatch('close')">
                    {{ __('Batal') }}
                </x-button>

                <x-button type="submit" variant="danger">
                    {{ __('Hapus Akun') }}
                </x-button>
            </div>
        </form>
    </x-modal>
</section>
