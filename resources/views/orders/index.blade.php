<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-bold leading-tight text-[#37352F]">
            {{ __('Riwayat Pesanan') }}
        </h2>
    </x-slot>

    <div class="flex flex-col items-center justify-center py-20 text-center animate-fade-in">
        <div class="flex items-center justify-center w-24 h-24 mb-6 rounded-full bg-gray-50 border-8 border-white shadow-sm">
            <span class="material-symbols-rounded text-[40px] text-gray-300">receipt_long</span>
        </div>
        <h3 class="text-xl font-bold text-[#37352F] mb-2">Modul Sedang Dibangun</h3>
        <p class="text-gray-500 max-w-md">Fitur riwayat pesanan komprehensif sedang dalam tahap pengembangan dan akan segera hadir pada pembaruan berikutnya.</p>
        
        <a href="{{ route('dashboard') }}" class="mt-8 px-6 py-3 bg-[#37352F] text-white font-bold rounded-xl hover:bg-black transition-colors shadow-sm inline-flex items-center gap-2">
            <span class="material-symbols-rounded text-[20px]">arrow_back</span>
            Kembali ke Dashboard
        </a>
    </div>
</x-app-layout>
