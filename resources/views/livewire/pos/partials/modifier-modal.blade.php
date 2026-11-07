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
            class="bg-white rounded-2xl w-full max-w-lg shadow-2xl border border-border flex flex-col overflow-hidden max-h-[90vh]">
            
            <!-- Header Modal -->
            <div class="p-4 border-b border-border flex items-center justify-between shrink-0 bg-white">
                <div class="flex items-center gap-3">
                    @if ($selectingProduct->photo_url)
                        <img src="{{ $selectingProduct->photo_url }}"
                            alt="{{ $selectingProduct->product_name }}"
                            class="w-12 h-12 rounded-lg object-cover bg-muted border border-border">
                    @else
                        <div class="w-12 h-12 rounded-lg bg-muted border border-border flex items-center justify-center text-gray-400">
                            <span class="material-symbols-rounded text-[24px]">inventory_2</span>
                        </div>
                    @endif
                    <div>
                        <h3 id="modifier-picker-title" class="font-headline-sm text-[16px] font-semibold text-foreground">
                            {{ $selectingProduct->product_name }}
                        </h3>
                        <span class="text-[13px] text-muted-foreground font-sans tabular-nums">
                            Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
                <button wire:click="closeModifierPicker"
                    aria-label="Close option picker (Esc)"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                    <span class="material-symbols-rounded text-[18px]">close</span>
                </button>
            </div>

            <!-- Body Modal (Modifiers) -->
            <div class="p-5 overflow-y-auto max-h-[60vh] space-y-4 text-[13px] custom-scrollbar">
                @foreach ($selectingProduct->modifierGroups as $group)
                    <div>
                        <div class="flex justify-between items-baseline mb-2">
                            <label class="font-headline-sm text-[12px] font-semibold text-foreground uppercase tracking-wider block">
                                {{ $group->name }}
                            </label>
                            <span class="text-[11px] font-medium text-muted-foreground">
                                {{ $group->is_required ? ($group->selection_type === 'single' ? 'Required (Select 1)' : 'Required') : 'Optional' }}
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
                                        'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-foreground bg-primary text-white font-medium shadow-xs' :
                                        'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-border hover:bg-muted text-foreground font-medium'"
                                    class="transition-all text-left">
                                    <span class="text-xs">{{ $mod->name }}</span>
                                    @if ($mod->extra_price > 0)
                                        <span :class="localMods.includes({{ $mod->id }}) ? 'text-white/80' : 'text-muted-foreground'"
                                            class="text-[11px] font-sans tabular-nums">
                                            +Rp{{ number_format($mod->extra_price, 0, ',', '.') }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div>
                    <label class="font-headline-sm text-[12px] font-semibold text-foreground uppercase tracking-wider block mb-1.5">
                        Special Instructions
                    </label>
                    <input type="text" wire:model="pendingNotes"
                        placeholder="e.g. Less ice, extra sauce..."
                        class="w-full h-9 px-3 rounded-lg border border-border text-[12px] text-foreground focus:outline-none focus:border-foreground transition-colors">
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="p-4 bg-muted border-t border-border flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="localQty = Math.max(1, localQty - 1)"
                        aria-label="Decrease quantity"
                        class="w-8 h-8 rounded-lg bg-white border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">remove</span>
                    </button>
                    <span class="font-sans tabular-nums font-semibold text-[15px] px-2 text-foreground" x-text="localQty"></span>
                    <button type="button" x-on:click="localQty++"
                        aria-label="Increase quantity"
                        class="w-8 h-8 rounded-lg bg-white border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">add</span>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="confirmAddToCart"
                        class="px-4 py-2.5 rounded-lg bg-primary hover:bg-primary/90 text-white font-medium text-[13px] flex items-center gap-1.5 shadow-sm transition-all active:scale-98">
                        <span class="material-symbols-rounded text-[16px]">shopping_bag</span>
                        <span>Add to Order</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
