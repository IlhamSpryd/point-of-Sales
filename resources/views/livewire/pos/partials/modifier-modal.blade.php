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

        $quickTagsList = ['Less Ice', 'No Sugar', 'Normal Ice', 'Normal Sugar', 'Pisah Saus', 'Bungkus / Takeaway'];

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
        :teleport="false"
        id="product-configurator-picker"
        maxWidth="lg"
        :closeButton="false"
        class="p-0 overflow-hidden"
        onClose="$wire.closeModifierPicker()"
    >
        <div
            wire:key="configurator-body-{{ $selectingProductId }}"
            x-data="{
                localMods: @js(array_values(array_map('intval', $pendingModifierIds ?? []))),
                localQty: @js((int) ($pendingQty ?? 1)),
                selectedVariantId: @js($pendingVariantId !== null ? (int) $pendingVariantId : null),
                selectedUomId: @js($pendingUomId !== null ? (int) $pendingUomId : null),
                localNotes: @js($pendingNotes ?? ''),
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
                        'name' => $g->name,
                        'modIds' => $g->modifiers->pluck('id')->values()->all(),
                        'minSelect' => max(1, (int) ($g->min_select ?? 1)),
                    ]
                ])) }},
                hasVariants: {{ $hasActiveVariants ? 'true' : 'false' }},

                isModSelected(modId) {
                    return Array.isArray(this.localMods) && this.localMods.some(id => Number(id) === Number(modId));
                },

                toggleMod(modId, type, allGroupModIds, maxSelect, isRequired = false) {
                    if (!Array.isArray(this.localMods)) {
                        this.localMods = [];
                    }
                    const numModId = Number(modId);
                    const numGroupModIds = allGroupModIds.map(id => Number(id));

                    if (type === 'single') {
                        const wasSelected = this.isModSelected(numModId);
                        this.localMods = this.localMods.filter(id => !numGroupModIds.includes(Number(id)));
                        if (!wasSelected || isRequired) {
                            this.localMods.push(numModId);
                        }
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
                    this.localMods = [...this.localMods];
                },

                selectVariant(id, disabled) {
                    if (disabled) return;
                    this.selectedVariantId = Number(id);
                },

                selectUom(id) {
                    this.selectedUomId = id ? Number(id) : null;
                },

                changeQty(delta) {
                    const cur = parseInt(this.localQty) || 1;
                    this.localQty = Math.max(1, cur + delta);
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

                get validationMissingReason() {
                    if (this.hasVariants && !this.selectedVariantId) {
                        return 'Pilih Varian (Wajib)';
                    }
                    for (const group of Object.values(this.requiredGroupMap)) {
                        const modIds = group.modIds || [];
                        const selectedCount = modIds.filter(id => this.isModSelected(id)).length;
                        const minReq = group.minSelect || 1;
                        if (selectedCount < minReq) {
                            return minReq > 1
                                ? 'Pilih ' + group.name + ' (Min ' + minReq + ')'
                                : 'Pilih ' + group.name + ' (Wajib)';
                        }
                    }
                    return null;
                },

                canAddToCart() {
                    return this.validationMissingReason === null;
                },

                submitConfigurator() {
                    if (!this.canAddToCart()) return;
                    $wire.confirmAddToCart(
                        this.selectedVariantId,
                        this.selectedUomId,
                        this.localMods,
                        parseInt(this.localQty) || 1,
                        this.localNotes
                    );
                }
            }"
            class="bg-card text-card-foreground w-full flex flex-col overflow-hidden max-h-[88vh] sm:max-h-[82vh]"
        >
            {{-- 1. STICKY DOCKED HEADER --}}
            <div class="px-5 py-3.5 border-b border-border bg-card flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3 min-w-0 pr-2">
                    @if ($selectingProduct->photo_url)
                        <img src="{{ $selectingProduct->photo_url }}"
                            alt="{{ $selectingProduct->product_name }}"
                            loading="lazy"
                            decoding="async"
                            class="w-11 h-11 rounded-xl object-cover bg-muted border border-border shadow-2xs shrink-0">
                    @else
                        <div class="w-11 h-11 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shadow-2xs shrink-0">
                            <span class="material-symbols-rounded text-[22px]">
                                {{ $isFnb ? 'restaurant' : 'inventory_2' }}
                            </span>
                        </div>
                    @endif
                    <div class="min-w-0">
                        <h3 id="modifier-picker-title" class="text-base font-bold text-foreground leading-snug truncate">
                            {{ $selectingProduct->product_name }}
                        </h3>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-xs font-bold text-primary font-sans tabular-nums">
                                Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}
                            </span>
                            @if ($selectingProduct->category)
                                <span class="text-[10px] text-muted-foreground bg-muted px-2 py-0.5 rounded-md font-semibold tracking-wide uppercase">
                                    {{ $selectingProduct->category->category_name }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Close Button --}}
                <button type="button"
                    wire:click="closeModifierPicker"
                    aria-label="Tutup dialog"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer shrink-0">
                    <span class="material-symbols-rounded text-[20px]">close</span>
                </button>
            </div>

            {{-- 2. SCROLLABLE BODY CONTENT --}}
            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5 custom-scrollbar text-xs">

                {{-- Error Alert Banner --}}
                @if ($errors->has('cart'))
                    <div class="p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs font-medium flex items-center gap-2" role="alert">
                        <span class="material-symbols-rounded text-[18px] shrink-0">error</span>
                        <span>{{ $errors->first('cart') }}</span>
                    </div>
                @endif

                {{-- SECTION A: PILIH VARIAN PRODUK --}}
                @if ($hasActiveVariants)
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                                <span>Pilih Varian</span>
                                <span class="text-destructive">*</span>
                            </label>
                            <span class="inline-flex items-center px-2 py-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold bg-warning text-warning border border-warning/20">
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
                                <button type="button" wire:key="mod-variant-{{ $variant->id }}"
                                    @if ($isOutOfStock)
                                        disabled
                                        class="opacity-40 cursor-not-allowed bg-muted/40 border border-border text-muted-foreground p-3 rounded-xl flex flex-col justify-between text-left transition-all"
                                    @else
                                        x-on:click="selectVariant({{ $variant->id }}, false)"
                                        :class="selectedVariantId === {{ $variant->id }} ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs ring-1 ring-primary/20' : 'bg-card hover:bg-muted/50 border border-border text-foreground'"
                                        class="p-3 rounded-xl flex flex-col justify-between text-left transition-all cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    @endif
                                >
                                    <div class="flex items-start justify-between gap-1.5 w-full">
                                        <span class="text-xs font-medium truncate">{{ $variant->variant_name }}</span>
                                        <div :class="selectedVariantId === {{ $variant->id }} ? 'bg-primary text-primary-foreground border-primary' : 'border-muted-foreground/30 bg-background'"
                                            class="w-4 h-4 rounded-full border flex items-center justify-center transition-colors shrink-0 mt-0.5">
                                            <span x-show="selectedVariantId === {{ $variant->id }}" x-cloak class="material-symbols-rounded text-[11px] font-bold">check</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-border/60 text-[11px] w-full">
                                        <span class="font-sans tabular-nums text-foreground font-bold">
                                            Rp {{ number_format((float) $vPrice, 0, ',', '.') }}
                                        </span>
                                        @if ($isOutOfStock)
                                            <span class="px-1.5 py-0.2 rounded text-[10px] bg-destructive/10 text-destructive font-semibold">Habis</span>
                                        @elseif ($selectingProduct->track_inventory)
                                            <span class="text-muted-foreground text-[10px] tabular-nums">Stok: {{ (int) $vStock }}</span>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- SECTION B: SATUAN JUAL / MULTI-UOM --}}
                @if ($hasUnitConversions)
                    <div class="space-y-2.5 pt-2 border-t border-border/60">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                                <span>Satuan Jual</span>
                            </label>
                            <span class="inline-flex items-center px-2 py-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold bg-muted text-muted-foreground border border-border">
                                Multi-UOM
                            </span>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach ($uomOptions as $uom)
                                @php
                                    $uomPrice = ((float) $selectingProduct->product_price) * $uom['factor'];
                                @endphp
                                <button type="button"
                                    wire:key="mod-uom-{{ $uom['id'] }}"
                                    x-on:click="selectUom({{ $uom['id'] }})"
                                    :class="selectedUomId === {{ $uom['id'] }} ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs ring-1 ring-primary/20' : 'bg-card hover:bg-muted/50 border border-border text-foreground'"
                                    class="p-3 rounded-xl flex flex-col justify-between text-left transition-all cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    <div class="flex items-start justify-between gap-1.5 w-full">
                                        <span class="text-xs font-medium truncate">{{ $uom['name'] }}</span>
                                        <div :class="selectedUomId === {{ $uom['id'] }} ? 'bg-primary text-primary-foreground border-primary' : 'border-muted-foreground/30 bg-background'"
                                            class="w-4 h-4 rounded-full border flex items-center justify-center transition-colors shrink-0 mt-0.5">
                                            <span x-show="selectedUomId === {{ $uom['id'] }}" x-cloak class="material-symbols-rounded text-[11px] font-bold">check</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-border/60 text-[11px] w-full">
                                        <span class="font-sans tabular-nums text-foreground font-bold"
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

                {{-- SECTION C: MODIFIER GROUPS --}}
                @if ($hasModifierGroups)
                    <div class="space-y-4 pt-2 border-t border-border/60">
                        @foreach ($selectingProduct->modifierGroups as $group)
                            <div wire:key="group-{{ $group->id ?? $loop->index }}" class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1">
                                        <span>{{ $group->name }}</span>
                                        @if ($group->is_required)
                                            <span class="text-destructive">*</span>
                                        @endif
                                    </label>
                                    <div>
                                        @if ($group->is_required)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded px-1.5 py-0.5 text-[10px] font-bold bg-warning text-warning border border-warning/20">
                                                @if ($group->selection_type === 'single')
                                                    Wajib • Pilih 1
                                                @elseif ($group->min_select && $group->min_select > 1)
                                                    Wajib • Min {{ $group->min_select }}
                                                @else
                                                    Wajib
                                                @endif
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded px-1.5 py-0.5 text-[10px] font-medium bg-muted text-muted-foreground border border-border">
                                                Opsional{{ $group->max_select ? ' • Maks ' . $group->max_select : '' }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    @php
                                        $allModIds = $group->modifiers->pluck('id')->toJson();
                                        $maxSelectVal = $group->max_select ? (int) $group->max_select : 'null';
                                        $isRequiredVal = $group->is_required ? 'true' : 'false';
                                    @endphp
                                    @foreach ($group->modifiers as $mod)
                                        <button type="button"
                                            wire:key="mod-opt-{{ $group->id ?? $loop->parent->index }}-{{ $mod->id }}"
                                            role="{{ $group->selection_type === 'single' ? 'radio' : 'checkbox' }}"
                                            :aria-checked="isModSelected({{ $mod->id }})"
                                            x-on:click="toggleMod({{ $mod->id }}, '{{ $group->selection_type }}', {{ $allModIds }}, {{ $maxSelectVal }}, {{ $isRequiredVal }})"
                                            :class="isModSelected({{ $mod->id }}) ? 'bg-primary/5 border-2 border-primary text-foreground font-semibold shadow-xs ring-1 ring-primary/20' : 'bg-card hover:bg-muted/50 border border-border text-foreground'"
                                            class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all duration-150 text-left cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            <div class="flex items-center gap-2.5 min-w-0">
                                                @if ($group->selection_type === 'single')
                                                    <div :class="isModSelected({{ $mod->id }}) ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/30 bg-background'"
                                                        class="w-4 h-4 rounded-full border flex items-center justify-center transition-colors shrink-0">
                                                        <span x-show="isModSelected({{ $mod->id }})" x-cloak class="material-symbols-rounded text-[11px] font-bold">check</span>
                                                    </div>
                                                @else
                                                    <div :class="isModSelected({{ $mod->id }}) ? 'bg-primary border-primary text-primary-foreground' : 'border-muted-foreground/30 border bg-background'"
                                                        class="w-4 h-4 rounded-md flex items-center justify-center transition-colors shrink-0">
                                                        <span x-show="isModSelected({{ $mod->id }})" x-cloak class="material-symbols-rounded text-[12px] font-bold">check</span>
                                                    </div>
                                                @endif
                                                <span class="text-xs truncate font-medium">{{ $mod->name }}</span>
                                            </div>

                                            @if ($mod->extra_price > 0)
                                                <span :class="isModSelected({{ $mod->id }}) ? 'text-primary font-bold' : 'text-muted-foreground font-medium'"
                                                    class="text-xs font-sans tabular-nums shrink-0 ml-1.5">
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

                {{-- SECTION D: CATATAN & QUICK TAGS --}}
                <div class="space-y-2 pt-2 border-t border-border/60">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-foreground uppercase tracking-wider">
                            {{ $isFnb ? 'Instruksi Khusus / Catatan' : 'Catatan Item / Nomor Seri (Opsional)' }}
                        </label>
                    </div>

                    @if ($isFnb)
                        <div class="flex flex-wrap gap-1.5 pb-1">
                            @foreach ($quickTagsList as $tag)
                                <button type="button"
                                    x-on:click="addQuickTag('{{ $tag }}')"
                                    :class="localNotes && localNotes.includes('{{ $tag }}') ? 'bg-primary/10 border-primary text-primary font-bold' : 'bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground border-border'"
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

            {{-- 3. STICKY DOCKED FOOTER: STEPPER KUANTITAS & ADD TO ORDER --}}
            <div class="px-5 py-3.5 border-t border-border bg-card/95 backdrop-blur-xs flex items-center justify-between gap-3 shrink-0 select-none">
                {{-- Quantity Stepper --}}
                <div class="flex items-center gap-1 bg-muted/60 p-1 rounded-xl border border-border">
                    <button type="button"
                        x-on:click="changeQty(-1)"
                        aria-label="Kurangi kuantitas"
                        class="w-9 h-9 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all shadow-2xs cursor-pointer">
                        <span class="material-symbols-rounded text-[18px]">remove</span>
                    </button>
                    <span class="text-foreground font-bold text-base tabular-nums min-w-[2.5rem] text-center"
                        x-text="localQty">{{ $pendingQty ?? 1 }}</span>
                    <button type="button"
                        x-on:click="changeQty(1)"
                        aria-label="Tambah kuantitas"
                        class="w-9 h-9 rounded-lg bg-background border border-border flex items-center justify-center text-foreground font-bold hover:bg-muted active:scale-95 transition-all shadow-2xs cursor-pointer">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                    </button>
                </div>

                {{-- Action Button: Add to Order with Live Price Calculation --}}
                <div class="flex-1 max-w-sm">
                    <button type="button"
                        x-on:click="submitConfigurator()"
                        wire:loading.attr="disabled"
                        wire:target="confirmAddToCart"
                        :disabled="!canAddToCart()"
                        :class="!canAddToCart() ? 'opacity-60 cursor-not-allowed bg-muted text-muted-foreground border border-border' : 'bg-primary hover:bg-primary/90 text-primary-foreground shadow-sm active:scale-98 cursor-pointer'"
                        class="w-full h-11 px-5 rounded-xl font-bold text-xs sm:text-sm flex items-center justify-center gap-2 transition-all">
                        <span class="material-symbols-rounded text-[18px]">shopping_bag</span>
                        <template x-if="canAddToCart()">
                            <span>Tambah ke Pesanan • <span class="tabular-nums font-bold" x-text="formattedTotalPrice">{{ $this->formattedModalTotal }}</span></span>
                        </template>
                        <template x-if="!canAddToCart()">
                            <span x-text="validationMissingReason">Lengkapi Pilihan</span>
                        </template>
                    </button>
                </div>
            </div>
        </div>
    </x-ui.modal>
@endif