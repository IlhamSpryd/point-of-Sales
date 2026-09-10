<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Profile</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Manage your account settings and preferences</p>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full p-6 md:p-8">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full p-6 md:p-8">
            @include('profile.partials.update-password-form')
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full p-6 md:p-8">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
