@props([
    'total' => 0,
    'value' => '0',
    'quickDenominations' => [10000, 20000, 50000, 100000],
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
@endphp

<div
    x-data="{
        amount: @if($wireModel) @entangle($attributes->wire('model')) @else @js((string) $value) @endif,
        totalRequired: @js((float) $total),
        append(val) {
            let str = String(this.amount || '').replace(/[^0-9]/g, '');
            if (str === '0' || str === '') {
                str = String(val);
            } else {
                str += String(val);
            }
            this.setAmount(str);
        },
        backspace() {
            let str = String(this.amount || '').replace(/[^0-9]/g, '');
            str = str.slice(0, -1);
            this.setAmount(str === '' ? '0' : str);
        },
        clear() {
            this.setAmount('0');
        },
        setExact() {
            this.setAmount(String(Math.round(this.totalRequired)));
        },
        setQuick(val) {
            this.setAmount(String(val));
        },
        setAmount(val) {
            this.amount = val;
            this.$dispatch('input', val);
            this.$dispatch('change', val);
        },
        formatRupiah(val) {
            const num = parseInt(String(val).replace(/[^0-9]/g, ''), 10) || 0;
            return 'Rp ' + num.toLocaleString('id-ID');
        },
        get changeAmount() {
            const paid = parseInt(String(this.amount).replace(/[^0-9]/g, ''), 10) || 0;
            return paid - this.totalRequired;
        }
    }"
    {{ $attributes->whereDoesntStartWith('wire:model')->twMerge('space-y-4') }}>

    {{-- Amount Display Screen --}}
    <div class="card-surface p-4 rounded-2xl border border-border bg-card shadow-xs text-right">
        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
            Amount Received
        </span>
        <div class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight numeric tabular-nums" x-text="formatRupiah(amount)">
            Rp 0
        </div>
        <template x-if="totalRequired > 0">
            <div class="mt-2 pt-2 border-t border-border flex items-center justify-between text-xs">
                <span class="text-muted-foreground">Change Due:</span>
                <span class="font-bold numeric tabular-nums text-sm"
                    :class="changeAmount >= 0 ? 'text-success' : 'text-destructive'"
                    x-text="changeAmount >= 0 ? formatRupiah(changeAmount) : '- ' + formatRupiah(Math.abs(changeAmount))">
                </span>
            </div>
        </template>
    </div>

    {{-- Quick Cash Denomination Pills (TailAdmin Pill Buttons) --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <button type="button" @click="setExact()"
            class="h-11 min-h-[44px] px-3 py-2 rounded-xl text-xs font-bold bg-primary text-primary-foreground hover:bg-primary/90 active:scale-95 transition-all shadow-xs cursor-pointer flex items-center justify-center gap-1">
            <span class="material-symbols-rounded text-sm">payments</span>
            <span>Exact Amount</span>
        </button>
        @foreach ($quickDenominations as $denom)
            <button type="button" @click="setQuick({{ $denom }})"
                class="h-11 min-h-[44px] px-3 py-2 rounded-xl text-xs font-bold bg-muted hover:bg-card border border-border text-foreground active:scale-95 transition-all shadow-xs cursor-pointer numeric font-sans">
                Rp {{ number_format($denom, 0, ',', '.') }}
            </button>
        @endforeach
    </div>

    {{-- Touch Numpad Grid (44x44px minimum touch targets) --}}
    <div class="grid grid-cols-3 gap-2">
        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
            <button type="button" @click="append('{{ $digit }}')"
                class="h-13 min-h-[44px] rounded-xl text-xl font-bold bg-card hover:bg-muted border border-border text-foreground active:scale-95 transition-all shadow-xs cursor-pointer select-none flex items-center justify-center numeric font-sans">
                {{ $digit }}
            </button>
        @endforeach
        <button type="button" @click="append('00')"
            class="h-13 min-h-[44px] rounded-xl text-lg font-bold bg-card hover:bg-muted border border-border text-foreground active:scale-95 transition-all shadow-xs cursor-pointer select-none flex items-center justify-center numeric font-sans">
            00
        </button>
        <button type="button" @click="append('0')"
            class="h-13 min-h-[44px] rounded-xl text-xl font-bold bg-card hover:bg-muted border border-border text-foreground active:scale-95 transition-all shadow-xs cursor-pointer select-none flex items-center justify-center numeric font-sans">
            0
        </button>
        <button type="button" @click="backspace()"
            aria-label="Delete last digit"
            class="h-13 min-h-[44px] rounded-xl bg-destructive-muted hover:bg-destructive-muted border border-destructive-border text-destructive active:scale-95 transition-all shadow-xs cursor-pointer select-none flex items-center justify-center">
            <span class="material-symbols-rounded text-2xl">backspace</span>
        </button>
    </div>
</div>
