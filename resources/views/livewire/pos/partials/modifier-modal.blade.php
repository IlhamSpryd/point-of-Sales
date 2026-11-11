{{-- ADAPTIVE UNIFIED PRODUCT CONFIGURATOR MODAL (F&B MODIFIER, RETAIL VARIANT & MULTI-UOM) --}}
@if ($selectingProductId && isset($selectingProduct))
    @php
        $activeVariantsList = $selectingProduct->variants->where('is_active', true)->values();
        $hasActiveVariants = $activeVariantsList->isNotEmpty();
        $hasUnitConversions = $selectingProduct->unitConversions->isNotEmpty();
        $hasModifierGroups = $selectingProduct->modifierGroups->isNotEmpty();

        $categoryNameLower = strtolower($selectingProduct->category?->category_name ?? '');
        $isFnb = !empty($selectingProduct->kitchen_station)
            || ($selectingProduct->relationLoaded('ingredients') && $selectingProduct->ingredients->isNotEmpty())
            || str_contains($categoryNameLower, 'beverage')
            || str_contains($categoryNameLower, 'food')
            || str_contains($categoryNameLower, 'minuman')
            || str_contains($categoryNameLower, 'makanan')
            || str_contains($categoryNameLower, 'kopi')
            || str_contains($categoryNameLower, 'coffee')
            || str_contains($categoryNameLower, 'cafe')
            || str_contains($categoryNameLower, 'resto')
            || str_contains($categoryNameLower, 'snack')
            || str_contains($categoryNameLower, 'dessert')
            || str_contains($categoryNameLower, 'bakery');

        $quickTagsList = ['Less Ice', 'No MSG', 'Pisah Saus', 'Bungkus / Takeaway'];

        // UOM List: Base UOM first, then conversions
        $uomOptions = collect([
            [
                'id' => $selectingProduct->base_uom_id ?? 0,
                'name' => $selectingProduct->baseUom?->name ?? 'Pcs (Dasar)',
                'code' => $selectingProduct->baseUom?->code ?? 'PCS',
                'factor' => 1.0,
            ]
        ]);
        if ($hasUnitConversions) {
            foreach ($selectingProduct->unitConversions as $conv) {
                $uomOptions->push([
                    'id' => $conv->from_uom_id,
                    'name' => ($conv->fromUom?->name ?? $conv->fromUom?->code ?? 'Unit') . ' (' . (float)$conv->conversion_factor . ' ' . ($selectingProduct->baseUom?->code ?? 'pcs') . ')',
                    'code' => $conv->fromUom?->code ?? 'UNIT',
                    'factor' => (float)$conv->conversion_factor,
                ]);
            }
        }
    @endphp

    <x-ui.modal
        :open="true"
        id="product-configurator-picker"
        maxWidth="lg"
        onClose="$wire.closeModifierPicker()"
        x-data="{
            localMods: @entangle('pendingModifierIds').live,
            localQty: @entangle('pendingQty').live,
            selectedVariantId: @entangle('pendingVariantId').live,
            selectedUomId: @entangle('pendingUomId').live,
            localNotes: @entangle('pendingNotes').live,
            baseProductPrice: {{ (float) $selectingProduct->product_price }},
            variants: {{ Js::from($activeVariantsList->map(fn($v) => [
                'id' => $v->id,
                'name' => $v->variant_name,
                'price' => (float) ($v->price_override ?? $selectingProduct->product_price),
                'stock' => $variantStocks[$v->id] ?? 0,
                'disabled' => ($selectingProduct->track_inventory && ($variantStocks[$v->id] ?? 0) <= 0),
            ])) }},
            uoms: {{ Js::from($uomOptions) }},
            modifierPrices: {{ Js::from($selectingProduct->modifierGroups->flatMap(fn($g) => $g->modifiers)->pluck('extra_price', 'id')) }},
            requiredGroupMap: {{ Js::from($selectingProduct->modifierGroups->where('is_required', true)->mapWithKeys(fn($g) => [
                $g->id => [
                    'modIds' => $g->modifiers->pluck('id')->values()->all(),
                    'minSelect' => max(1, (int) ($g->min_select ?? 1)),
                ]
            ])) }},
            hasVariants: {{ $hasActiveVariants ? 'true' : 'false' }},

            isModSelected(modId) {
                return Array.isArray(this.localMods) && this.localMods.some(id => Number(id) === Number(modId));
            },

            toggleMod(modId, type, allGroupModIds, maxSelect) {
                if (!Array.isArray(this.localMods)) {
                    this.localMods = [];
                }
                const numModId = Number(modId);
                const numGroupModIds = allGroupModIds.map(id => Number(id));

                if (type === 'single') {
                    this.localMods = this.localMods.filter(id => !numGroupModIds.includes(Number(id)));
                    this.localMods.push(numModId);
                } else {
                    const idx = this.localMods.findIndex(id => Number(id) === numModId);
                    if (idx !== -1) {
                        this.localMods.splice(idx, 1);
                    } else {
                        if (maxSelect !== null && maxSelect > 0) {
                            const currentInGroup = this.localMods.filter(id => numGroupModIds.includes(Number(id))).length;
                            if (currentInGroup >= maxSelect) {
                                return;
                            }
                        }
                        this.localMods.push(numModId);
                    }
                }
                $wire.set('pendingModifierIds', this.localMods);
            },

            selectVariant(id, disabled) {
                if (disabled) return;
                this.selectedVariantId = Number(id);
                $wire.setPendingVariant(Number(id));
            },

            selectUom(id) {
                this.selectedUomId = id ? Number(id) : null;
                $wire.setPendingUom(id ? Number(id) : null);
            },

            changeQty(delta) {
                const cur = parseInt(this.localQty) || 1;
                const next = Math.max(1, cur + delta);
                this.localQty = next;
                $wire.setPendingQty(next);
            },

            addQuickTag(tag) {
                let note = this.localNotes ? String(this.localNotes).trim() : '';
                if (note === '') {
                    this.localNotes = tag;
                } else if (!note.includes(tag)) {
                    this.localNotes = note + ', ' + tag;
                } else {
                    this.localNotes = note.replace(new RegExp('(,\\s*)?' + tag), '').trim();
                    if (this.localNotes.startsWith(',')) {
                        this.localNotes = this.localNotes.substring(1).trim();
                    }
                }
                $wire.set('pendingNotes', this.localNotes);
            },

            get currentBasePrice() {
                if (this.hasVariants && this.selectedVariantId) {
                    const v = this.variants.find(item => Number(item.id) === Number(this.selectedVariantId));
                    if (v && v.price !== null) {
                        return Number(v.price);
                    }
                }
                return this.baseProductPrice;
            },

            get currentUnitPrice() {
                let base = this.currentBasePrice;
                let factor = 1.0;
                if (this.selectedUomId) {
                    const u = this.uoms.find(item => Number(item.id) === Number(this.selectedUomId));
                    if (u && u.factor) {
                        factor = Number(u.factor);
                    }
                }
                let effectiveBase = base * factor;
                let extraTotal = 0;
                if (Array.isArray(this.localMods)) {
                    for (const id of this.localMods) {
                        if (this.modifierPrices[id]) {
                            extraTotal += Number(this.modifierPrices[id]);
                        }
                    }
                }
                return effectiveBase + extraTotal;
            },

            get liveTotalPrice() {
                const qty = Math.max(1, parseInt(this.localQty) || 1);
                return this.currentUnitPrice * qty;
            },

            get formattedTotalPrice() {
                return 'Rp ' + Math.round(this.liveTotalPrice).toLocaleString('id-ID');
            },

            canAddToCart() {
                if (this.hasVariants && !this.selectedVariantId) {
                    return false;
                }
                for (const group of Object.values(this.requiredGroupMap)) {
                    const modIds = group.modIds || [];
                    const selectedCount = modIds.filter(id => this.isModSelected(id)).length;
                    const minReq = group.minSelect || 1;
                    if (selectedCount < minReq) return false;
                }
                return true;
            }
        }"
    >
        <x-slot:header>
            <div class="flex items-center gap-3">
                @if ($selectingProduct->photo_url)
                    <img src="{{ $selectingProduct->photo_url }}"
                        alt="{{ $selectingProduct->product_name }}"
                        class="w-12 h-12 rounded-xl object-cover bg-muted border border-border shadow-xs">
                @else
                    <div class="w-12 h-12 rounded-xl bg-muted border border-border flex items-center justify-center text-muted-foreground shadow-xs">
                        <span class="material-symbols-rounded text-[24px]">
                            {{ $isFnb ? 'restaurant' : 'inventory_2' }}
                        </span>
                    </div>
                @endif
                <div>
                    <h3 id="modifier-picker-title" class="text-base font-bold text-foreground leading-snug">
                        {{ $selectingProduct->product_name }}
                    </h3>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="text-xs font-semibold text-primary font-sans tabular-nums">
                            Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                        </span>
                        @if ($selectingProduct->category)
                            <span class="text-[11px] text-muted-foreground bg-muted px-2 py-0.5 rounded-md font-medium">
                                {{ $selectingProduct->category->category_name }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </x-slot:header>

        <!-- Body Modal: Adaptive Blocks -->
        <div class="space-y-5 text-xs py-2 divide-y divide-border/60">

            {{-- 1. BLOK PEMILIH VARIAN PRODUK (product_variants) --}}
            @if ($hasActiveVariants)
                <div class="space-y-2.5 pt-1 first:pt-0">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                            <span>Pilih Varian</span>
                            <span class="text-destructive">*</span>
                        </label>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 border border-amber-500/20">
                            Wajib • Pilih 1
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach ($activeVariantsList as $variant)
                            @php
                                $vPrice = $variant->price_override ?? $selectingProduct->product_price;
                                $vStock = $variantStocks[$variant->id] ?? 0;
                                $isOutOfStock = $selectingProduct->track_inventory && $vStock <= 0;
                            @endphp
                            <button type="button"
                                @if ($isOutOfStock)
                                    disabled
                                    class="opacity-50 cursor-not-allowed bg-muted/60 border border-border text-muted-foreground p-3 rounded-xl flex flex-col justify-between text-left transition-all"
                                @else
                                    x-on:click="selectVariant({{ $variant->id }}, false)"
                                    :class="selectedVariantId === {{ $variant->id }}
                                        ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs ring-1 ring-primary/20'
                                        : 'bg-background hover:bg-muted/50 border border-border text-foreground'"
                                    class="p-3 rounded-xl flex flex-col justify-between text-left transition-all cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                @endif
                            >
                                <div class="flex items-start justify-between gap-1.5 w-full">
                                    <span class="text-xs font-medium truncate">{{ $variant->variant_name }}</span>
                                    <div :class="selectedVariantId === {{ $variant->id }} ? 'border-primary' : 'border-muted-foreground/40'"
                                        class="w-4 h-4 rounded-full border bg-background flex items-center justify-center transition-colors shrink-0 mt-0.5">
                                        <div :class="selectedVariantId === {{ $variant->id }} ? 'bg-primary' : 'bg-transparent'"
                                            class="w-2 h-2 rounded-full transition-colors"></div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-border/60 text-[11px] w-full">
                                    <span class="font-sans tabular-nums text-foreground font-semibold">
                                        Rp {{ number_format((float) $vPrice, 0, ',', '.') }}
                                    </span>
                                    @if ($isOutOfStock)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-destructive/10 text-destructive font-semibold">Habis</span>
                                    @elseif ($selectingProduct->track_inventory)
                                        <span class="text-muted-foreground text-[10px] tabular-nums">Stok: {{ (int) $vStock }}</span>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 2. BLOK PEMILIH SATUAN JUAL / MULTI-UOM (product_unit_conversions) --}}
            @if ($hasUnitConversions)
                <div class="space-y-2.5 pt-4">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                            <span>Satuan Jual</span>
                        </label>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-muted text-muted-foreground border border-border">
                            Multi-UOM
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach ($uomOptions as $uom)
                            @php
                                $uomPrice = ((float) $selectingProduct->product_price) * $uom['factor'];
                            @endphp
                            <button type="button"
                                x-on:click="selectUom({{ $uom['id'] }})"
                                :class="selectedUomId === {{ $uom['id'] }}
                                    ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs ring-1 ring-primary/20'
                                    : 'bg-background hover:bg-muted/50 border border-border text-foreground'"
                                class="p-3 rounded-xl flex flex-col justify-between text-left transition-all cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <div class="flex items-start justify-between gap-1.5 w-full">
                                    <span class="text-xs font-medium truncate">{{ $uom['name'] }}</span>
                                    <div :class="selectedUomId === {{ $uom['id'] }} ? 'border-primary' : 'border-muted-foreground/40'"
                                        class="w-4 h-4 rounded-full border bg-background flex items-center justify-center transition-colors shrink-0 mt-0.5">
                                        <div :class="selectedUomId === {{ $uom['id'] }} ? 'bg-primary' : 'bg-transparent'"
                                            class="w-2 h-2 rounded-full transition-colors"></div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-border/60 text-[11px] w-full">
                                    <span class="font-sans tabular-nums text-foreground font-semibold"
                                        x-text="'Rp ' + Math.round(currentBasePrice * {{ $uom['factor'] }}).toLocaleString('id-ID')">
                                        Rp {{ number_format($uomPrice, 0, ',', '.') }}
                                    </span>
                                    <span class="text-muted-foreground text-[10px] font-mono">x{{ $uom['factor'] }}</span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 3. BLOK MODIFIER GROUPS (modifier_groups & modifiers) --}}
            @if ($hasModifierGroups)
                <div class="space-y-4 pt-4">
                    @foreach ($selectingProduct->modifierGroups as $group)
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1">
                                    <span>{{ $group->name }}</span>
                                    @if ($group->is_required)
                                        <span class="text-destructive">*</span>
                                    @endif
                                </label>
                                <div>
                                    @if ($group->is_required)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                            @if ($group->selection_type === 'single')
                                                Wajib • Pilih 1
                                            @elseif ($group->min_select && $group->min_select > 1)
                                                Wajib • Min {{ $group->min_select }}
                                            @else
                                                Wajib
                                            @endif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-muted text-muted-foreground border border-border">
                                            Opsional{{ $group->max_select ? ' • Maks ' . $group->max_select : '' }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                @php
                                    $allModIds = $group->modifiers->pluck('id')->toJson();
                                    $maxSelectVal = $group->max_select ? (int) $group->max_select : 'null';
                                @endphp
                                @foreach ($group->modifiers as $mod)
                                    <button type="button"
                                        role="{{ $group->selection_type === 'single' ? 'radio' : 'checkbox' }}"
                                        :aria-checked="isModSelected({{ $mod->id }})"
                                        x-on:click="toggleMod({{ $mod->id }}, '{{ $group->selection_type }}', {{ $allModIds }}, {{ $maxSelectVal }})"
                                        :class="isModSelected({{ $mod->id }})
                                            ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs'
                                            : 'bg-background hover:bg-muted/50 border border-border text-foreground'"
                                        class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all duration-150 text-left cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            @if ($group->selection_type === 'single')
                                                <div :class="isModSelected({{ $mod->id }}) ? 'border-primary' : 'border-muted-foreground/40'"
                                                    class="w-4 h-4 rounded-full border bg-background flex items-center justify-center transition-colors shrink-0">
                                                    <div :class="isModSelected({{ $mod->id }}) ? 'bg-primary' : 'bg-transparent'"
                                                        class="w-2 h-2 rounded-full transition-colors"></div>
                                                </div>
                                            @else
                                                <div :class="isModSelected({{ $mod->id }}) ? 'bg-primary border-primary text-primary-foreground' : 'border-muted-foreground/40 border bg-background'"
                                                    class="w-4 h-4 rounded-md flex items-center justify-center transition-colors shrink-0">
                                                    <span x-show="isModSelected({{ $mod->id }})" class="material-symbols-rounded text-[13px] font-bold">check</span>
                                                </div>
                                            @endif
                                            <span class="text-xs truncate font-medium">{{ $mod->name }}</span>
                                        </div>

                                        @if ($mod->extra_price > 0)
                                            <span :class="isModSelected({{ $mod->id }}) ? 'text-primary font-semibold' : 'text-muted-foreground'"
                                                class="text-xs font-sans tabular-nums shrink-0 ml-2">
                                                +Rp {{ number_format($mod->extra_price, 0, ',', '.') }}
                                            </span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- 4. BLOK CATATAN & QUICK TAGS --}}
            <div class="space-y-2 pt-4">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-foreground uppercase tracking-wider">
                        {{ $isFnb ? 'Special Instructions' : 'Catatan Item / Nomor Seri (Opsional)' }}
                    </label>
                </div>

                @if ($isFnb)
                    <div class="flex flex-wrap gap-1.5 pb-1">
                        @foreach ($quickTagsList as $tag)
                            <button type="button"
                                x-on:click="addQuickTag('{{ $tag }}')"
                                :class="localNotes && localNotes.includes('{{ $tag }}')
                                    ? 'bg-primary/10 border-primary text-primary font-semibold'
                                    : 'bg-muted/60 hover:bg-muted text-muted-foreground hover:text-foreground border-border'"
                                class="px-2.5 py-1 rounded-lg border text-[11px] font-medium transition-colors cursor-pointer select-none">
                                + {{ $tag }}
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="relative">
                    <input type="text"
                        x-model="localNotes"
                        placeholder="{{ $isFnb ? 'Contoh: Sedikit es, kuah dipisah, no sugar...' : 'Contoh: SN-84920, garansi toko...' }}"
                        class="w-full h-10 px-3.5 rounded-xl border border-border bg-background text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:border-ring focus:ring-1 focus:ring-ring transition-colors shadow-2xs">
                </div>
            </div>

        </div>

        {{-- FOOTER MODAL: STEPPER KUANTITAS & LIVE PRICE ADD BUTTON --}}
        <x-slot:footer>
            <div class="flex items-center justify-between w-full gap-3">
                {{-- Quantity Stepper --}}
                <div class="flex items-center gap-1 bg-muted/40 p-1 rounded-xl border border-border">
                    <button type="button"
                        x-on:click="changeQty(-1)"
                        aria-label="Decrease quantity"
                        class="w-9 h-9 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all shadow-2xs cursor-pointer">
                        <span class="material-symbols-rounded text-[18px]">remove</span>
                    </button>
                    <span class="text-foreground font-semibold text-base tabular-nums min-w-[2.5rem] text-center"
                        x-text="localQty">{{ $pendingQty ?? 1 }}</span>
                    <button type="button"
                        x-on:click="changeQty(1)"
                        aria-label="Increase quantity"
                        class="w-9 h-9 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all shadow-2xs cursor-pointer">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                    </button>
                </div>

                {{-- Add to Order with Live Price Calculation --}}
                <div class="flex items-center gap-2">
                    <button type="button"
                        wire:click="confirmAddToCart"
                        :disabled="!canAddToCart()"
                        :class="!canAddToCart()
                            ? 'opacity-50 cursor-not-allowed bg-muted text-muted-foreground border border-border'
                            : 'bg-primary hover:bg-primary/90 text-primary-foreground shadow-sm active:scale-98 cursor-pointer'"
                        class="px-5 py-2.5 rounded-xl font-semibold text-sm flex items-center gap-2 transition-all">
                        <span class="material-symbols-rounded text-[18px]">shopping_bag</span>
                        <span>Add to Order • <span class="tabular-nums font-semibold" x-text="formattedTotalPrice">{{ $this->formattedModalTotal }}</span></span>
                    </button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>
@endif
