<!-- Persistent Cart & Checkout Summary (380px) -->
<aside class="w-[360px] xl:w-[380px] shrink-0 flex flex-col h-full bg-white rounded-2xl p-4 shadow-sm border border-border select-none">
    <!-- Cart Header: Current Order, Counter, Mode, Reset -->
    <div class="flex items-center justify-between pb-3 border-b border-border mb-2 shrink-0">
        <h2 class="font-headline-sm text-[15px] font-semibold text-foreground">Current Order</h2>
        <div class="flex items-center gap-2.5">
            <span class="text-[12px] text-muted-foreground flex items-center gap-1.5 select-none">
                <span class="font-medium text-muted-foreground">{{ count($this->cartLines) }} {{ Str::plural('item', count($this->cartLines)) }}</span>
                <span class="text-muted-foreground">|</span>
                <span class="capitalize text-muted-foreground">{{ $orderType === 'dine_in' ? ($tableId ? 'Table ' . ($this->activeTables->firstWhere('id', $tableId)?->table_name ?? $tableId) : 'dine in') : 'takeaway' }}</span>
            </span>
            <button class="text-muted-foreground hover:text-rose-600 transition-colors p-1 rounded hover:bg-gray-50 disabled:opacity-30 disabled:pointer-events-none"
                title="Clear Cart" type="button"
                @if (count($this->cartLines) === 0) disabled @endif
                x-on:click="if (confirm('Clear all items from the current cart?')) { $wire.clearCart(); }">
                <span class="material-symbols-rounded text-[17px] block">delete_sweep</span>
            </button>
        </div>
    </div>

    <!-- Customer Selection (Optional) -->
    @unless ($pendingOrderId)
        <div class="relative mb-2 shrink-0" x-data="{ showResults: false }">
            @if ($customerId)
                <div class="flex items-center justify-between gap-2 bg-muted rounded-lg px-2.5 py-1.5 border border-border">
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-foreground truncate">
                        <span class="material-symbols-rounded text-[16px] text-gray-400">person</span>
                        {{ $customerName }}
                    </span>
                    <button type="button" wire:click="clearCustomer" class="text-gray-400 hover:text-rose-500 transition-colors">
                        <span class="material-symbols-rounded text-[16px] block">close</span>
                    </button>
                </div>
            @else
                <div class="relative">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-muted-foreground pointer-events-none">person_add</span>
                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                        x-on:focus="showResults = true" x-on:click.outside="showResults = false"
                        placeholder="Customer (optional)..."
                        class="w-full h-8 pl-9 pr-3 text-xs bg-muted border border-border rounded-lg text-foreground placeholder-muted-foreground focus:bg-white focus:border-foreground focus:outline-none transition-colors" />
                </div>
                @if (mb_strlen(trim($customerSearch)) >= 2)
                    <div x-show="showResults" x-cloak wire:loading.class="opacity-50"
                        class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-border shadow-xl overflow-hidden max-h-48 overflow-y-auto custom-scrollbar">
                        @forelse ($this->customerResults as $c)
                            <button type="button" wire:key="customer-result-{{ $c->id }}"
                                wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                x-on:click="showResults = false"
                                class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left hover:bg-muted transition-colors border-b border-border last:border-0 text-xs">
                                <span class="font-medium text-foreground">{{ $c->name }}</span>
                                <span class="text-[11px] text-muted-foreground font-mono">{{ $c->phone ?? '-' }}</span>
                            </button>
                        @empty
                            <div class="px-3 py-2 text-xs text-muted-foreground text-center">No customers found.</div>
                        @endforelse
                    </div>
                @endif
            @endif
        </div>
    @endunless

    <!-- Order Items List & Calculation Summary -->
    <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
        @include('livewire.pos.partials.cart-items')
        @include('livewire.pos.partials.cart-summary')
    </div>
</aside>
