{{-- MODAL MANUAL DISCOUNT OVERRIDE (SUPERVISOR PIN) --}}
<x-ui.modal wire:model="isManualDiscountModalOpen" maxWidth="md">
    <x-slot:header>
        <div class="flex items-center gap-2 text-apeiron-ink">
            <span class="material-symbols-rounded text-[22px]">verified_user</span>
            <span class="text-base font-bold">Manual Discount Override</span>
        </div>
    </x-slot:header>

    <div class="space-y-4">
        <p class="text-xs text-gray-500">
            Pemberian diskon manual di luar master voucher memerlukan alasan sah dan otorisasi PIN Supervisor/Manager.
        </p>

        <form id="manual-discount-form" wire:submit.prevent="applyManualDiscount" class="space-y-4">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">
                    Nominal Diskon (Rp)
                </label>
                <input type="number" wire:model="manualDiscountAmount" required min="1" max="{{ $this->subtotal }}"
                    class="w-full px-3.5 py-2.5 bg-gray-50 border border-[#E5E7EB] rounded-xl font-mono font-bold text-sm text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink outline-none transition-all"
                    placeholder="0">
                @error('manualDiscountAmount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">
                    Alasan Pemberian Diskon
                </label>
                <textarea wire:model="manualDiscountReason" required rows="2"
                    class="w-full px-3.5 py-2.5 bg-gray-50 border border-[#E5E7EB] rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink outline-none transition-all resize-none"
                    placeholder="Contoh: Kompensasi keterlambatan sajian / diskon VIP..."></textarea>
                @error('manualDiscountReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1.5">
                    PIN Supervisor (Wajib Otorisasi)
                </label>
                <input type="password" wire:model="manualDiscountSupervisorPin" maxlength="6" required
                    class="w-full px-3.5 py-2.5 bg-rose-50/50 border border-rose-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest text-rose-900 focus:bg-white focus:ring-1 focus:ring-rose-500 focus:border-rose-500 outline-none transition-all"
                    placeholder="••••••">
                @error('manualDiscountSupervisorPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>
        </form>
    </div>

    <x-slot:footer>
        <div class="flex items-center justify-end gap-2 w-full">
            <button type="button" wire:click="$set('isManualDiscountModalOpen', false)"
                class="px-4 py-2 rounded-xl border border-[#E5E7EB] text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                Batal
            </button>
            <button type="submit" form="manual-discount-form"
                class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm transition-all active:scale-95">
                Terapkan Diskon
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
