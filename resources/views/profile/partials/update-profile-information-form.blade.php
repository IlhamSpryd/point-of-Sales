<section>
    <header>
        <h2 class="text-lg font-bold text-apeiron-ink">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Update your profile display name and account email address.') }}
        </p>
    </header>

    @if (session('success'))
        <div class="mt-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-emerald-500 text-base">check_circle</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mt-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-rose-500 text-base">error</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Update Name -->
    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-form-label for="name">{{ __('Full Name') }}</x-form-label>
            <x-form-input id="name" name="name" type="text" class="mt-1 block w-full md:w-2/3" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            @error('name')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="current_email_display">{{ __('Active Email Address') }}</x-form-label>
            <x-form-input id="current_email_display" type="email" class="mt-1 block w-full md:w-2/3 bg-[#F7F7F5] cursor-not-allowed" :value="$user->email" disabled />
            <p class="text-xs text-[#787774] mt-1.5">To update your email address, use the verification form below.</p>
        </div>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit" variant="primary">
                <span class="material-symbols-rounded text-[18px]">save</span> {{ __('Save Name') }}
            </x-ui.button>

            @if (session('status') === 'profile-updated')
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
                    <span class="material-symbols-rounded mr-1.5 text-[16px]">check_circle</span> {{ __('Saved.') }}
                </p>
            @endif
        </div>
    </form>

    <div class="mt-8 pt-8 border-t border-[#E5E7EB]">
        <h3 class="text-md font-bold text-apeiron-ink">
            {{ __('Email Address Update (Verify-Before-Swap)') }}
        </h3>
        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Your current email remains active and secure until the verification code sent to your new email is confirmed.') }}
        </p>

        @if ($user->pending_email)
            <!-- Pending Email Verification -->
            <div class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm">
                <div class="flex items-start gap-2.5">
                    <span class="material-symbols-rounded text-amber-600 text-lg shrink-0 mt-0.5">mark_email_unread</span>
                    <div>
                        <p class="font-semibold">Pending New Email Verification</p>
                        <p class="text-xs text-amber-800 mt-0.5">
                            A request to change email to <strong>{{ $user->pending_email }}</strong> has been submitted. Enter the 6-digit verification code sent to that address.
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.email.verify') }}" class="mt-4 flex flex-col sm:flex-row gap-3 items-center">
                    @csrf
                    <input type="text" name="otp_code" maxlength="6" placeholder="6-digit code" required
                           class="w-full sm:w-48 h-10 px-3 text-center tracking-widest font-mono text-base font-bold rounded-xl border border-amber-300 bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    
                    <button type="submit" class="w-full sm:w-auto h-10 px-4 bg-amber-700 hover:bg-amber-800 text-white font-medium text-xs rounded-xl shadow-sm transition-all active:scale-95 flex items-center justify-center gap-1.5">
                        <span class="material-symbols-rounded text-base">verified</span>
                        Verify &amp; Update Email
                    </button>
                </form>

                <form method="POST" action="{{ route('profile.email.cancel') }}" class="mt-2.5">
                    @csrf
                    <button type="submit" class="text-xs text-rose-600 hover:text-rose-800 hover:underline">
                        Cancel email change request
                    </button>
                </form>
            </div>
        @else
            <!-- Request Email Change Form -->
            <form method="POST" action="{{ route('profile.email.request') }}" class="mt-4 space-y-4">
                @csrf

                @if ($user->password_hash !== null)
                    <div>
                        <x-form-label for="current_password_email">{{ __('Current Password (Security Confirmation)') }}</x-form-label>
                        <x-form-input id="current_password_email" name="current_password" type="password" class="mt-1 block w-full md:w-2/3" required placeholder="Enter current password" />
                        @error('current_password')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div>
                    <x-form-label for="new_email">{{ __('New Email Address') }}</x-form-label>
                    <x-form-input id="new_email" name="new_email" type="email" class="mt-1 block w-full md:w-2/3" required placeholder="new.email@example.com" />
                    @error('new_email')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-ui.button type="submit" variant="secondary">
                        <span class="material-symbols-rounded text-[18px]">forward_to_inbox</span> {{ __('Send Verification Code to New Email') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </div>
</section>
