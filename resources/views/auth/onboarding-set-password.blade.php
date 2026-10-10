<x-guest-layout>
    <form method="POST" action="{{ route('onboarding.set-password.store', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Aktivasi akun{{ $tenantName ? ' — '.$tenantName : '' }}</h2>
            <p class="text-sm text-gray-600">Tetapkan password untuk mengaktifkan akun Owner Anda.</p>
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-button variant="primary">
                {{ __('Aktivasi & Tetapkan Password') }}
            </x-button>
        </div>
    </form>
</x-guest-layout>
