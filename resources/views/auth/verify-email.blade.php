<x-guest-layout>
    <div class="mb-4 text-sm text-zinc-500">
        {{ __('Before getting started, please verify your email address by clicking the link we sent to your inbox. If you did not receive the email, we will gladly send you another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-button variant="primary">
                    {{ __('Resend verification email') }}
                </x-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-zinc-500 hover:text-zinc-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-950">
                {{ __('Sign out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
