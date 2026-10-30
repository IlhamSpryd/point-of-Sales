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
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-gray-900/40 backdrop-blur-[2px]"
        wire:transition>
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="modifier-picker-title"
            class="bg-white sm:rounded-2xl rounded-t-3xl p-0 w-full max-w-md max-h-[90vh] flex flex-col shadow-2xl overflow-hidden animate-slide-up-fade">
            <!-- Header Modal -->
            <div class="px-6 py-5 flex justify-between items-start bg-white z-10 shrink-0 border-b border-[#E5E7EB]">
                <div>
                    <h3 id="modifier-picker-title" class="font-black text-lg leading-tight text-apeiron-ink tracking-tight">
                        {{ $selectingProduct->product_name }}
                    </h3>
                    <p class="text-xs font-semibold text-gray-500 mt-1">
                        Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                    </p>
                </div>
                <button wire:click="closeModifierPicker"
                    aria-label="Close option picker (Esc)"
                    class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-apeiron-ink hover:bg-gray-100 transition-colors -mr-1 -mt-1">
                    <span class="material-symbols-rounded text-[20px]">close</span>
                </button>
            </div>

            <!-- Body Modal (Modifiers) -->
            <div class="px-6 overflow-y-auto flex-1 bg-white space-y-5 py-5 custom-scrollbar">
                @foreach ($selectingProduct->modifierGroups as $group)
                    <div class="py-1">
                        <div class="flex justify-between items-baseline mb-2.5">
                            <h4 class="font-bold text-xs uppercase tracking-wider text-gray-600">{{ $group->name }}</h4>
                            <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">
                                {{ $group->is_required ? ($group->selection_type === 'single' ? 'Select 1 (Required)' : 'Required') : 'Optional' }}
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
                                    :class="localMods.includes({{ $mod->id }}) ? 'bg-apeiron-ink border-apeiron-ink text-white shadow-sm' : 'bg-white border-[#E5E7EB] text-gray-700 hover:bg-gray-50'"
                                    class="flex flex-col min-h-[48px] rounded-xl p-2.5 justify-center items-center cursor-pointer transition-colors border text-center">
                                    <span class="font-bold text-xs leading-tight text-center">
                                        {{ $mod->name }}
                                    </span>
                                    @if ($mod->extra_price > 0)
                                        <span :class="localMods.includes({{ $mod->id }}) ? 'text-gray-300' : 'text-gray-400'"
                                              class="text-[10px] font-bold mt-0.5">+Rp {{ number_format($mod->extra_price, 0, ',', '.') }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="pt-2">
                    <label class="block font-bold text-xs uppercase tracking-wider text-gray-500 mb-1.5">Special Instructions</label>
                    <textarea wire:model="pendingNotes"
                        class="w-full bg-gray-50 border border-[#E5E7EB] rounded-xl text-xs font-medium focus:border-apeiron-ink focus:bg-white focus:ring-1 focus:ring-apeiron-ink transition-colors px-3 py-2 resize-none placeholder:text-gray-400"
                        rows="2" placeholder="e.g. Less sugar, light ice..."></textarea>
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="px-6 py-4 bg-white flex items-center justify-between gap-3 shrink-0 border-t border-[#E5E7EB] shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.05)]">
                <div class="flex items-center gap-2 bg-gray-50 rounded-xl p-1 border border-[#E5E7EB]">
                    <button type="button" x-on:click="localQty = Math.max(1, localQty - 1)"
                        aria-label="Decrease quantity"
                        class="w-8 h-8 flex items-center justify-center bg-white rounded-lg shadow-sm text-apeiron-ink font-bold hover:bg-gray-50 active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[18px]">remove</span>
                    </button>
                    <span class="w-6 text-center font-black text-xs text-apeiron-ink" x-text="localQty"></span>
                    <button type="button" x-on:click="localQty++"
                        aria-label="Increase quantity"
                        class="w-8 h-8 flex items-center justify-center bg-white rounded-lg shadow-sm text-apeiron-ink font-bold hover:bg-gray-50 active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                    </button>
                </div>
                <button wire:click="confirmAddToCart"
                    class="flex-1 min-h-[44px] bg-apeiron-ink hover:bg-[#111111] text-white rounded-xl font-bold text-xs shadow-md transition-all active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink">
                    Add to Cart
                </button>
            </div>
        </div>
    </div>
@endif
