{{-- MODAL DAFTAR KERANJANG TERTUNDA --}}
<x-ui.modal wire:model="isParkedListOpen" maxWidth="lg">
    <x-slot:header>
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[#111827] text-[20px]">pause_circle</span>
            <h3 class="font-headline-sm text-[16px] font-semibold text-[#111827]">Daftar Hold Pesanan (Antrean)</h3>
            <span class="px-1.5 py-0.5 rounded-[5px] bg-[#171717] text-white font-label-sm text-[11px] font-mono">
                {{ $this->parkedCarts->count() }}
            </span>
        </div>
    </x-slot:header>

    <div class="space-y-3">
        <p class="text-[12px] text-[#6B7280]">
            Daftar transaksi pesanan pelanggan yang sedang ditahan di cabang kasir ini.
        </p>

        <div class="space-y-2.5 max-h-[60vh] overflow-y-auto custom-scrollbar pr-1" id="held-orders-list">
            @forelse($this->parkedCarts as $pc)
                @php
                    $pPayload = $pc->cart_payload;
                    $pItems = $pPayload['items'] ?? $pPayload;
                    $pCount = is_array($pItems) ? array_sum(array_column($pItems, 'qty')) : 0;
                    $parkedTime = $pc->parked_at
                        ? ($pc->parked_at->format('H:i') . ' WIB')
                        : ($pc->created_at ? ($pc->created_at->format('H:i') . ' WIB') : '-');
                @endphp
                <div class="p-3 rounded-xl border border-[#E5E5E5] hover:border-[#171717] bg-[#F9FAFB] flex items-center justify-between transition-all">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-semibold text-[13px] text-[#111827]">{{ $pc->hold_reference }}</span>
                            <span class="px-1.5 py-0.2 rounded bg-white text-[10px] border border-[#E5E5E5] font-medium uppercase text-[#111827]">
                                {{ $pc->order_type }}
                            </span>
                        </div>
                        <span class="text-[11px] text-[#6B7280] block mt-0.5">
                            {{ $pCount }} Item &bull; Ditahan jam {{ $parkedTime }}
                        </span>
                        @if($pc->notes)
                            <p class="text-[11px] text-[#9CA3AF] italic mt-0.5">{{ $pc->notes }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" wire:click="resumeCart({{ $pc->id }})"
                            class="px-3 py-1.5 rounded-lg bg-white border border-[#E5E5E5] hover:bg-[#171717] hover:text-white font-medium text-[11px] transition-colors shadow-xs">
                            Muat Pesanan
                        </button>
                        <button type="button" wire:click="discardHeldCart({{ $pc->id }})"
                            class="p-1.5 rounded-lg text-[#9CA3AF] hover:text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus Antrean">
                            <span class="material-symbols-rounded text-[16px] block">close</span>
                        </button>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-[#9CA3AF] space-y-1">
                    <span class="material-symbols-rounded text-[36px] text-gray-300 block mb-1">inbox</span>
                    <p class="text-xs font-semibold text-[#111827]">Tidak ada antrean pesanan yang ditahan.</p>
                    <p class="text-[11px] text-[#6B7280]">Pesanan aktif dapat ditahan menggunakan tombol "Tahan Pesanan (F8)".</p>
                </div>
            @endforelse
        </div>
    </div>

    <x-slot:footer>
        <div class="flex justify-between items-center w-full">
            <button type="button" wire:click="$set('isParkModalOpen', true)"
                class="px-3.5 py-2 rounded-lg bg-[#171717] text-white text-[12px] font-medium hover:bg-[#262626] flex items-center gap-1.5 shadow-xs transition-all">
                <span class="material-symbols-rounded text-[15px]">archive</span>
                <span>Tahan Pesanan Aktif Ini</span>
            </button>
            <button type="button" wire:click="$set('isParkedListOpen', false)"
                class="px-4 py-2 rounded-lg border border-[#E5E5E5] text-[12px] font-medium text-[#111827] hover:bg-[#F9FAFB] transition-colors">
                Tutup
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
