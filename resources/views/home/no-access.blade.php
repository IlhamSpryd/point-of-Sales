<x-app-layout>
    <div class="flex h-full flex-col items-center justify-center text-center px-6 py-16">
        <div class="w-16 h-16 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-5">
            <span class="material-symbols-rounded text-[32px] text-[#9B9A97]">lock_person</span>
        </div>
        <h1 class="text-xl font-bold text-yovel-ink">Belum ada modul untuk peran Anda</h1>
        <p class="mt-2 max-w-sm text-sm text-[#787774]">
            Peran <strong>{{ auth()->user()->role?->name ?? '—' }}</strong> belum dipetakan ke halaman manapun.
            Hubungi Owner untuk mengatur akses Anda.
        </p>
        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit"
                class="inline-flex items-center gap-2 rounded-xl border border-[#E9E9E7] bg-white px-5 py-2.5 text-sm font-semibold text-yovel-ink hover:bg-[#F7F7F5] transition-all active:scale-95 shadow-sm">
                <span class="material-symbols-rounded text-[18px]">logout</span> Keluar
            </button>
        </form>
    </div>
</x-app-layout>
