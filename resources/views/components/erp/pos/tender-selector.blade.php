@props([
    'value' => 'cash',
    'methods' => [
        ['id' => 'cash', 'label' => 'Tunai', 'icon' => 'payments', 'color' => 'emerald'],
        ['id' => 'qris', 'label' => 'QRIS', 'icon' => 'qr_code_scanner', 'color' => 'blue'],
        ['id' => 'debit', 'label' => 'Debit Card', 'icon' => 'credit_card', 'color' => 'amber'],
        ['id' => 'transfer', 'label' => 'Bank Transfer', 'icon' => 'account_balance', 'color' => 'purple'],
        ['id' => 'voucher', 'label' => 'Voucher', 'icon' => 'confirmation_number', 'color' => 'rose'],
    ],
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
@endphp

<div
    x-data="{
        selectedMethod: @if($wireModel) @entangle($attributes->wire('model')) @else @js($value) @endif,
        setMethod(id) {
            this.selectedMethod = id;
            this.$dispatch('input', id);
            this.$dispatch('change', id);
        }
    }"
    class="grid grid-cols-2 sm:grid-cols-3 gap-2.5"
    {{ $attributes->whereDoesntStartWith('wire:model') }}>
    @foreach ($methods as $method)
        <button
            type="button"
            @click="setMethod('{{ $method['id'] }}')"
            :class="selectedMethod === '{{ $method['id'] }}'
                ? 'bg-primary text-primary-foreground border-primary shadow-sm font-semibold'
                : 'bg-card text-foreground border-border hover:bg-muted'"
            class="h-14 min-h-[44px] p-3 rounded-xl border flex items-center gap-3 transition-all cursor-pointer text-left select-none group">
            
            <div
                class="size-9 rounded-lg flex items-center justify-center shrink-0 transition-colors"
                :class="selectedMethod === '{{ $method['id'] }}'
                    ? 'bg-primary-foreground text-primary'
                    : 'bg-muted text-foreground border border-border'">
                <span class="material-symbols-rounded text-xl">{{ $method['icon'] }}</span>
            </div>

            <div class="min-w-0 flex-1">
                <span class="block text-xs leading-tight truncate font-semibold">
                    {{ $method['label'] }}
                </span>
            </div>
        </button>
    @endforeach
</div>
