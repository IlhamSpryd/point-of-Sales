<x-guest-layout>
    <div class="mb-4 text-sm text-zinc-500">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div>
            <x-ui.label for="password">{{ __('Password') }}</x-ui.label>

            <x-ui.input id="password" class="block mt-1 w-full"
                        type="password"
                        name="password"
                        required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-ui.button type="submit">
                {{ __('Confirm password') }}
            </x-ui.button>
        </div>
    </form>
</x-guest-layout>
