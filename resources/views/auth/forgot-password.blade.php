<x-guest-layout>
    @section('title', 'Forgot Password — Apeiron')

    <div class="mb-8">
        <h1 class="text-2xl font-medium tracking-tight text-foreground">
            {{ __('Forgot password?') }}
        </h1>
        <p class="text-sm text-muted-foreground mt-1.5 leading-relaxed">
            {{ __('Enter your account email address and we will send you a password reset link.') }}
        </p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div role="status" aria-live="polite" class="mb-6 pb-4 border-b border-success-border text-xs text-success leading-relaxed">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" aria-live="assertive" class="mb-6 pb-4 border-b border-destructive-border text-xs text-destructive leading-relaxed">
            {{ $errors->first('email') ?: $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-[13px] font-medium text-foreground mb-1.5">
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
                class="field-input w-full h-10 px-3 rounded-md border border-input bg-card text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-foreground focus:ring-1 focus:ring-foreground transition-all {{ $errors->has('email') ? 'field-input-error' : '' }}"
            />
        </div>

        <div class="pt-2">
            <button
                type="submit"
                class="w-full h-10 px-4 rounded-md font-medium transition-colors flex items-center justify-center gap-2 bg-primary hover:bg-foreground text-primary-foreground text-sm cursor-pointer shadow-xs active:scale-[0.99]"
            >
                {{ __('Send reset link') }}
            </button>
        </div>
    </form>

    <div class="mt-8 pt-6 border-t border-border text-xs text-muted-foreground leading-relaxed">
        <a href="{{ route('login') }}" class="text-foreground font-medium underline-offset-4 hover:underline">
            {{ __('Back to sign in') }}
        </a>
    </div>
</x-guest-layout>
