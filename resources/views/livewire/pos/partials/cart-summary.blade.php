<!-- AREA KALKULASI: TETAP DI BAWAH -->
<div class="p-4 border-t border-[#E5E7EB] bg-white shrink-0 z-10 shadow-[0_-4px_20px_rgba(0,0,0,0.03)] flex flex-col gap-3">
    {{-- Promo Preview Banner --}}
    @php
        $pipelineBreakdown = $this->getCalculationBreakdown();
    @endphp
    @if(!empty($pipelineBreakdown->appliedPromotionsSummary))
        <div class="flex flex-col gap-1 p-2 bg-amber-50 border border-amber-200 rounded-lg text-xs">
            <span class="font-bold text-amber-800 flex items-center gap-1">
                <span class="material-symbols-rounded text-[14px]">local_offer</span>
                Promo Aktif Diterapkan
            </span>
            @foreach($pipelineBreakdown->appliedPromotionsSummary as $promo)
                <div class="flex justify-between text-[11px] text-amber-900">
                    <span>{{ $promo['name'] ?? 'Promo Otomatis' }}</span>
                    <span class="font-semibold">- Rp {{ number_format($promo['discount_amount'] ?? 0, 0, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Voucher Code Input & Applied Voucher Badge --}}
    @if ($voucherCode)
        <div class="flex items-center justify-between p-2 bg-blue-50 border border-blue-200 rounded-lg text-xs">
            <div class="min-w-0">
                <span class="font-bold text-blue-800 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">confirmation_number</span>
                    Voucher: {{ $voucherCode }}
                </span>
            </div>
            <button type="button" wire:click="removeVoucher" class="text-blue-700 hover:text-rose-600 font-bold p-1 text-sm leading-none" title="Hapus Voucher">&times;</button>
        </div>
    @elseif (!$pendingOrderId)
        <div class="space-y-1">
            <div class="flex gap-1.5">
                <input type="text" wire:model="voucherInput" wire:keydown.enter.prevent="applyVoucher"
                    placeholder="Kode kupon / voucher..."
                    class="flex-1 h-8 px-2.5 text-xs bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-gray-400 focus:outline-none uppercase" />
                <button type="button" wire:click="applyVoucher"
                    class="h-8 px-3 text-xs font-bold text-white bg-apeiron-ink rounded-lg hover:bg-gray-800 transition-colors">
                    Pakai
                </button>
            </div>
            @if ($voucherError)
                <span class="text-[11px] text-rose-600 block">{{ $voucherError }}</span>
            @endif
        </div>
    @endif
    @if (count($this->activeDiscounts) > 0)
        <div class="relative">
            <select wire:model.live="selectedDiscountId"
                @disabled((bool) $pendingOrderId)
                class="w-full h-9 pl-3.5 pr-8 bg-gray-100/80 border border-[#E5E7EB]/50 rounded-lg outline-none text-xs font-semibold text-gray-700 hover:bg-gray-200/50 hover:text-apeiron-ink focus:bg-white focus:border-gray-300 focus:ring-0 appearance-none transition-colors duration-150 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                <option value="">+ Add Voucher Discount</option>
                @foreach ($this->activeDiscounts as $d)
                    <option value="{{ $d->id }}">{{ $d->name }}
                        ({{ $d->type === 'percentage' ? $d->value . '%' : 'Rp ' . number_format($d->value, 0, ',', '.') }})
                        {{ !$d->is_stackable ? ' [Non-stackable]' : '' }}
                    </option>
                @endforeach
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-gray-400">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
        </div>
    @endif

    {{-- Manual Discount Override Badge / Trigger --}}
    <div>
        @if($appliedManualDiscount > 0)
            <div class="flex items-center justify-between p-2.5 bg-emerald-50 border border-emerald-200 rounded-lg text-xs">
                <div class="min-w-0">
                    <span class="font-bold text-emerald-800">Manual Discount: Rp {{ number_format($appliedManualDiscount, 0, ',', '.') }}</span>
                    <span class="text-[10px] text-emerald-600 block truncate">{{ $appliedManualDiscountReason }}</span>
                </div>
                <button type="button" wire:click="removeManualDiscount" class="text-emerald-700 hover:text-rose-600 font-bold p-1 text-sm leading-none" title="Remove Manual Discount">&times;</button>
            </div>
        @elseif(!$pendingOrderId)
            <button type="button" wire:click="$set('isManualDiscountModalOpen', true)"
                class="text-xs text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1 transition-colors">
                <span class="material-symbols-rounded text-[15px]">edit_note</span> + Manual Discount Override (Supervisor)
            </button>
        @endif
    </div>

    <div class="flex flex-col gap-1.5 p-3 rounded-lg border border-[#E5E7EB]/80 bg-gray-50/80">
        <div class="flex justify-between items-center">
            <span class="text-xs text-gray-500 font-medium">Subtotal</span>
            <span class="text-xs font-semibold text-gray-700 tabular-nums">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
        </div>
        @if ($this->discountAmount > 0)
            <div class="flex justify-between items-center">
                <span class="text-xs text-gray-500 font-medium">Discount</span>
                <span class="text-xs font-semibold text-rose-600 tabular-nums">- Rp {{ number_format($this->discountAmount, 0, ',', '.') }}</span>
            </div>
        @endif
        <div class="flex justify-between items-center">
            <span class="text-xs text-gray-500 font-medium">Tax ({{ $this->taxRatePercent }}%)</span>
            <span class="text-xs font-semibold text-gray-700 tabular-nums">Rp {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
        </div>
    </div>

    <div class="flex justify-between items-baseline px-1 pt-0.5">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-600">Total Due</span>
        <span class="text-2xl font-bold text-apeiron-ink tabular-nums tracking-tight">
            Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
        </span>
    </div>

    @if ($pendingOrderId)
        <div class="relative mt-1">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-apeiron-ink text-sm font-bold">Rp</span>
            <input type="number" wire:model.live="cashReceived"
                class="bg-white border border-[#E5E7EB] text-apeiron-ink rounded-lg focus:outline-none focus:ring-1 focus:ring-gray-900 w-full pl-10 pr-4 py-2 font-bold text-base transition-colors"
                placeholder="Cash received">
        </div>

        {{-- Shortcut Tunai Pas --}}
        <div class="flex gap-2">
            <button type="button"
                x-on:click="$wire.set('cashReceived', {{ (int) $this->totalAmount }})"
                class="flex-1 h-8 text-[11px] font-bold rounded-lg bg-apeiron-ink text-white hover:bg-[#111111] transition-all active:scale-95 shadow-sm">
                Exact Cash
            </button>
            <button type="button"
                x-on:click="$wire.set('cashReceived', 50000 * Math.ceil({{ (int) $this->totalAmount }} / 50000))"
                class="flex-1 h-8 text-[11px] font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition-all active:scale-95 border border-[#E5E7EB]">
                Round to 50K
            </button>
            <button type="button"
                x-on:click="$wire.set('cashReceived', 100000 * Math.ceil({{ (int) $this->totalAmount }} / 100000))"
                class="flex-1 h-8 text-[11px] font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition-all active:scale-95 border border-[#E5E7EB]">
                Round to 100K
            </button>
        </div>

        <div class="flex justify-between items-center px-1">
            <span class="text-xs font-semibold text-gray-500">Change Due</span>
            <span class="font-bold text-sm {{ $this->changeAmount >= 0 ? 'text-emerald-600' : 'text-apeiron-ink' }}">
                Rp {{ number_format($this->changeAmount, 0, ',', '.') }}
            </span>
        </div>

        @error('cart')
            <div class="p-2 bg-rose-50 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
            </div>
        @enderror

        @if(!$this->canCheckout && count($this->cartLines) > 0)
            <div class="p-2 bg-rose-50 text-rose-700 rounded-lg text-xs font-bold flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[16px]">lock</span>
                <span>{{ !$this->activeShift ? 'Shift is not open. Open a shift in Cashier Shifts.' : 'Shift handover is currently in progress.' }}</span>
            </div>
        @endif

        <button wire:click="submitOrder" id="btn-bayar"
            wire:loading.attr="disabled" wire:target="submitOrder"
            class="w-full h-11 bg-apeiron-ink hover:bg-[#262626] text-white rounded-lg font-medium text-sm transition-colors duration-150 active:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink focus-visible:ring-offset-1"
            @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
            <span wire:loading wire:target="submitOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
            <span wire:loading.remove wire:target="submitOrder">Pay Now</span>
            <span wire:loading wire:target="submitOrder">Processing...</span>
            <x-ui.kbd class="bg-white/20 text-white border-white/20 ml-1">F4</x-ui.kbd>
        </button>
    @else
        @error('cart')
            <div class="p-2 bg-rose-50 text-rose-600 rounded-lg text-xs font-bold flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
            </div>
        @enderror

        @if(!$this->canCheckout && count($this->cartLines) > 0)
            <div class="p-2 bg-rose-50 text-rose-700 rounded-lg text-xs font-bold flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[16px]">lock</span>
                <span>{{ !$this->activeShift ? 'Shift is not open. Open a shift in Cashier Shifts.' : 'Shift handover is currently in progress.' }}</span>
            </div>
        @endif

        <button type="button" id="btn-proses-bayar"
            class="w-full h-11 bg-apeiron-ink hover:bg-[#262626] text-white rounded-lg font-medium text-sm transition-colors duration-150 active:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink focus-visible:ring-offset-1"
            @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }} }); $dispatch('open-modal', 'checkout-payment')"
            @disabled(!$this->canCheckout || count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && $this->isTablesEnabled && !$tableId))>
            <span>Proceed to Payment</span>
            <x-ui.kbd class="bg-white/20 text-white border-white/20 ml-1">F4</x-ui.kbd>
        </button>
    @endif
</div>
