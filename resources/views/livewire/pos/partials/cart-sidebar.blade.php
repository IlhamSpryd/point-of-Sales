<!-- Persistent Cart & Checkout Summary (380px) -->
<aside class="w-[360px] xl:w-[380px] shrink-0 flex flex-col h-full bg-white rounded-2xl p-4 shadow-sm border border-[#E5E5E5] select-none">
    <!-- Header Keranjang: Pesanan Saat Ini, Counter, Mode, Reset -->
    <div class="flex items-center justify-between pb-3 border-b border-[#E5E5E5] mb-2 shrink-0">
        <h2 class="font-headline-sm text-[15px] font-semibold text-[#111827]">Pesanan Saat Ini</h2>
        <div class="flex items-center gap-2.5">
            <span class="text-[12px] text-[#6B7280] flex items-center gap-1.5 select-none">
                <span class="font-medium text-[#4B5563]">{{ count($this->cartLines) }} item</span>
                <span class="text-[#D1D5DB]">|</span>
                <span class="capitalize text-[#4B5563]">{{ $orderType === 'dine_in' ? ($tableId ? 'Meja ' . ($this->activeTables->firstWhere('id', $tableId)?->table_name ?? $tableId) : 'dine in') : 'takeaway' }}</span>
            </span>
            <button class="text-[#9CA3AF] hover:text-rose-600 transition-colors p-1 rounded hover:bg-gray-50 disabled:opacity-30 disabled:pointer-events-none"
                title="Reset Pesanan" type="button"
                @if (count($this->cartLines) === 0) disabled @endif
                x-on:click="if (confirm('Reset seluruh isi keranjang pesanan?')) { $wire.clearCart(); }">
                <span class="material-symbols-rounded text-[17px] block">delete_sweep</span>
            </button>
        </div>
    </div>

    <!-- Customer Selection (Optional) -->
    @unless ($pendingOrderId)
        <div class="relative mb-2 shrink-0" x-data="{ showResults: false }">
            @if ($customerId)
                <div class="flex items-center justify-between gap-2 bg-[#F9FAFB] rounded-lg px-2.5 py-1.5 border border-[#E5E5E5]">
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-[#111827] truncate">
                        <span class="material-symbols-rounded text-[16px] text-gray-400">person</span>
                        {{ $customerName }}
                    </span>
                    <button type="button" wire:click="clearCustomer" class="text-gray-400 hover:text-rose-500 transition-colors">
                        <span class="material-symbols-rounded text-[16px] block">close</span>
                    </button>
                </div>
            @else
                <div class="relative">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-[#9CA3AF] pointer-events-none">person_add</span>
                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                        x-on:focus="showResults = true" x-on:click.outside="showResults = false"
                        placeholder="Pelanggan (opsional)..."
                        class="w-full h-8 pl-9 pr-3 text-xs bg-[#F9FAFB] border border-[#E5E5E5] rounded-lg text-[#111827] placeholder-[#9CA3AF] focus:bg-white focus:border-[#171717] focus:outline-none transition-colors" />
                </div>
                @if (mb_strlen(trim($customerSearch)) >= 2)
                    <div x-show="showResults" x-cloak wire:loading.class="opacity-50"
                        class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-[#E5E5E5] shadow-xl overflow-hidden max-h-48 overflow-y-auto custom-scrollbar">
                        @forelse ($this->customerResults as $c)
                            <button type="button" wire:key="customer-result-{{ $c->id }}"
                                wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                x-on:click="showResults = false"
                                class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left hover:bg-[#F9FAFB] transition-colors border-b border-[#E5E5E5] last:border-0 text-xs">
                                <span class="font-medium text-[#111827]">{{ $c->name }}</span>
                                <span class="text-[11px] text-[#6B7280] font-mono">{{ $c->phone ?? '-' }}</span>
                            </button>
                        @empty
                            <div class="px-3 py-2 text-xs text-[#9CA3AF] text-center">Pelanggan tidak ditemukan.</div>
                        @endforelse
                    </div>
                @endif
            @endif
        </div>
    @endunless

    <!-- List Item Pesanan & Ringkasan Perhitungan -->
    <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
        @include('livewire.pos.partials.cart-items')
        @include('livewire.pos.partials.cart-summary')
    </div>
</aside>
