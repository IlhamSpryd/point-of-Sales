<section>
    <header>
        <h2 class="text-lg font-bold text-apeiron-ink">
            {{ __('Informasi Profil') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __("Perbarui nama profil dan alamat email akun Anda.") }}
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

    <!-- Update Nama -->
    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-form-label for="name">{{ __('Nama Lengkap') }}</x-form-label>
            <x-form-input id="name" name="name" type="text" class="mt-1 block w-full md:w-2/3" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            @error('name')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="current_email_display">{{ __('Alamat Email Aktif') }}</x-form-label>
            <x-form-input id="current_email_display" type="email" class="mt-1 block w-full md:w-2/3 bg-[#F7F7F5] cursor-not-allowed" :value="$user->email" disabled />
            <p class="text-xs text-[#787774] mt-1.5">Untuk mengubah alamat email, gunakan formulir verifikasi email di bawah ini.</p>
        </div>

        <div class="flex items-center gap-4">
            <x-button type="submit" variant="primary">
                <span class="material-symbols-rounded text-[18px]">save</span> {{ __('Simpan Nama') }}
            </x-button>

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
                    <span class="material-symbols-rounded mr-1.5 text-[16px]">check_circle</span> {{ __('Tersimpan.') }}
                </p>
            @endif
        </div>
    </form>

    <div class="mt-8 pt-8 border-t border-[#E9E9E7]">
        <h3 class="text-md font-bold text-apeiron-ink">
            {{ __('Perubahan Alamat Email (Verify-Before-Swap)') }}
        </h3>
        <p class="mt-1 text-sm text-[#787774]">
            {{ __('Alamat email lama tetap aktif dan aman hingga kode OTP pada email baru berhasil diverifikasi.') }}
        </p>

        @if ($user->pending_email)
            <!-- Ada email perubahan yang sedang pending verifikasi -->
            <div class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm">
                <div class="flex items-start gap-2.5">
                    <span class="material-symbols-rounded text-amber-600 text-lg shrink-0 mt-0.5">mark_email_unread</span>
                    <div>
                        <p class="font-semibold">Menunggu Verifikasi Email Baru</p>
                        <p class="text-xs text-amber-800 mt-0.5">
                            Permintaan pengalihan ke <strong>{{ $user->pending_email }}</strong> telah diajukan. Masukkan 6-digit OTP yang dikirimkan ke alamat email tersebut.
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.email.verify') }}" class="mt-4 flex flex-col sm:flex-row gap-3 items-center">
                    @csrf
                    <input type="text" name="otp_code" maxlength="6" placeholder="Kode OTP 6 Digit" required
                           class="w-full sm:w-48 h-10 px-3 text-center tracking-widest font-mono text-base font-bold rounded-xl border border-amber-300 bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    
                    <button type="submit" class="w-full sm:w-auto h-10 px-4 bg-amber-700 hover:bg-amber-800 text-white font-medium text-xs rounded-xl shadow-sm transition-all active:scale-95 flex items-center justify-center gap-1.5">
                        <span class="material-symbols-rounded text-base">verified</span>
                        Verifikasi & Ubah Email
                    </button>
                </form>

                <form method="POST" action="{{ route('profile.email.cancel') }}" class="mt-2.5">
                    @csrf
                    <button type="submit" class="text-xs text-rose-600 hover:text-rose-800 hover:underline">
                        Batalkan permintaan perubahan email
                    </button>
                </form>
            </div>
        @else
            <!-- Form Pengajuan Perubahan Email -->
            <form method="POST" action="{{ route('profile.email.request') }}" class="mt-4 space-y-4">
                @csrf

                @if ($user->password_hash !== null)
                    <div>
                        <x-form-label for="current_password_email">{{ __('Kata Sandi Saat Ini (Konfirmasi Keamanan)') }}</x-form-label>
                        <x-form-input id="current_password_email" name="current_password" type="password" class="mt-1 block w-full md:w-2/3" required placeholder="Masukkan kata sandi saat ini" />
                        @error('current_password')
                            <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div>
                    <x-form-label for="new_email">{{ __('Alamat Email Baru') }}</x-form-label>
                    <x-form-input id="new_email" name="new_email" type="email" class="mt-1 block w-full md:w-2/3" required placeholder="email.baru@domain.com" />
                    @error('new_email')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-button type="submit" variant="secondary">
                        <span class="material-symbols-rounded text-[18px]">forward_to_inbox</span> {{ __('Kirim Kode Verifikasi ke Email Baru') }}
                    </x-button>
                </div>
            </form>
        @endif
    </div>
</section>
