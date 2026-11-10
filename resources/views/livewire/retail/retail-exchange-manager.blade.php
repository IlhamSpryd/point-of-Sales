<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="catalog" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Retail Item Exchange
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Exchange retail items for alternate sizes or colors with automatic stock movement and balance adjustment.
            </p>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3 text-red-800 text-sm font-medium">
            <span class="material-symbols-rounded text-red-600 text-[20px]">error</span>
            {{ session('error') }}
        </div>
    @endif

    <!-- Search Order Bar -->
    <div class="bg-card p-6 rounded-2xl border border-border shadow-sm space-y-4">
        <h3 class="text-base font-semibold text-foreground">Find Customer Order</h3>
        <div class="flex flex-col sm:flex-row gap-3 items-center">
            <input type="text" wire:model="orderCode" placeholder="Enter Order Code (e.g. ORD-20261109-0001)"
                class="flex-1 bg-background border border-border rounded-xl px-4 py-2 text-sm text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
            <button wire:click="searchOrder"
                class="px-5 py-2 bg-primary text-primary-foreground text-sm font-semibold rounded-xl hover:bg-primary/90 transition-colors flex items-center gap-2">
                <span class="material-symbols-rounded text-[20px]">search</span>
                Find Order
            </button>
        </div>
    </div>

    @if ($order)
        <div class="bg-card rounded-2xl border border-border shadow-sm p-6 space-y-6">
            <div class="flex justify-between items-center border-b border-border pb-4">
                <div>
                    <h3 class="text-lg font-bold text-foreground">Order #{{ $order->order_code }}</h3>
                    <p class="text-xs text-muted-foreground">Date: {{ $order->created_at->format('d M Y H:i') }} | Status: {{ $order->order_status }}</p>
                </div>
            </div>

            <div class="space-y-4">
                <h4 class="text-sm font-semibold text-foreground">Select Item to Exchange:</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach ($order->orderItems as $item)
                        <div wire:click="$set('selectedOrderItemId', {{ $item->id }})"
                            class="p-4 rounded-xl border cursor-pointer transition-all {{ $selectedOrderItemId === $item->id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-background hover:border-primary/50' }}">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-semibold text-foreground text-sm">{{ $item->product?->name }}</p>
                                    <p class="text-xs text-muted-foreground">Qty Purchased: {{ $item->quantity }} | Unit Price: Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</p>
                                </div>
                                @if ($selectedOrderItemId === $item->id)
                                    <span class="material-symbols-rounded text-primary text-[20px]">check_circle</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($selectedOrderItemId)
                <div class="p-4 bg-muted/30 rounded-xl border border-border space-y-4">
                    <h4 class="text-sm font-semibold text-foreground">Select Replacement Variant:</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">New Variant (Size/Color)</label>
                            <select wire:model="newVariantId" class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary">
                                <option value="">-- Choose Alternate Variant --</option>
                                @foreach ($availableVariants as $v)
                                    <option value="{{ $v->id }}">{{ $v->variant_name }} (SKU: {{ $v->sku }}) - Rp {{ number_format((float) $v->price_override ?? 0, 0, ',', '.') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">Quantity to Exchange</label>
                            <input type="number" wire:model="exchangeQuantity" min="1" max="50"
                                class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary">
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button wire:click="processExchange"
                            class="px-5 py-2.5 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90 flex items-center gap-2">
                            <span class="material-symbols-rounded text-[20px]">swap_horiz</span>
                            Complete Item Exchange
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
