<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Profil</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola pengaturan dan preferensi akun Anda</p>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card-surface p-6 md:p-8">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="card-surface p-6 md:p-8">
            @include('profile.partials.update-password-form')
        </div>

        <div class="card-surface p-6 md:p-8">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
