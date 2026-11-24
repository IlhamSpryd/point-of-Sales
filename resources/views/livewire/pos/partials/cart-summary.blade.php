<!-- CASHIER CALCULATION & PAYMENT AREA -->
<div class="pt-2 border-t border-border bg-card shrink-0 z-10 flex flex-col gap-2">
    {{-- Promo & Voucher Banner --}}
    @php
        $pipelineBreakdown = $this->getCalculationBreakdown();
    @endphp
    @if(!empty($pipelineBreakdown->appliedPromotionsSummary))
        <div class="flex flex-col gap-1 p-2 bg-muted border border-border rounded-lg text-xs">
            <span class="font-semibold text-foreground flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[14px] text-muted-foreground">local_offer</span>
                Active Promotion Applied
            </span>
            @foreach($pipelineBreakdown->appliedPromotionsSummary as $promo)
                <div wire:key="promo-{{ $promo->id ?? $loop->index }}" class="flex justify-between text-xs text-muted-foreground">
                    <span>{{ $promo['name'] ?? 'Automatic Promotion' }}</span>
                    <span class="font-semibold text-foreground tabular-nums">- Rp {{ number_format($promo['discount_amount'] ?? 0, 0, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Coupon / Voucher Code & Master Discount (Expandable) --}}
    <div x-data="{ openPromo: {{ ($voucherCode || $selectedDiscountId) ? 'true' : 'false' }} }" class="space-y-1.5">
        @if ($voucherCode)
            <div class="flex items-center justify-between p-2 bg-muted border border-border rounded-lg text-xs">
                <span class="font-semibold text-foreground flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-[14px] text-muted-foreground">confirmation_number</span>
                    Voucher: {{ $voucherCode }}
                </span>
                <button type="button" wire:click.prevent="removeVoucher" class="text-muted-foreground hover:text-destructive font-bold p-1 text-sm leading-none cursor-pointer" title="Remove Voucher">&times;</button>
            </div>
        @elseif (!$pendingOrderId)
            <div x-show="!openPromo" class="flex items-center justify-between text-xs px-0.5">
                <button type="button" @click="openPromo = true" class="text-muted-foreground hover:text-foreground font-medium flex items-center gap-1 transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-[14px] text-muted-foreground">sell</span>
                    <span>+ Add Coupon / Discount</span>
                </button>
            </div>
            <div x-show="openPromo" x-cloak class="space-y-1.5 p-2 bg-muted/60 border border-border rounded-lg">
                <div class="flex items-center gap-1.5">
                    <input type="text" wire:model="voucherInput" wire:keydown.enter.prevent="applyVoucher"
                        placeholder="Coupon / voucher code..."
                        class="flex-1 min-h-[40px] h-10 px-3 text-xs bg-background border border-border rounded-md text-foreground placeholder-muted-foreground focus:border-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase tabular-nums transition-colors" />
                    <button type="button" wire:click.prevent="applyVoucher"
                        class="min-h-[40px] h-10 px-3 text-xs font-medium text-primary-foreground bg-primary hover:bg-primary/90 rounded-md transition-colors shadow-2xs cursor-pointer">
                        Apply
                    </button>
                    <button type="button" @click="openPromo = false" class="text-muted-foreground hover:text-foreground p-1 text-xs cursor-pointer leading-none" title="Close">
                        &times;
                    </button>
                </div>
                @if ($voucherError)
                    <span class="text-xs text-destructive block">{{ $voucherError }}</span>
                @endif
                @if (count($this->activeDiscounts) > 0)
                    <div class="relative">
                        <select wire:model.live="selectedDiscountId"
                            @disabled((bool) $pendingOrderId)
                            class="w-full min-h-[40px] h-10 pl-3 pr-8 bg-background border border-border rounded-md text-xs font-medium text-foreground focus:border-foreground appearance-none transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <option value="">+ Select Discount / Promo</option>
                            @foreach ($this->activeDiscounts as $d)
                                <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->type === 'percentage' ? $d->value . '%' : 'Rp ' . number_format($d->value, 0, ',', '.') }})</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-1.5 text-muted-foreground">
                            <span class="material-symbols-rounded text-[14px]">expand_more</span>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Manual Discount Badge if applied --}}
    @if($appliedManualDiscount > 0)
        <div class="flex items-center justify-between p-2 bg-muted border border-border rounded-lg text-xs">
            <div class="min-w-0">
                <span class="font-semibold text-foreground">Manual Discount: Rp {{ number_format($appliedManualDiscount, 0, ',', '.') }}</span>
                <span class="text-xs text-muted-foreground block truncate">{{ $appliedManualDiscountReason }}</span>
            </div>
            <button type="button" wire:click.prevent="removeManualDiscount" class="text-muted-foreground hover:text-destructive font-bold p-1 text-sm leading-none cursor-pointer" title="Remove Manual Discount">&times;</button>
        </div>
    @endif

    {{-- Subtotal & Discount Breakdown --}}
    <div class="pt-2 pb-0.5 space-y-1 text-xs border-t border-border">
        <div class="flex justify-between text-muted-foreground">
            <span>Subtotal</span>
            <span class="text-foreground font-medium tabular-nums">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
        </div>

        {{-- Member Discount / Override Discount --}}
        <div class="flex justify-between items-center text-muted-foreground cursor-pointer hover:bg-muted p-0.5 rounded-md transition-colors"
            wire:click.prevent="$set('isManualDiscountModalOpen', true)" title="Configure discount or supervisor authorization">
            <div class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[14px] text-muted-foreground">sell</span>
                <span>Discount / Promo</span>
                @if ($this->discountAmount > 0)
                    <span class="px-1.5 py-0.5 rounded bg-muted border border-border text-muted-foreground text-xs tabular-nums">
                        Applied
                    </span>
                @endif
            </div>
            <span class="text-foreground font-medium tabular-nums">
                {{ $this->discountAmount > 0 ? '-Rp ' . number_format($this->discountAmount, 0, ',', '.') : 'Rp 0' }}
            </span>
        </div>

        {{-- Pajak PB1 & Service --}}
        <div class="flex justify-between text-muted-foreground">
            <span>Tax / VAT ({{ $this->taxRatePercent }}%)</span>
            <span class="text-foreground font-medium tabular-nums">Rp {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
        </div>

        {{-- Total Akhir --}}
        <div class="pt-1.5 mt-1 border-t border-border flex justify-between items-baseline">
            <div class="flex flex-col">
                <span class="text-sm font-semibold text-foreground tracking-tight">Total Due</span>
                <span class="text-xs text-muted-foreground">All taxes included</span>
            </div>
            <span class="text-base text-foreground font-semibold tracking-tight tabular-nums">
                Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
            </span>
        </div>
    </div>

    {{-- Fast Cash Tender Tray --}}
    @unless ($pendingOrderId || $this->isOfd())
        @if ($this->canCheckout && count($this->cartLines) > 0)
            <div class="p-2 rounded-xl bg-muted/60 border border-border space-y-1.5 my-0.5">
                <div class="flex items-center justify-between text-xs text-foreground font-semibold">
                    <span>Fast Cash Tender</span>
                    <span class="text-xs font-sans text-muted-foreground tabular-nums">F5 Exact</span>
                </div>
                <div class="grid grid-cols-3 gap-1.5">
                    <button type="button" wire:click.prevent="fastCashPay(0)" wire:loading.attr="disabled"
                        class="min-h-[44px] h-11 px-2.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-semibold flex items-center justify-center transition-colors duration-150 disabled:opacity-50 cursor-pointer">
                        Exact Cash
                    </button>
                    <button type="button" wire:click.prevent="fastCashPay(50000)" wire:loading.attr="disabled"
                        @disabled($this->totalAmount > 50000)
                        class="min-h-[44px] h-11 px-2 rounded-lg bg-card border border-border hover:border-foreground text-foreground text-xs font-medium flex items-center justify-center tabular-nums transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                        Rp 50,000
                    </button>
                    <button type="button" wire:click.prevent="fastCashPay(100000)" wire:loading.attr="disabled"
                        @disabled($this->totalAmount > 100000)
                        class="min-h-[44px] h-11 px-2 rounded-lg bg-card border border-border hover:border-foreground text-foreground text-xs font-medium flex items-center justify-center tabular-nums transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                        Rp 100,000
                    </button>
                </div>
            </div>
        @endif
    @endunless

    @php($isWaiterRole = auth()->user()?->role?->name === 'Waiter')

    {{-- Fast Payment Method Bar (Tunai, QRIS, EDC Kartu, Transfer) --}}
    @unless ($isWaiterRole || $pendingOrderId || $this->isOfd())
        <div class="grid grid-cols-4 gap-1.5 py-0.5">
            <button class="min-h-[44px] h-11 px-1 rounded-md bg-primary text-primary-foreground text-xs text-center font-medium shadow-2xs transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'cash' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Cash
            </button>
            <button class="min-h-[44px] h-11 px-1 rounded-md bg-card border border-border hover:bg-muted text-foreground text-xs text-center font-medium transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'qris' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                QRIS
            </button>
            <button class="min-h-[44px] h-11 px-1 rounded-md bg-card border border-border hover:bg-muted text-foreground text-xs text-center font-medium transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'card' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Card
            </button>
            <button class="min-h-[44px] h-11 px-1 rounded-md bg-card border border-border hover:bg-muted text-foreground text-xs text-center font-medium transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'store_credit' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Transfer
            </button>
        </div>
    @endunless

    {{-- Error messages & Shift Alert --}}
    @error('cart')
        <div class="p-2 bg-destructive-muted text-destructive rounded-lg text-xs font-bold flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
        </div>
    @enderror

    @if(!$this->canCheckout && count($this->cartLines) > 0)
        <div class="p-2 bg-destructive-muted text-destructive rounded-lg text-xs font-bold flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[16px]">lock</span>
            <span>{{ !$this->activeShift ? 'Shift not open. Please open a cashier shift first.' : 'Shift handover in progress.' }}</span>
        </div>
    @endif

    {{-- Primary Action Button: Dispatch to Kitchen for Waiter, Checkout for Cashier --}}
    <div class="pt-1 flex flex-col gap-1.5">
        @if ($isWaiterRole)
            <button wire:click.prevent="parkCurrentCart" id="btn-dispatch-kitchen"
                wire:loading.attr="disabled" wire:target="parkCurrentCart"
                class="w-full h-11 px-4 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground transition-all flex items-center justify-between shadow-sm disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                @disabled(count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">restaurant</span>
                    <span class="text-sm font-medium">Kirim ke Dapur (KDS)</span>
                </div>
                <span class="text-sm font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @elseif ($this->isOfd())
            <button wire:click.prevent="submitOrder" id="btn-proses-ofd"
                wire:loading.attr="disabled" wire:target="submitOrder"
                wire:loading.class.add="pointer-events-none"
                aria-label="Selesaikan Pesanan OFD"
                class="w-full min-h-[44px] h-11 px-4 rounded-lg bg-foreground hover:opacity-90 text-background transition-all flex items-center justify-between shadow-sm disabled:bg-muted disabled:text-muted-foreground disabled:border disabled:border-border disabled:opacity-75 disabled:cursor-not-allowed cursor-pointer"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || empty($externalShortCode))>
                <div class="flex items-center gap-2">
                    <span wire:loading wire:target="submitOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"></span>
                    <span wire:loading.remove wire:target="submitOrder" class="material-symbols-rounded text-lg">moped</span>
                    <span wire:loading.remove wire:target="submitOrder" class="text-sm font-semibold">Selesaikan Pesanan {{ strtoupper($orderType) }}</span>
                    <span wire:loading wire:target="submitOrder" class="text-sm font-medium">Memproses OFD...</span>
                </div>
                <span class="text-sm font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @elseif ($pendingOrderId)
            <button wire:click.prevent="submitOrder" id="btn-bayar"
                wire:loading.attr="disabled" wire:target="submitOrder"
                wire:loading.class.add="pointer-events-none"
                aria-label="Pay pending QR order (F4)"
                class="w-full h-11 px-4 rounded-lg bg-foreground hover:opacity-90 text-background transition-all flex items-center justify-between shadow-sm disabled:bg-muted disabled:text-muted-foreground disabled:border disabled:border-border disabled:opacity-75 disabled:cursor-not-allowed"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                <div class="flex items-center gap-2">
                    <span wire:loading wire:target="submitOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"></span>
                    <span wire:loading.remove wire:target="submitOrder" class="text-sm font-medium">Pay Now</span>
                    <span wire:loading wire:target="submitOrder" class="text-sm font-medium">Processing...</span>
                    <span class="px-1.5 py-0.5 rounded bg-background/20 text-xs tabular-nums uppercase">F4</span>
                </div>
                <span class="text-sm font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @else
            <button type="button" id="btn-proses-bayar"
                wire:loading.attr="disabled" wire:target="submitOrder"
                aria-haspopup="dialog" aria-controls="checkout-payment"
                class="w-full h-11 px-4 rounded-lg bg-foreground hover:opacity-90 text-background transition-all flex items-center justify-between shadow-sm disabled:bg-muted disabled:text-muted-foreground disabled:border disabled:border-border disabled:opacity-75 disabled:cursor-not-allowed"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }} }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">Pay Now</span>
                    <span class="px-1.5 py-0.5 rounded bg-background/20 text-xs tabular-nums uppercase">F4</span>
                </div>
                <span class="text-sm font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @endif

        @unless ($isWaiterRole)
            <div class="flex items-center justify-between px-1 text-muted-foreground text-xs pt-0.5">
                <button type="button"
                    wire:click.prevent="openSplitBillModal"
                    aria-haspopup="dialog"
                    class="flex items-center gap-1 hover:text-foreground text-primary font-semibold transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-[14px]">call_split</span>
                    <span>Split Bill (Pisah Tagihan)</span>
                </button>
                <a href="{{ route('shifts.index') }}" class="flex items-center gap-1 hover:text-foreground transition-colors">
                    <span class="material-symbols-rounded text-[14px]">print</span>
                    <span>Auto Receipt (USB/LAN)</span>
                </a>
            </div>
        @endunless
    </div>
</div>