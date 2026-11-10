<!-- Scrollable Products Area in Cart -->
<div class="flex-1 overflow-y-auto custom-scrollbar space-y-2 pr-1 py-1 min-h-[160px]">
    @if ($pendingOrderId)
        <div class="p-2.5 bg-muted border border-border rounded-lg text-xs font-semibold text-foreground flex items-center justify-between shadow-2xs">
            <span class="flex items-center gap-1.5 truncate">
                <span class="material-symbols-rounded text-[16px] text-muted-foreground">lock</span>
                QR Settlement Mode #{{ $pendingOrderCode }}
            </span>
            <button type="button" wire:click="cancelPendingOrderMode" class="text-xs font-semibold text-rose-600 hover:text-rose-800 transition-colors ml-2 shrink-0">
                Cancel
            </button>
        </div>
    @endif

    {{-- Alert Revalidasi Keranjang Tertunda --}}
    @if(!empty($resumeWarnings))
        <div class="p-2.5 bg-muted border border-border rounded-lg text-xs text-foreground space-y-1">
            <div class="flex items-center justify-between font-bold">
                <span class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-[16px] text-muted-foreground">warning</span>
                    Revalidation Warning:
                </span>
                <button type="button" wire:click="$set('resumeWarnings', [])" class="text-muted-foreground hover:text-foreground font-bold">&times;</button>
            </div>
            <ul class="list-disc list-inside space-y-0.5 text-xs text-muted-foreground">
                @foreach($resumeWarnings as $warn)
                    <li>{{ $warn }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (count($this->cartLines) === 0)
        <div class="h-full min-h-[140px] flex flex-col justify-center items-center text-center py-6 px-3 space-y-1.5 select-none">
            <div class="w-10 h-10 rounded-xl bg-muted flex items-center justify-center text-gray-400 border border-border">
                <span class="material-symbols-rounded text-[24px]">shopping_cart</span>
            </div>
            <p class="text-sm font-semibold text-foreground">Cart is Empty</p>
            <p class="text-xs text-muted-foreground max-w-[200px] leading-tight">Select products from the catalog to begin an order.</p>
        </div>
    @else
        @foreach ($this->cartLines as $line)
            <div wire:key="cart-line-{{ $line['product_id'] }}-{{ implode('-', $line['modifier_ids'] ?? []) }}-{{ md5((string) ($line['notes'] ?? '')) }}-{{ $line['index'] }}"
                class="p-2.5 rounded-lg bg-muted border border-border hover:border-foreground flex flex-col gap-2 transition-all group">

                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-medium text-foreground truncate">
                            {{ $line['product_name'] }}
                        </span>
                        @if (count($line['options']) > 0 || $line['notes'])
                            <span class="text-xs text-muted-foreground leading-tight mt-0.5">
                                {{ collect($line['options'])->pluck('name')->join(', ') }}
                                @if ($line['notes'])
                                    <span class="block text-gray-400 italic">{{ $line['notes'] }}</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    @unless ($pendingOrderId)
                        <button type="button" wire:click="removeFromCart({{ $line['index'] }})"
                            aria-label="Remove {{ $line['product_name'] }} from cart"
                            class="text-muted-foreground hover:text-rose-600 transition-colors p-0.5 shrink-0" title="Remove">
                            <span class="material-symbols-rounded text-[16px]">close</span>
                        </button>
                    @endunless
                </div>

                <div class="flex items-center justify-between pt-1 border-t border-border">
                    <!-- Stepper Quantity -->
                    <div class="flex items-center gap-1 bg-white border border-border rounded-md p-0.5 h-8">
                        @if ($pendingOrderId)
                            <span class="text-sm font-semibold text-foreground px-2 min-w-[20px] text-center tabular-nums">
                                {{ $line['qty'] }}
                            </span>
                        @else
                            <button type="button"
                                wire:click="updateCartQty({{ $line['index'] }}, -1)"
                                aria-label="Decrease quantity of {{ $line['product_name'] }}"
                                class="w-7 h-7 rounded flex items-center justify-center text-muted-foreground hover:bg-muted hover:text-foreground active:scale-95 transition-colors">
                                <span class="material-symbols-rounded text-[14px]">remove</span>
                            </button>
                            <span class="text-sm font-semibold text-foreground px-2 min-w-[20px] text-center tabular-nums">
                                {{ $line['qty'] }}
                            </span>
                            <button type="button"
                                wire:click="updateCartQty({{ $line['index'] }}, 1)"
                                aria-label="Increase quantity of {{ $line['product_name'] }}"
                                class="w-7 h-7 rounded flex items-center justify-center text-muted-foreground hover:bg-muted hover:text-foreground active:scale-95 transition-colors">
                                <span class="material-symbols-rounded text-[14px]">add</span>
                            </button>
                        @endif
                    </div>

                    <!-- Line Subtotal & Item Discount -->
                    <div class="flex flex-col items-end">
                        <span class="text-sm font-semibold text-foreground tabular-nums">
                            Rp {{ number_format($line['order_price'], 0, ',', '.') }}
                        </span>
                        @if (!$pendingOrderId && isset($this->cart[$line['index']]['item_discount_amount']) && $this->cart[$line['index']]['item_discount_amount'] > 0)
                            <span class="text-xs text-rose-600 font-medium tabular-nums">
                                -Rp {{ number_format($this->cart[$line['index']]['item_discount_amount'], 0, ',', '.') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
