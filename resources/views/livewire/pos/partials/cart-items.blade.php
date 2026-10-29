<!-- AREA PRODUK YANG DI-SCROLL -->
<div class="flex-1 overflow-y-auto custom-scrollbar p-4 lg:p-5 space-y-3">
    @if ($pendingOrderId)
        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs font-semibold text-amber-900 flex items-center justify-between shadow-sm">
            <span class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-base text-amber-700">lock</span>
                Mode Pelunasan QR #{{ $pendingOrderCode }} (Terkunci)
            </span>
            <button type="button" wire:click="cancelPendingOrderMode" class="text-xs font-bold text-rose-600 hover:text-rose-800 transition-colors">
                Batal
            </button>
        </div>
    @endif

    {{-- Alert Revalidasi Keranjang Tertunda --}}
    @if(!empty($resumeWarnings))
        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1">
            <div class="flex items-center justify-between font-bold">
                <span class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-[16px] text-amber-600">warning</span>
                    Peringatan Revalidasi Keranjang:
                </span>
                <button type="button" wire:click="$set('resumeWarnings', [])" class="text-amber-500 hover:text-amber-800 font-bold">&times;</button>
            </div>
            <ul class="list-disc list-inside space-y-0.5 text-[11px]">
                @foreach($resumeWarnings as $warn)
                    <li>{{ $warn }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (count($this->cartLines) === 0)
        <div class="h-full min-h-[220px] flex flex-col justify-center items-center text-center opacity-70 py-8">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center text-gray-300 mb-3 border border-gray-100">
                <span class="material-symbols-rounded text-[36px]">local_mall</span>
            </div>
            <p class="text-[14px] font-bold text-apeiron-ink">Keranjang Masih Kosong</p>
            <p class="text-xs text-gray-400 mt-1 max-w-[200px]">Pilih produk dari katalog di sebelah kiri untuk memulai pesanan.</p>
        </div>
    @else
        @foreach ($this->cartLines as $line)
            <div wire:key="cart-line-{{ $line['product_id'] }}-{{ implode('-', $line['modifier_ids'] ?? []) }}-{{ md5((string) ($line['notes'] ?? '')) }}-{{ $line['index'] }}"
                class="flex gap-3 p-3 bg-white rounded-xl border border-gray-200 shadow-sm relative group transition-all duration-200 hover:border-gray-300">
                @unless ($pendingOrderId)
                    <button type="button" wire:click="removeFromCart({{ $line['index'] }})"
                        aria-label="Hapus {{ $line['product_name'] }} dari keranjang"
                        class="absolute -top-2 -right-2 bg-white rounded-full p-1.5 border border-gray-200 text-gray-400 hover:text-rose-500 hover:border-rose-200 shadow-sm transition-opacity opacity-100 sm:opacity-0 sm:group-hover:opacity-100 focus:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endunless

                <div class="flex-1 flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <h4 class="text-[13.5px] font-bold text-apeiron-ink line-clamp-2 pr-2 leading-tight">
                            {{ $line['product_name'] }}
                        </h4>
                    </div>

                    @if (count($line['options']) > 0 || $line['notes'])
                        <p class="text-[11px] text-gray-500 mt-1 leading-snug">
                            {{ collect($line['options'])->pluck('name')->join(', ') }}
                            @if ($line['notes'])
                                <span class="block text-gray-400 mt-0.5 italic">{{ $line['notes'] }}</span>
                            @endif
                        </p>
                    @endif

                    <div class="flex items-end justify-between mt-2.5 pt-2 border-t border-gray-100">
                        <div>
                            <span class="text-[13.5px] font-black text-apeiron-ink tabular-nums whitespace-nowrap">
                                Rp {{ number_format($line['order_price'], 0, ',', '.') }}
                            </span>
                            @if (!$pendingOrderId)
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="text-[10px] text-gray-400 font-medium">Diskon:</span>
                                    <input type="number" min="0" placeholder="Rp 0"
                                        value="{{ $this->cart[$line['index']]['item_discount_amount'] ?? '' }}"
                                        wire:change="setItemDiscount({{ $line['index'] }}, $event.target.value)"
                                        class="text-[11px] font-mono px-2 py-0.5 border border-gray-200 rounded-lg w-20 focus:ring-1 focus:ring-gray-900 bg-gray-50/50">
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-0.5 shadow-sm">
                            @if ($pendingOrderId)
                                <span class="text-[12px] font-bold text-gray-700 px-2 py-0.5 leading-none select-none">{{ $line['qty'] }} pcs</span>
                            @else
                                <button type="button"
                                    wire:click="updateCartQty({{ $line['index'] }}, -1)"
                                    aria-label="Kurangi jumlah {{ $line['product_name'] }}"
                                    class="w-6 h-6 flex items-center justify-center rounded text-gray-500 hover:text-apeiron-ink hover:bg-gray-200/60 active:scale-95 transition-all text-xs font-bold focus:outline-none focus-visible:ring-1 focus-visible:ring-gray-900">
                                    -
                                </button>
                                <span class="text-[12px] font-bold text-gray-700 min-w-[20px] text-center leading-none select-none">
                                    {{ $line['qty'] }}
                                </span>
                                <button type="button"
                                    wire:click="updateCartQty({{ $line['index'] }}, 1)"
                                    aria-label="Tambah jumlah {{ $line['product_name'] }}"
                                    class="w-6 h-6 flex items-center justify-center rounded text-gray-500 hover:text-apeiron-ink hover:bg-gray-200/60 active:scale-95 transition-all text-xs font-bold focus:outline-none focus-visible:ring-1 focus-visible:ring-gray-900">
                                    +
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
