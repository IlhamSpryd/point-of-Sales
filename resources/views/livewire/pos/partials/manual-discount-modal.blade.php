{{-- MODAL MANUAL DISCOUNT OVERRIDE (SUPERVISOR PIN) --}}
<x-ui.modal wire:model="isManualDiscountModalOpen" maxWidth="md">
    <x-slot:header>
        <div class="flex items-center gap-2 text-foreground">
            <span class="material-symbols-rounded text-[20px] text-amber-500">percent</span>
            <span class="font-headline-sm text-[16px] font-semibold">Configure Discount & Authorization</span>
        </div>
    </x-slot:header>

    <div class="space-y-4 text-xs" x-data="{
        subtotal: {{ (int) $this->subtotal }},
        supervisorPin: '',
        setPercent(pct) {
            const calculated = Math.round(this.subtotal * (pct / 100));
            $wire.set('manualDiscountAmount', calculated);
            $wire.set('manualDiscountReason', 'Special Discount ' + pct + '%');
        }
    }">
        <p class="text-[12px] text-muted-foreground">
            Manual discounts require a valid business reason and supervisor PIN authorization.
        </p>

        <!-- Quick Percent Preset Buttons -->
        <div class="space-y-1.5">
            <label class="font-headline-sm text-[11px] font-semibold text-foreground uppercase tracking-wider block">
                Quick Discount Presets
            </label>
            <div class="grid grid-cols-4 gap-1.5">
                <button type="button" x-on:click="setPercent(5)"
                    class="h-8 rounded-lg border border-border hover:border-foreground bg-muted text-foreground font-sans font-medium text-[11px] tabular-nums transition-colors">
                    5%
                </button>
                <button type="button" x-on:click="setPercent(10)"
                    class="h-8 rounded-lg border border-border hover:border-foreground bg-muted text-foreground font-sans font-medium text-[11px] tabular-nums transition-colors">
                    10% (VIP)
                </button>
                <button type="button" x-on:click="setPercent(15)"
                    class="h-8 rounded-lg border border-border hover:border-foreground bg-muted text-foreground font-sans font-medium text-[11px] tabular-nums transition-colors">
                    15% (Promo)
                </button>
                <button type="button" x-on:click="setPercent(25)"
                    class="h-8 rounded-lg border border-border hover:border-foreground bg-muted text-foreground font-sans font-medium text-[11px] tabular-nums transition-colors">
                    25% (Staff)
                </button>
            </div>
        </div>

        <form id="manual-discount-form" wire:submit.prevent="applyManualDiscount(supervisorPin)" class="space-y-3.5">
            <div>
                <label class="font-headline-sm text-[11px] font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Discount Amount (Rp)
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 font-sans text-muted-foreground font-semibold text-[13px]">Rp</span>
                    <input type="number" wire:model="manualDiscountAmount" required min="1" max="{{ $this->subtotal }}"
                        class="w-full h-10 pl-10 pr-3 bg-white border border-border rounded-lg font-sans tabular-nums font-bold text-sm text-foreground focus:outline-none focus:border-foreground transition-all"
                        placeholder="0">
                </div>
                @error('manualDiscountAmount') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="font-headline-sm text-[11px] font-semibold text-foreground uppercase tracking-wider block mb-1">
                    Reason for Discount
                </label>
                <input type="text" wire:model="manualDiscountReason" required
                    class="w-full h-10 px-3 bg-white border border-border rounded-lg text-xs text-foreground focus:outline-none focus:border-foreground transition-all"
                    placeholder="e.g. VIP guest discount, service compensation...">
                @error('manualDiscountReason') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 space-y-1.5">
                <div class="flex items-center gap-1.5 text-amber-800 text-[11px] font-semibold">
                    <span class="material-symbols-rounded text-[15px]">lock</span>
                    <span>Supervisor PIN Authorization</span>
                </div>
                <input type="password" x-model="supervisorPin" maxlength="6"
                    class="w-full h-9 px-3 bg-white border border-amber-300 rounded-lg text-center font-sans tabular-nums font-bold text-xs tracking-widest text-foreground focus:outline-none focus:border-amber-600 transition-all"
                    placeholder="••••••">
                <span class="text-[10px] text-amber-700 block">Required when discount exceeds cashier authorization limit.</span>
                @error('manualDiscountSupervisorPin') <span class="text-[11px] text-rose-500 mt-0.5 block">{{ $message }}</span> @enderror
            </div>
        </form>
    </div>

    <x-slot:footer>
        <div class="flex items-center justify-end gap-2 w-full">
            <button type="button" wire:click="$set('isManualDiscountModalOpen', false)"
                class="px-4 py-2 rounded-lg border border-border text-xs font-medium text-foreground hover:bg-muted transition-colors">
                Cancel
            </button>
            <button type="submit" form="manual-discount-form"
                class="px-5 py-2 rounded-lg bg-primary text-white text-xs font-medium hover:bg-primary/90 shadow-sm transition-all active:scale-95">
                Apply Discount
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
