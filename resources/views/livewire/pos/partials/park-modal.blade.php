{{-- MODAL PARK / HOLD CART --}}
<x-ui.modal wire:model="isParkModalOpen" maxWidth="md">
    <x-slot:header>
        <div>
            <h3 class="text-base font-semibold text-foreground">Park Order</h3>
        </div>
    </x-slot:header>

    <div class="space-y-3.5 text-xs">
        <p class="text-xs text-muted-foreground">
            Hold this customer transaction to serve the next customer without losing current cart items.
        </p>

        <form id="park-cart-form" wire:submit.prevent="parkCurrentCart" class="space-y-3.5">
            <div>
                <label class="text-xs font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Hold Reference / Customer Name
                </label>
                <input type="text" wire:model="holdReference"
                    class="w-full h-10 px-3 bg-white border border-border rounded-lg text-xs font-semibold text-foreground focus:outline-none focus:border-foreground transition-all"
                    placeholder="e.g. Table 03 / Guest Name">
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Order Remarks (Optional)
                </label>
                <textarea wire:model="holdNotes" rows="2"
                    class="w-full p-3 bg-white border border-border rounded-lg text-xs text-foreground focus:outline-none focus:border-foreground transition-all resize-none"
                    placeholder="e.g. Awaiting party arrival..."></textarea>
            </div>
        </form>
    </div>

    <x-slot:footer>
        <div class="flex items-center justify-end gap-2 w-full">
            <button type="button" wire:click="$set('isParkModalOpen', false)"
                class="px-4 py-2 rounded-lg border border-border text-xs font-medium text-foreground hover:bg-muted transition-colors">
                Cancel
            </button>
            <button type="submit" form="park-cart-form"
                class="px-5 py-2 rounded-lg bg-primary text-white text-xs font-medium hover:bg-primary/90 shadow-sm transition-all active:scale-95 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[16px]">archive</span>
                <span>Park Order</span>
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
