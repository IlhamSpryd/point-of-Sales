<x-guest-layout>
    @section('title', 'Reset Password — Apeiron POS')

    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Reset your password') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5 leading-relaxed">
            {{ __('Set a new password for your account.') }}
        </p>
    </div>

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4" x-data="{ showPass: false, showConfirm: false }">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Email address') }}
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email', $request->email) }}"
                required
                autofocus
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
                {{ __('New password') }}
            </label>
            <div class="relative">
                <input
                    id="password"
                    :type="showPass ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('At least 10 characters') }}"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showPass = !showPass"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showPass ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <span x-text="showPass ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Confirm new password') }}
            </label>
            <div class="relative">
                <input
                    id="password_confirmation"
                    :type="showConfirm ? 'text' : 'password'"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Re-enter your new password') }}"
                    class="field-input w-full h-10 pl-3 pr-24 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('password_confirmation') ? 'field-input-error' : '' }}"
                />
                <button
                    type="button"
                    @click="showConfirm = !showConfirm"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-zinc-950 px-1.5 py-0.5 transition-colors cursor-pointer select-none whitespace-nowrap font-medium"
                    :aria-label="showConfirm ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                >
                    <span x-text="showConfirm ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-2">
            <button
                type="submit"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99]"
            >
                {{ __('Reset password') }}
            </button>
        </div>
    </form>

    <div class="mt-8 pt-6 border-t border-zinc-100 text-xs text-zinc-500 leading-relaxed">
        <a href="{{ route('login') }}" class="text-zinc-950 font-medium hover:underline underline-offset-4">
            ← {{ __('Back to sign in') }}
        </a>
    </div>
</x-guest-layout>
