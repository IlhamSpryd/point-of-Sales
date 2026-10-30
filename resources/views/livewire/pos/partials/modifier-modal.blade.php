{{-- MODAL PEMILIHAN MODIFIER / VARIAN PRODUK --}}
@if ($selectingProductId && isset($selectingProduct))
    <div x-data="{
            localMods: @entangle('pendingModifierIds'),
            localQty: @entangle('pendingQty'),
            toggleMod(modId, type, allGroupModIds) {
                if (type === 'single') {
                    this.localMods = this.localMods.filter(id => !allGroupModIds.includes(id));
                    this.localMods.push(modId);
                } else {
                    if (this.localMods.includes(modId)) {
                        this.localMods = this.localMods.filter(id => id !== modId);
                    } else {
                        this.localMods.push(modId);
                    }
                }
            },
            focusables() {
                let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])';
                return [...$el.querySelectorAll(selector)].filter(el => !el.hasAttribute('disabled'));
            },
            firstFocusable() { return this.focusables()[0]; },
            lastFocusable() { return this.focusables().slice(-1)[0]; },
            nextFocusable() { return this.focusables()[(this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1)] || this.firstFocusable(); },
            prevFocusable() { return this.focusables()[Math.max(0, this.focusables().indexOf(document.activeElement) - 1)] || this.lastFocusable(); }
        }"
        x-init="setTimeout(() => firstFocusable() && firstFocusable().focus(), 100)"
        @keydown.escape.window="$wire.closeModifierPicker()"
        x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
        x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm p-4"
        wire:transition>
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="modifier-picker-title"
            class="bg-white rounded-2xl w-full max-w-lg shadow-2xl border border-[#E5E5E5] flex flex-col overflow-hidden max-h-[90vh]">
            
            <!-- Header Modal -->
            <div class="p-4 border-b border-[#E5E5E5] flex items-center justify-between shrink-0 bg-white">
                <div class="flex items-center gap-3">
                    @if ($selectingProduct->product_photo)
                        <img src="{{ Storage::url($selectingProduct->product_photo) }}"
                            alt="{{ $selectingProduct->product_name }}"
                            class="w-12 h-12 rounded-lg object-cover bg-[#F4F4F5] border border-[#E5E5E5]">
                    @else
                        <div class="w-12 h-12 rounded-lg bg-[#F4F4F5] border border-[#E5E5E5] flex items-center justify-center text-gray-400">
                            <span class="material-symbols-rounded text-[24px]">inventory_2</span>
                        </div>
                    @endif
                    <div>
                        <h3 id="modifier-picker-title" class="font-headline-sm text-[16px] font-semibold text-[#111827]">
                            {{ $selectingProduct->product_name }}
                        </h3>
                        <span class="text-[13px] text-[#6B7280] font-mono">
                            Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
                <button wire:click="closeModifierPicker"
                    aria-label="Close option picker (Esc)"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-[#9CA3AF] hover:text-[#111827] hover:bg-[#F4F4F5] transition-colors">
                    <span class="material-symbols-rounded text-[18px]">close</span>
                </button>
            </div>

            <!-- Body Modal (Modifiers) -->
            <div class="p-5 overflow-y-auto max-h-[60vh] space-y-4 text-[13px] custom-scrollbar">
                @foreach ($selectingProduct->modifierGroups as $group)
                    <div>
                        <div class="flex justify-between items-baseline mb-2">
                            <label class="font-headline-sm text-[12px] font-semibold text-[#111827] uppercase tracking-wider block">
                                {{ $group->name }}
                            </label>
                            <span class="text-[11px] font-medium text-[#6B7280]">
                                {{ $group->is_required ? ($group->selection_type === 'single' ? 'Wajib (Pilih 1)' : 'Wajib') : 'Opsional' }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            @php
                                $allModIds = $group->modifiers->pluck('id')->toJson();
                            @endphp
                            @foreach ($group->modifiers as $mod)
                                <button type="button"
                                    role="{{ $group->selection_type === 'single' ? 'radio' : 'checkbox' }}"
                                    :aria-checked="localMods.includes({{ $mod->id }})"
                                    x-on:click="toggleMod({{ $mod->id }}, '{{ $group->selection_type }}', {{ $allModIds }})"
                                    :class="localMods.includes({{ $mod->id }}) ?
                                        'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-[#171717] bg-[#171717] text-white font-medium shadow-xs' :
                                        'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-[#E5E5E5] hover:bg-[#F9FAFB] text-[#111827] font-medium'"
                                    class="transition-all text-left">
                                    <span class="text-xs">{{ $mod->name }}</span>
                                    @if ($mod->extra_price > 0)
                                        <span :class="localMods.includes({{ $mod->id }}) ? 'text-white/80' : 'text-[#6B7280]'"
                                            class="text-[11px] font-mono">
                                            +Rp{{ number_format($mod->extra_price, 0, ',', '.') }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div>
                    <label class="font-headline-sm text-[12px] font-semibold text-[#111827] uppercase tracking-wider block mb-1.5">
                        Catatan Khusus
                    </label>
                    <input type="text" wire:model="pendingNotes"
                        placeholder="Contoh: pisahkan es, jangan terlalu manis..."
                        class="w-full h-9 px-3 rounded-lg border border-[#E5E5E5] text-[12px] text-[#111827] focus:outline-none focus:border-[#171717] transition-colors">
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="p-4 bg-[#F9FAFB] border-t border-[#E5E5E5] flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="localQty = Math.max(1, localQty - 1)"
                        aria-label="Decrease quantity"
                        class="w-8 h-8 rounded-lg bg-white border border-[#E5E5E5] flex items-center justify-center text-[#111827] font-bold hover:bg-[#F4F4F5] active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">remove</span>
                    </button>
                    <span class="font-mono font-semibold text-[15px] px-2 text-[#111827]" x-text="localQty"></span>
                    <button type="button" x-on:click="localQty++"
                        aria-label="Increase quantity"
                        class="w-8 h-8 rounded-lg bg-white border border-[#E5E5E5] flex items-center justify-center text-[#111827] font-bold hover:bg-[#F4F4F5] active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">add</span>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="confirmAddToCart"
                        class="px-4 py-2.5 rounded-lg bg-[#171717] hover:bg-[#262626] text-white font-medium text-[13px] flex items-center gap-1.5 shadow-sm transition-all active:scale-98">
                        <span class="material-symbols-rounded text-[16px]">shopping_bag</span>
                        <span>Tambah ke Pesanan</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
