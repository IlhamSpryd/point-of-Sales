<!-- Area Produk Yang Di-scroll Dalam Keranjang -->
<div class="flex-1 overflow-y-auto custom-scrollbar space-y-2.5 pr-1 py-1">
    @if ($pendingOrderId)
        <div class="p-2.5 bg-amber-50 border border-amber-200 rounded-lg text-xs font-semibold text-amber-900 flex items-center justify-between shadow-xs">
            <span class="flex items-center gap-1.5 truncate">
                <span class="material-symbols-rounded text-[16px] text-amber-700">lock</span>
                QR Settlement Mode #{{ $pendingOrderCode }}
            </span>
            <button type="button" wire:click="cancelPendingOrderMode" class="text-xs font-bold text-rose-600 hover:text-rose-800 transition-colors ml-2 shrink-0">
                Batal
            </button>
        </div>
    @endif

    {{-- Alert Revalidasi Keranjang Tertunda --}}
    @if(!empty($resumeWarnings))
        <div class="p-2.5 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900 space-y-1">
            <div class="flex items-center justify-between font-bold">
                <span class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-[15px] text-amber-600">warning</span>
                    Peringatan Revalidasi:
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
        <div class="h-full min-h-[140px] flex flex-col justify-center items-center text-center py-6 px-3 space-y-1.5 select-none">
            <div class="w-10 h-10 rounded-xl bg-muted flex items-center justify-center text-gray-400 border border-border">
                <span class="material-symbols-rounded text-[22px]">shopping_cart</span>
            </div>
            <p class="font-headline-sm text-[13px] font-semibold text-foreground">Keranjang Kosong</p>
            <p class="font-body-sm text-[11px] text-muted-foreground max-w-[200px] leading-tight">Pilih produk dari menu di sebelah kiri untuk memulai pesanan.</p>
        </div>
    @else
        @foreach ($this->cartLines as $line)
            <div wire:key="cart-line-{{ $line['product_id'] }}-{{ implode('-', $line['modifier_ids'] ?? []) }}-{{ md5((string) ($line['notes'] ?? '')) }}-{{ $line['index'] }}"
                class="p-2.5 rounded-lg bg-muted border border-border hover:border-foreground flex flex-col gap-2 transition-all group">

                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col min-w-0">
                        <span class="font-headline-sm text-[13px] font-medium text-foreground truncate">
                            {{ $line['product_name'] }}
                        </span>
                        @if (count($line['options']) > 0 || $line['notes'])
                            <span class="font-body-sm text-[11px] text-muted-foreground leading-tight mt-0.5">
                                {{ collect($line['options'])->pluck('name')->join(', ') }}
                                @if ($line['notes'])
                                    <span class="block text-gray-400 italic">{{ $line['notes'] }}</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    @unless ($pendingOrderId)
                        <button type="button" wire:click="removeFromCart({{ $line['index'] }})"
                            aria-label="Hapus {{ $line['product_name'] }} dari keranjang"
                            class="text-muted-foreground hover:text-rose-600 transition-colors p-0.5 shrink-0" title="Hapus">
                            <span class="material-symbols-rounded text-[15px]">close</span>
                        </button>
                    @endunless
                </div>

                <div class="flex items-center justify-between pt-1 border-t border-border">
                    <!-- Stepper Quantity -->
                    <div class="flex items-center gap-1 bg-white border border-border rounded-md p-0.5 h-8">
                        @if ($pendingOrderId)
                            <span class="font-numeric-currency text-[13px] font-semibold text-foreground px-2 min-w-[20px] text-center font-mono">
                                {{ $line['qty'] }}
                            </span>
                        @else
                            <button type="button"
                                wire:click="updateCartQty({{ $line['index'] }}, -1)"
                                aria-label="Kurangi kuantitas {{ $line['product_name'] }}"
                                class="w-7 h-7 rounded flex items-center justify-center text-muted-foreground hover:bg-muted hover:text-foreground active:scale-95 transition-colors">
                                <span class="material-symbols-rounded text-[14px]">remove</span>
                            </button>
                            <span class="font-numeric-currency text-[13px] font-semibold text-foreground px-2 min-w-[20px] text-center font-mono">
                                {{ $line['qty'] }}
                            </span>
                            <button type="button"
                                wire:click="updateCartQty({{ $line['index'] }}, 1)"
                                aria-label="Tambah kuantitas {{ $line['product_name'] }}"
                                class="w-7 h-7 rounded flex items-center justify-center text-muted-foreground hover:bg-muted hover:text-foreground active:scale-95 transition-colors">
                                <span class="material-symbols-rounded text-[14px]">add</span>
                            </button>
                        @endif
                    </div>

                    <!-- Line Subtotal & Item Discount -->
                    <div class="flex flex-col items-end">
                        <span class="font-numeric-currency text-[13px] font-semibold text-foreground font-mono">
                            Rp {{ number_format($line['order_price'], 0, ',', '.') }}
                        </span>
                        @if (!$pendingOrderId && isset($this->cart[$line['index']]['item_discount_amount']) && $this->cart[$line['index']]['item_discount_amount'] > 0)
                            <span class="text-[10px] text-rose-600 font-mono">
                                -Rp {{ number_format($this->cart[$line['index']]['item_discount_amount'], 0, ',', '.') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
