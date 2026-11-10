{{-- MODAL PEMILIHAN MODIFIER / VARIAN PRODUK --}}
@if ($selectingProductId && isset($selectingProduct))
    <x-ui.modal
        :open="true"
        id="modifier-picker"
        maxWidth="lg"
        onClose="$wire.closeModifierPicker()"
        x-data="{
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
            }
        }"
    >
        <x-slot:header>
            <div class="flex items-center gap-3">
                @if ($selectingProduct->photo_url)
                    <img src="{{ $selectingProduct->photo_url }}"
                        alt="{{ $selectingProduct->product_name }}"
                        class="w-12 h-12 rounded-lg object-cover bg-muted border border-border">
                @else
                    <div class="w-12 h-12 rounded-lg bg-muted border border-border flex items-center justify-center text-muted-foreground">
                        <span class="material-symbols-rounded text-[24px]">inventory_2</span>
                    </div>
                @endif
                <div>
                    <h3 id="modifier-picker-title" class="text-base font-semibold text-foreground">
                        {{ $selectingProduct->product_name }}
                    </h3>
                    <span class="text-xs text-muted-foreground font-sans tabular-nums">
                        Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                    </span>
                </div>
            </div>
        </x-slot:header>

        <!-- Body Modal (Modifiers) -->
        <div class="space-y-4 text-xs py-1">
            @foreach ($selectingProduct->modifierGroups as $group)
                <div>
                    <div class="flex justify-between items-baseline mb-2">
                        <label class="text-xs font-semibold text-foreground uppercase tracking-wider block">
                            {{ $group->name }}
                        </label>
                        <span class="text-xs font-medium text-muted-foreground">
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
                                    'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-primary bg-primary text-primary-foreground font-medium shadow-xs' :
                                    'opt-btn flex justify-between items-center px-3 py-2.5 rounded-lg border border-border hover:bg-muted text-foreground font-medium bg-background'"
                                class="transition-all text-left">
                                <span class="text-xs">{{ $mod->name }}</span>
                                @if ($mod->extra_price > 0)
                                    <span :class="localMods.includes({{ $mod->id }}) ? 'text-primary-foreground/80' : 'text-muted-foreground'"
                                        class="text-xs font-sans tabular-nums">
                                        +Rp{{ number_format($mod->extra_price, 0, ',', '.') }}
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div>
                <label class="text-xs font-semibold text-foreground uppercase tracking-wider block mb-1.5">
                    Special Instructions
                </label>
                <input type="text" wire:model="pendingNotes"
                    placeholder="e.g. Less ice, extra sauce..."
                    class="w-full h-9 px-3 rounded-lg border border-border bg-background text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-ring focus:ring-1 focus:ring-ring transition-colors">
            </div>
        </div>

        <x-slot:footer>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="localQty = Math.max(1, localQty - 1)"
                        aria-label="Decrease quantity"
                        class="w-8 h-8 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">remove</span>
                    </button>
                    <span class="font-sans tabular-nums font-semibold text-base px-2 text-foreground" x-text="localQty"></span>
                    <button type="button" x-on:click="localQty++"
                        aria-label="Increase quantity"
                        class="w-8 h-8 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[16px]">add</span>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="confirmAddToCart"
                        class="px-4 py-2.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground font-medium text-sm flex items-center gap-1.5 shadow-sm transition-all active:scale-98">
                        <span class="material-symbols-rounded text-[16px]">shopping_bag</span>
                        <span>Add to Order</span>
                    </button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>
@endif
