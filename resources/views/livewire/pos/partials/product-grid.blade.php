@php
    $allProducts = collect();
    foreach ($this->categories as $category) {
        foreach ($category->products as $product) {
            $product->cat_id = $category->id;
            $product->cat_name = $category->category_name;
            $allProducts->push($product);
        }
    }

    $searchTerm = trim(strtolower($this->search));
    if ($searchTerm !== '') {
        $allProducts = $allProducts->filter(function($p) use ($searchTerm) {
            return str_contains(strtolower($p->product_name), $searchTerm)
                || str_contains(strtolower($p->product_sku ?? ''), $searchTerm);
        });
    }
@endphp

<!-- Left Column: Product Discovery & Gallery -->
<div class="flex-1 flex flex-col min-h-0 overflow-hidden" 
    x-data="{ 
        activeCategory: 'all',
        matchesCategory(catId, isFav) {
            if (this.activeCategory === 'all') return true;
            if (this.activeCategory === 'fav') return Boolean(isFav);
            return this.activeCategory === catId;
        }
    }">

    <!-- Category Chips Bar -->
    <div class="flex items-center gap-2 overflow-x-auto pb-2.5 mb-3 shrink-0 select-none scrollbar-hide">
        <!-- All Items -->
        <button type="button" @click="activeCategory = 'all'"
            :class="activeCategory === 'all' ? 'bg-primary text-white shadow-sm' : 'bg-white hover:bg-muted text-muted-foreground hover:text-foreground border border-border'"
            class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-all cursor-pointer">
            <span class="truncate">All Items</span>
            <span :class="activeCategory === 'all' ? 'bg-white/20 text-white' : 'bg-muted text-muted-foreground'"
                class="px-1.5 py-0.5 rounded-[5px] font-label-sm text-[11px] tabular-nums transition-colors">
                {{ $this->categories->sum(fn($cat) => $cat->products->count()) }}
            </span>
        </button>

        @foreach ($this->categories as $category)
            @if ($category->products->count() > 0)
                <button type="button" @click="activeCategory = {{ $category->id }}"
                    :class="activeCategory === {{ $category->id }} ? 'bg-primary text-white shadow-sm' : 'bg-white hover:bg-muted text-muted-foreground hover:text-foreground border border-border'"
                    class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-all cursor-pointer">
                    <span class="truncate">{{ $category->category_name }}</span>
                    <span :class="activeCategory === {{ $category->id }} ? 'bg-white/20 text-white' : 'bg-muted text-muted-foreground'"
                        class="px-1.5 py-0.5 rounded-[5px] font-label-sm text-[11px] tabular-nums transition-colors">
                        {{ $category->products->count() }}
                    </span>
                </button>
            @endif
        @endforeach

        <div class="h-5 w-px bg-secondary shrink-0 mx-1"></div>

        <button type="button" @click="activeCategory = 'fav'"
            :class="activeCategory === 'fav' ? 'bg-primary text-white shadow-sm' : 'bg-white hover:bg-muted text-muted-foreground hover:text-foreground border border-border'"
            class="flex items-center gap-1.5 px-3 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-colors cursor-pointer">
            <span class="material-symbols-rounded text-[15px] text-amber-500">star</span>
            <span class="truncate">Favorites</span>
        </button>
    </div>

    <!-- Search Input & Barcode Ready Status -->
    <div class="flex items-center gap-3 mb-5 shrink-0">
        <div class="relative flex-1">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-[18px]">search</span>
            <input type="text"
                wire:model.live.debounce.300ms="search"
                id="search-input"
                placeholder="Search product name, SKU, or scan barcode (F2)..."
                class="w-full h-10 pl-9 pr-20 rounded-lg bg-white border border-border text-foreground placeholder-muted-foreground font-body-sm text-[13px] focus:outline-none focus:border-foreground transition-all">
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-1.5 pointer-events-none">
                <span class="px-1.5 py-0.5 rounded bg-muted border border-border text-muted-foreground font-label-sm text-[11px] tabular-nums uppercase tracking-wide">F2</span>
            </div>
        </div>

        <button type="button" @click="focusSearch()" title="Focus barcode scanner (F2)"
            class="flex items-center gap-2 px-3 h-10 rounded-lg bg-white border border-border hover:border-foreground hover:bg-muted text-muted-foreground shrink-0 shadow-xs transition-colors cursor-pointer">
            <span class="material-symbols-rounded text-[17px] text-foreground">qr_code_scanner</span>
            <span class="font-label-sm text-[12px] font-medium text-foreground">Barcode Ready</span>
        </button>
    </div>

    <!-- Product Cards Grid (Scrollable Container) -->
    <div id="top-products" class="flex-1 overflow-y-auto pr-1 pt-1.5 custom-scrollbar min-h-0">
        @if ($allProducts->isEmpty())
            <div class="h-64 flex flex-col items-center justify-center text-center p-8 select-none">
                <div class="w-12 h-12 rounded-xl bg-muted flex items-center justify-center text-gray-400 mb-2 border border-border">
                    <span class="material-symbols-rounded text-[28px]">search_off</span>
                </div>
                <p class="font-headline-sm text-[14px] font-semibold text-foreground">No Products Found</p>
                <p class="font-body-sm text-[12px] text-muted-foreground max-w-[280px] mt-1">
                    {{ $searchTerm !== '' ? 'No products match your search "' . $this->search . '".' : 'No products available in this catalog.' }}
                </p>
            </div>
        @else
            <!-- Unified Responsive Grid: Fills across columns (2, 3, 4) first! -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-4 gap-4 pb-2">
                @foreach ($allProducts as $product)
                    @php
                        $inCartQty = $this->cartQtyByProduct[$product->id] ?? 0;
                        $hasBom = $product->relationLoaded('ingredients') && $product->ingredients->isNotEmpty();
                        if ($hasBom) {
                            $isFulfillable = $product->is_fulfillable ?? true;
                            $isOutOfStock = ! $isFulfillable;
                            $isLowStock = $isFulfillable && isset($product->available_portions) && $product->available_portions > 0 && $product->available_portions <= 10;
                            $tracksStock = true;
                        } else {
                            $tracksStock = (bool) ($product->track_inventory ?? true)
                                && ! ($product->isService() || $product->isNonInventory() || $product->isBundle());
                            $isFulfillable = $product->is_fulfillable ?? (! $tracksStock || ($product->stock > 0));
                            $isOutOfStock = ! $isFulfillable;
                            $isLowStock = $tracksStock && !is_null($product->stock) && $product->stock > 0 && $product->stock <= 10;
                        }
                    @endphp
                    <div wire:key="product-{{ $product->id }}"
                        x-show="matchesCategory({{ $product->cat_id }}, {{ (!empty($product->is_favorite) || !empty($product->is_featured)) ? 'true' : 'false' }})"
                        @if(!$isOutOfStock)
                            x-on:click="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                            @keydown.enter="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                            @keydown.space.prevent="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                        @endif
                        role="button" tabindex="{{ $isOutOfStock ? '-1' : '0' }}"
                        @if($isOutOfStock) aria-disabled="true" @endif
                        aria-label="{{ $isOutOfStock ? ($product->product_name . ' - Out of Stock') : ('Add ' . $product->product_name . ' to cart, price Rp ' . number_format($product->product_price, 0, ',', '.')) }}"
                        class="{{ $isOutOfStock ? 'opacity-60 cursor-not-allowed bg-gray-50 border border-border' : 'group bg-white border border-border hover:border-foreground hover:shadow-sm cursor-pointer' }} relative flex flex-col justify-between p-3.5 rounded-xl transition-all duration-200 select-none outline-none focus-visible:ring-2 focus-visible:ring-ring">

                        <div class="flex flex-col gap-2.5">
                            <div class="relative w-full h-[120px] rounded-lg overflow-hidden bg-muted flex items-center justify-center">
                                @if ($isOutOfStock)
                                    <div class="absolute inset-0 bg-white/80 backdrop-blur-[1px] flex items-center justify-center z-10">
                                        <span class="px-2.5 py-1 rounded-[5px] bg-primary text-white font-label-sm text-[10px] uppercase font-medium">Out of Stock</span>
                                    </div>
                                @endif

                                @if ($inCartQty > 0)
                                    <span class="absolute top-2 left-2 z-20 px-2 py-0.5 rounded-[5px] bg-primary text-white font-label-sm text-[10px] tabular-nums font-medium shadow-sm">
                                        {{ $inCartQty }} in Cart
                                    </span>
                                @elseif ($isLowStock)
                                    <span class="absolute top-2 left-2 z-20 px-2 py-0.5 rounded-[5px] bg-destructive-muted border border-destructive-border text-destructive font-label-sm text-[10px] font-medium tabular-nums">
                                        Left: {{ $hasBom ? $product->available_portions : $product->stock }}
                                    </span>
                                @endif

                                @if ($product->photo_url)
                                    <img src="{{ $product->photo_url }}"
                                        alt="{{ $product->product_name }}"
                                        loading="lazy"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                @else
                                    <div class="w-full h-full flex flex-col items-center justify-center text-gray-300">
                                        <span class="material-symbols-rounded text-[32px]">inventory_2</span>
                                    </div>
                                @endif

                                @if ($hasBom && isset($product->available_portions) && $product->available_portions > 0)
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-border text-muted-foreground font-label-sm text-[10px] tabular-nums shadow-2xs">
                                        Portions: {{ $product->available_portions }}
                                    </span>
                                @elseif (!$hasBom && $tracksStock && !is_null($product->stock) && $product->stock > 0)
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-border text-muted-foreground font-label-sm text-[10px] tabular-nums shadow-2xs">
                                        Stock: {{ $product->stock }}
                                    </span>
                                @elseif ($product->isBundle())
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-border text-muted-foreground font-label-sm text-[10px] shadow-2xs">
                                        Bundle
                                    </span>
                                @elseif ($product->isService() || $product->isNonInventory() || is_null($product->stock))
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-border text-muted-foreground font-label-sm text-[10px] shadow-2xs">
                                        In Stock
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-col">
                                <h3 class="font-headline-sm text-[14px] font-medium text-foreground truncate">
                                    {{ $product->product_name }}
                                </h3>
                                <div class="flex items-center justify-between">
                                    <span class="font-label-md text-[12px] text-muted-foreground truncate">
                                        {{ $product->cat_name }}
                                    </span>
                                    @if ($product->modifierGroups->count() > 0)
                                        <span class="px-1.5 py-0.2 rounded bg-muted text-foreground text-[10px] font-medium">Options</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-border">
                            <span class="font-numeric-currency text-[14px] font-semibold text-foreground tabular-nums">
                                Rp {{ number_format($product->product_price, 0, ',', '.') }}
                            </span>
                            <button class="flex items-center justify-center w-7 h-7 rounded-md {{ $isOutOfStock ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-muted hover:bg-primary hover:text-white text-foreground cursor-pointer' }} transition-colors"
                                type="button" title="{{ $isOutOfStock ? ($hasBom ? 'Insufficient ingredients (Out of stock)' : 'Product out of stock') : 'Add' }}"
                                @if(!$isOutOfStock)
                                    x-on:click.stop="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                                @else
                                    disabled
                                @endif>
                                <span class="material-symbols-rounded text-[16px]">add</span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Quick Modifier / POS Shortcut Helper Tray -->
    <div class="shrink-0 mt-2 px-3.5 py-2 rounded-lg bg-muted border border-border flex items-center justify-between select-none">
        <div class="flex items-center gap-2.5">
            <span class="w-6 h-6 rounded bg-secondary flex items-center justify-center text-foreground">
                <span class="material-symbols-rounded text-[15px]">tune</span>
            </span>
            <div class="flex items-center gap-2">
                <span class="font-label-md text-[12px] font-medium text-foreground">Shortcuts:</span>
                <span class="font-label-sm text-[12px] text-muted-foreground">
                    <kbd class="tabular-nums bg-white border border-border px-1 rounded text-[10px]">F2</kbd> Search &bull;
                    <kbd class="tabular-nums bg-white border border-border px-1 rounded text-[10px]">F4</kbd> Pay &bull;
                    <kbd class="tabular-nums bg-white border border-border px-1 rounded text-[10px]">F8</kbd> Park &bull;
                    <kbd class="tabular-nums bg-white border border-border px-1 rounded text-[10px]">F9</kbd> Parked List &bull;
                    <kbd class="tabular-nums bg-white border border-border px-1 rounded text-[10px]">Esc</kbd> Close
                </span>
            </div>
        </div>
        <button type="button" wire:click="$set('isParkModalOpen', true)"
            class="px-2.5 py-1 rounded-[6px] bg-white border border-border hover:bg-muted text-foreground font-label-sm text-[12px] font-medium transition-colors shadow-xs cursor-pointer">
            Park Order (F8)
        </button>
    </div>
</div>
