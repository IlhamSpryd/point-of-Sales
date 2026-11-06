{{-- MODAL PARKED ORDERS LIST --}}
<x-ui.modal wire:model="isParkedListOpen" maxWidth="lg">
    <x-slot:header>
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-foreground text-[20px]">pause_circle</span>
            <h3 class="font-headline-sm text-[16px] font-semibold text-foreground">Parked Orders (Held Orders)</h3>
            <span class="px-1.5 py-0.5 rounded-[5px] bg-primary text-white font-label-sm text-[11px] font-mono">
                {{ $this->parkedCarts->count() }}
            </span>
        </div>
    </x-slot:header>

    <div class="space-y-3">
        <p class="text-[12px] text-muted-foreground">
            Active parked orders held at this branch register.
        </p>

        <div class="space-y-2.5 max-h-[60vh] overflow-y-auto custom-scrollbar pr-1" id="held-orders-list">
            @forelse($this->parkedCarts as $pc)
                @php
                    $pPayload = $pc->cart_payload;
                    $pItems = $pPayload['items'] ?? $pPayload;
                    $pCount = is_array($pItems) ? array_sum(array_column($pItems, 'qty')) : 0;
                    $parkedTime = $pc->parked_at
                        ? $pc->parked_at->format('H:i')
                        : ($pc->created_at ? $pc->created_at->format('H:i') : '-');
                @endphp
                <div class="p-3 rounded-xl border border-border hover:border-foreground bg-muted flex items-center justify-between transition-all">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-semibold text-[13px] text-foreground">{{ $pc->hold_reference }}</span>
                            <span class="px-1.5 py-0.2 rounded bg-white text-[10px] border border-border font-medium uppercase text-foreground">
                                {{ $pc->order_type }}
                            </span>
                        </div>
                        <span class="text-[11px] text-muted-foreground block mt-0.5">
                            {{ $pCount }} {{ Str::plural('Item', $pCount) }} &bull; Parked at {{ $parkedTime }}
                        </span>
                        @if($pc->notes)
                            <p class="text-[11px] text-muted-foreground italic mt-0.5">{{ $pc->notes }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" wire:click="resumeCart({{ $pc->id }})"
                            class="px-3 py-1.5 rounded-lg bg-white border border-border hover:bg-primary hover:text-white font-medium text-[11px] transition-colors shadow-xs">
                            Resume Order
                        </button>
                        <button type="button" wire:click="discardHeldCart({{ $pc->id }})"
                            class="p-1.5 rounded-lg text-muted-foreground hover:text-rose-600 hover:bg-rose-50 transition-colors" title="Discard Order">
                            <span class="material-symbols-rounded text-[16px] block">close</span>
                        </button>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-muted-foreground space-y-1">
                    <span class="material-symbols-rounded text-[36px] text-gray-300 block mb-1">inbox</span>
                    <p class="text-xs font-semibold text-foreground">No parked orders found.</p>
                    <p class="text-[11px] text-muted-foreground">Active orders can be held using the Park Order (F8) action.</p>
                </div>
            @endforelse
        </div>
    </div>

    <x-slot:footer>
        <div class="flex justify-between items-center w-full">
            <button type="button" wire:click="$set('isParkModalOpen', true)"
                class="px-3.5 py-2 rounded-lg bg-primary text-white text-[12px] font-medium hover:bg-primary/90 flex items-center gap-1.5 shadow-xs transition-all">
                <span class="material-symbols-rounded text-[15px]">archive</span>
                <span>Park Current Order</span>
            </button>
            <button type="button" wire:click="$set('isParkedListOpen', false)"
                class="px-4 py-2 rounded-lg border border-border text-[12px] font-medium text-foreground hover:bg-muted transition-colors">
                Close
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
