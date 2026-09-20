<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-[#37352F] leading-tight">
            {{ __('Activity Logs') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-3xl p-8 border border-[#E9E9E7] shadow-sm text-center">
                <div class="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-gray-100">
                    <span class="material-symbols-rounded text-[32px] text-[#37352F]">history</span>
                </div>
                <h3 class="text-xl font-bold text-[#37352F] mb-2">Modul Segera Hadir</h3>
                <p class="text-sm text-gray-500">Modul Activity Logs ini akan diaktifkan pada iterasi pengembangan berikutnya.</p>
            </div>
        </div>
    </div>
</x-app-layout>
