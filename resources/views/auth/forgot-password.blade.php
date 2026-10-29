<x-guest-layout>
    @section('title', 'Forgot Password — Apeiron')

    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-zinc-950">
            {{ __('Forgot password?') }}
        </h1>
        <p class="text-sm text-zinc-500 mt-1.5 leading-relaxed">
            {{ __('Enter your account email address and we will send you a password reset link.') }}
        </p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-emerald-200 text-xs text-emerald-700 leading-relaxed">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-red-200 text-xs text-red-600 leading-relaxed">
            {{ $errors->first('email') ?: $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-[13px] font-medium text-zinc-800 mb-1.5">
                {{ __('Email address') }}
            </label>
            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                placeholder="Enter your Email"
                required
                autofocus
                class="field-input w-full h-10 px-3 rounded-md border border-zinc-300 bg-white text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none focus:border-zinc-950 focus:ring-1 focus:ring-zinc-950 transition-all {{ $errors->has('email') ? 'field-input-error' : '' }}"
            />
        </div>

        <div class="pt-2">
            <button
                type="submit"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-apeiron-ink hover:bg-[#111111] text-white text-sm cursor-pointer shadow-xs active:scale-[0.99]"
            >
                {{ __('Send reset link') }}
            </button>
        </div>
    </form>

    <div class="mt-8 pt-6 border-t border-zinc-100 text-xs text-zinc-500 leading-relaxed">
        <a href="{{ route('login') }}" class="text-zinc-950 font-medium hover:underline underline-offset-4">
            {{ __('Back to sign in') }}
        </a>
    </div>
</x-guest-layout>
