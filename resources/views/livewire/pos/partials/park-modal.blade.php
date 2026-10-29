{{-- MODAL PARK / HOLD CART --}}
<x-ui.modal wire:model="isParkModalOpen" maxWidth="md">
    <x-slot:header>
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-apeiron-ink text-[22px]">pause_circle</span>
            <span class="text-base font-bold">Tahan Keranjang (Hold)</span>
        </div>
    </x-slot:header>

    <div class="space-y-4">
        <p class="text-xs text-gray-500">
            Simpan pesanan pelanggan ini untuk dilanjutkan nanti tanpa kehilangan item yang sudah dipilih.
        </p>

        <form id="park-cart-form" wire:submit.prevent="parkCurrentCart" class="space-y-4">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">
                    Label / Nama Pelanggan
                </label>
                <input type="text" wire:model="holdReference"
                    class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink outline-none transition-all"
                    placeholder="Contoh: Meja 4 / Pak Budi (opsional)">
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">
                    Catatan Tambahan
                </label>
                <textarea wire:model="holdNotes" rows="2"
                    class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink outline-none transition-all resize-none"
                    placeholder="Contoh: Menunggu teman datang..."></textarea>
            </div>
        </form>
    </div>

    <x-slot:footer>
        <div class="flex items-center justify-end gap-2 w-full">
            <button type="button" wire:click="$set('isParkModalOpen', false)"
                class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                Batal
            </button>
            <button type="submit" form="park-cart-form"
                class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm transition-all active:scale-95">
                Simpan & Tahan Keranjang
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
