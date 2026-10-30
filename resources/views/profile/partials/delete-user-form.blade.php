<section class="space-y-6">
    <header>
        <h2 class="text-lg font-bold text-apeiron-ink">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <x-ui.button
        variant="destructive"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    ><span class="material-symbols-rounded text-[18px]">delete</span> {{ __('Delete Account') }}</x-ui.button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-bold text-apeiron-ink">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm text-[#787774]">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <div class="mt-6">
                <x-form-label for="password">{{ __('Password') }}</x-form-label>
                <x-form-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full md:w-3/4"
                    placeholder="{{ __('Password') }}"
                />
                @error('password', 'userDeletion')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button type="submit" variant="destructive">
                    {{ __('Delete Account') }}
                </x-ui.button>
            </div>
        </form>
    </x-modal>
</section>
