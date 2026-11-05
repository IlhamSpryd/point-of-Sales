{{-- MODAL PARK / HOLD CART --}}
<x-ui.modal wire:model="isParkModalOpen" maxWidth="md">
    <x-slot:header>
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-foreground text-[20px]">pause_circle</span>
            <span class="font-headline-sm text-[16px] font-semibold text-foreground">Tahan Pesanan (Hold Cart)</span>
        </div>
    </x-slot:header>

    <div class="space-y-3.5 text-xs">
        <p class="text-[12px] text-muted-foreground">
            Tahan transaksi pelanggan ini ke daftar antrean sementara untuk melayani pelanggan berikutnya tanpa menghilangkan item terpilih.
        </p>

        <form id="park-cart-form" wire:submit.prevent="parkCurrentCart" class="space-y-3.5">
            <div>
                <label class="font-headline-sm text-[11px] font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Label Antrean / Nama Pelanggan
                </label>
                <input type="text" wire:model="holdReference"
                    class="w-full h-10 px-3 bg-white border border-border rounded-lg text-xs font-semibold text-foreground focus:outline-none focus:border-foreground transition-all"
                    placeholder="Contoh: Meja 03 / Bpk. Rudi">
            </div>

            <div>
                <label class="font-headline-sm text-[11px] font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Catatan Tambahan
                </label>
                <textarea wire:model="holdNotes" rows="2"
                    class="w-full p-3 bg-white border border-border rounded-lg text-xs text-foreground focus:outline-none focus:border-foreground transition-all resize-none"
                    placeholder="Contoh: Menunggu konfirmasi menu teman..."></textarea>
            </div>
        </form>
    </div>

    <x-slot:footer>
        <div class="flex items-center justify-end gap-2 w-full">
            <button type="button" wire:click="$set('isParkModalOpen', false)"
                class="px-4 py-2 rounded-lg border border-border text-xs font-medium text-foreground hover:bg-muted transition-colors">
                Batal
            </button>
            <button type="submit" form="park-cart-form"
                class="px-5 py-2 rounded-lg bg-primary text-white text-xs font-medium hover:bg-primary/90 shadow-sm transition-all active:scale-95 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[15px]">archive</span>
                <span>Simpan & Tahan Pesanan</span>
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
