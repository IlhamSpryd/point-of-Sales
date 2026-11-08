<!-- CASHIER CALCULATION & PAYMENT AREA -->
<div class="pt-2 border-t border-border bg-white shrink-0 z-10 flex flex-col gap-2">
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
                <div class="flex justify-between text-[11px] text-muted-foreground">
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
                <button type="button" wire:click="removeVoucher" class="text-muted-foreground hover:text-rose-600 font-bold p-1 text-sm leading-none cursor-pointer" title="Remove Voucher">&times;</button>
            </div>
        @elseif (!$pendingOrderId)
            <div x-show="!openPromo" class="flex items-center justify-between text-[11px] px-0.5">
                <button type="button" @click="openPromo = true" class="text-muted-foreground hover:text-foreground font-medium flex items-center gap-1 transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-[14px] text-muted-foreground">sell</span>
                    <span>+ Add Coupon / Discount</span>
                </button>
            </div>
            <div x-show="openPromo" x-cloak class="space-y-1.5 p-2 bg-muted/60 border border-border rounded-lg">
                <div class="flex items-center gap-1.5">
                    <input type="text" wire:model="voucherInput" wire:keydown.enter.prevent="applyVoucher"
                        placeholder="Coupon / voucher code..."
                        class="flex-1 h-7 px-2 text-xs bg-white border border-border rounded-md text-foreground placeholder-muted-foreground focus:border-foreground focus:outline-none uppercase tabular-nums transition-colors" />
                    <button type="button" wire:click="applyVoucher"
                        class="h-7 px-2.5 text-xs font-medium text-white bg-primary hover:bg-primary/90 rounded-md transition-colors shadow-2xs cursor-pointer">
                        Apply
                    </button>
                    <button type="button" @click="openPromo = false" class="text-muted-foreground hover:text-foreground p-1 text-xs cursor-pointer leading-none" title="Close">
                        &times;
                    </button>
                </div>
                @if ($voucherError)
                    <span class="text-[11px] text-rose-600 block">{{ $voucherError }}</span>
                @endif
                @if (count($this->activeDiscounts) > 0)
                    <div class="relative">
                        <select wire:model.live="selectedDiscountId"
                            @disabled((bool) $pendingOrderId)
                            class="w-full h-7 pl-2 pr-6 bg-white border border-border rounded-md text-[11px] font-medium text-foreground focus:border-foreground appearance-none transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
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
                <span class="text-[10px] text-muted-foreground block truncate">{{ $appliedManualDiscountReason }}</span>
            </div>
            <button type="button" wire:click="removeManualDiscount" class="text-muted-foreground hover:text-rose-600 font-bold p-1 text-sm leading-none cursor-pointer" title="Remove Manual Discount">&times;</button>
        </div>
    @endif

    {{-- Subtotal & Discount Breakdown --}}
    <div class="pt-2 pb-0.5 space-y-1 font-label-md text-[12px] border-t border-border">
        <div class="flex justify-between text-muted-foreground">
            <span>Subtotal</span>
            <span class="text-foreground font-medium tabular-nums">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
        </div>

        {{-- Member Discount / Override Discount --}}
        <div class="flex justify-between items-center text-muted-foreground cursor-pointer hover:bg-muted p-0.5 rounded-md transition-colors"
            wire:click="$set('isManualDiscountModalOpen', true)" title="Configure discount or supervisor authorization">
            <div class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[13px] text-muted-foreground">sell</span>
                <span>Discount / Promo</span>
                @if ($this->discountAmount > 0)
                    <span class="px-1.5 py-0.2 rounded-[4px] bg-muted border border-border text-muted-foreground font-label-sm text-[10px] tabular-nums">
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
                <span class="font-headline-md text-[14px] font-semibold text-foreground tracking-tight">Total Due</span>
                <span class="font-label-sm text-[10px] text-muted-foreground">All taxes included</span>
            </div>
            <span class="font-numeric-metric text-foreground font-semibold tracking-tight tabular-nums text-[16px]">
                Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
            </span>
        </div>
    </div>

    {{-- Fast Cash Tender Tray --}}
    @unless ($pendingOrderId)
        @if ($this->canCheckout && count($this->cartLines) > 0)
            <div class="p-2 rounded-xl bg-muted/60 border border-border space-y-1.5 my-0.5">
                <div class="flex items-center justify-between text-[11px] text-foreground font-semibold">
                    <span>Fast Cash Tender</span>
                    <span class="text-[10px] font-sans text-muted-foreground tabular-nums">F5 Exact</span>
                </div>
                <div class="grid grid-cols-3 gap-1.5">
                    <button type="button" wire:click="fastCashPay(0)" wire:loading.attr="disabled"
                        class="h-8 px-2 rounded-lg bg-primary hover:bg-primary/90 text-white font-label-sm text-[11px] font-semibold flex items-center justify-center transition-all disabled:opacity-50 cursor-pointer">
                        Exact Cash
                    </button>
                    <button type="button" wire:click="fastCashPay(50000)" wire:loading.attr="disabled"
                        @disabled($this->totalAmount > 50000)
                        class="h-8 px-1.5 rounded-lg bg-white border border-border hover:border-foreground text-foreground font-label-sm text-[11px] font-medium flex items-center justify-center tabular-nums transition-all disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                        Rp 50,000
                    </button>
                    <button type="button" wire:click="fastCashPay(100000)" wire:loading.attr="disabled"
                        @disabled($this->totalAmount > 100000)
                        class="h-8 px-1.5 rounded-lg bg-white border border-border hover:border-foreground text-foreground font-label-sm text-[11px] font-medium flex items-center justify-center tabular-nums transition-all disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                        Rp 100,000
                    </button>
                </div>
            </div>
        @endif
    @endunless

    {{-- Fast Payment Method Bar (Tunai, QRIS, EDC Kartu, Transfer) --}}
    @unless ($pendingOrderId)
        <div class="grid grid-cols-4 gap-1.5 py-0.5">
            <button class="h-8 px-1 rounded-md bg-primary text-white font-label-sm text-[11px] text-center font-medium shadow-2xs transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'cash' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Cash
            </button>
            <button class="h-8 px-1 rounded-md bg-white border border-border hover:bg-muted text-foreground font-label-sm text-[11px] text-center font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'qris' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                QRIS
            </button>
            <button class="h-8 px-1 rounded-md bg-white border border-border hover:bg-muted text-foreground font-label-sm text-[11px] text-center font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'card' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Card
            </button>
            <button class="h-8 px-1 rounded-md bg-white border border-border hover:bg-muted text-foreground font-label-sm text-[11px] text-center font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:pointer-events-none cursor-pointer"
                type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'store_credit' }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                Transfer
            </button>
        </div>
    @endunless

    {{-- Error messages & Shift Alert --}}
    @error('cart')
        <div class="p-2 bg-rose-50 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
        </div>
    @enderror

    @if(!$this->canCheckout && count($this->cartLines) > 0)
        <div class="p-2 bg-rose-50 text-rose-700 rounded-lg text-xs font-bold flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[16px]">lock</span>
            <span>{{ !$this->activeShift ? 'Shift not open. Please open a cashier shift first.' : 'Shift handover in progress.' }}</span>
        </div>
    @endif

    {{-- Primary Checkout Button --}}
    <div class="pt-1 flex flex-col gap-1.5">
        @if ($pendingOrderId)
            <button wire:click="submitOrder" id="btn-bayar"
                wire:loading.attr="disabled" wire:target="submitOrder"
                class="w-full h-11 px-4 rounded-lg bg-primary hover:bg-primary/90 text-white transition-all flex items-center justify-between shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                <div class="flex items-center gap-2">
                    <span wire:loading wire:target="submitOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span wire:loading.remove wire:target="submitOrder" class="font-headline-sm text-[14px] font-medium">Pay Now</span>
                    <span wire:loading wire:target="submitOrder" class="font-headline-sm text-[14px] font-medium">Processing...</span>
                    <span class="px-1.5 py-0.5 rounded bg-white/20 font-label-sm text-[10px] tabular-nums uppercase">F4</span>
                </div>
                <span class="font-numeric-currency text-[14px] font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @else
            <button type="button" id="btn-proses-bayar"
                wire:loading.attr="disabled" wire:target="submitOrder"
                class="w-full h-11 px-4 rounded-lg bg-primary hover:bg-primary/90 text-white transition-all flex items-center justify-between shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }} }); $dispatch('open-modal', 'checkout-payment')"
                @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
                <div class="flex items-center gap-2">
                    <span class="font-headline-sm text-[14px] font-medium">Pay Now</span>
                    <span class="px-1.5 py-0.5 rounded bg-white/20 font-label-sm text-[10px] tabular-nums uppercase">F4</span>
                </div>
                <span class="font-numeric-currency text-[14px] font-semibold tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </button>
        @endif

        <div class="flex items-center justify-between px-1 text-muted-foreground font-label-sm text-[11px] pt-0.5">
            <button type="button"
                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }}, method: 'cash' }); $dispatch('open-modal', 'checkout-payment')"
                class="flex items-center gap-1 hover:text-foreground transition-colors">
                <span class="material-symbols-rounded text-[13px]">call_split</span>
                <span>Split Payment Supported</span>
            </button>
            <a href="{{ route('shifts.index') }}" class="flex items-center gap-1 hover:text-foreground transition-colors">
                <span class="material-symbols-rounded text-[13px]">print</span>
                <span>Auto Receipt (USB/LAN)</span>
            </a>
        </div>
    </div>
</div>
