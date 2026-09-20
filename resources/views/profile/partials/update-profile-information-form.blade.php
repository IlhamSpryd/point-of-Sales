<section>
    <header>
        <h2 class="text-lg font-bold text-[#37352F]">
            {{ __('Informasi Profil') }}
        </h2>

        <p class="mt-1 text-sm text-[#787774]">
            {{ __("Perbarui informasi profil dan alamat surel (email) akun Anda.") }}
        </p>
    </header>


    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-form-label for="name">{{ __('Nama') }}</x-form-label>
            <x-form-input id="name" name="name" type="text" class="mt-1 block w-full md:w-2/3" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            @error('name')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <x-form-label for="email">{{ __('Alamat Email') }}</x-form-label>
            <x-form-input id="email" name="email" type="email" class="mt-1 block w-full md:w-2/3" :value="old('email', $user->email)" required autocomplete="username" />
            @error('email')
                <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <x-button type="submit" variant="primary">
                <span class="material-symbols-rounded text-[18px]">save</span> {{ __('Simpan Perubahan') }}
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
</section>
