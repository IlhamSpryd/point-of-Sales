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

<!-- Kolom Kiri: Penemuan & Galeri Produk -->
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
        <!-- Semua Menu -->
        <button type="button" @click="activeCategory = 'all'"
            :class="activeCategory === 'all' ? 'bg-[#171717] text-white shadow-sm' : 'bg-white hover:bg-[#F9FAFB] text-[#4B5563] hover:text-[#111827] border border-[#E5E5E5]'"
            class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-all cursor-pointer">
            <span class="truncate">Semua Menu</span>
            <span :class="activeCategory === 'all' ? 'bg-white/20 text-white' : 'bg-[#F1F1F1] text-[#6B7280]'"
                class="px-1.5 py-0.5 rounded-[5px] font-label-sm text-[11px] font-mono transition-colors">
                {{ $this->categories->sum(fn($cat) => $cat->products->count()) }}
            </span>
        </button>

        @foreach ($this->categories as $category)
            @if ($category->products->count() > 0)
                <button type="button" @click="activeCategory = {{ $category->id }}"
                    :class="activeCategory === {{ $category->id }} ? 'bg-[#171717] text-white shadow-sm' : 'bg-white hover:bg-[#F9FAFB] text-[#4B5563] hover:text-[#111827] border border-[#E5E5E5]'"
                    class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-all cursor-pointer">
                    <span class="truncate">{{ $category->category_name }}</span>
                    <span :class="activeCategory === {{ $category->id }} ? 'bg-white/20 text-white' : 'bg-[#F1F1F1] text-[#6B7280]'"
                        class="px-1.5 py-0.5 rounded-[5px] font-label-sm text-[11px] font-mono transition-colors">
                        {{ $category->products->count() }}
                    </span>
                </button>
            @endif
        @endforeach

        <div class="h-5 w-px bg-[#E5E5E5] shrink-0 mx-1"></div>

        <button type="button" @click="activeCategory = 'fav'"
            :class="activeCategory === 'fav' ? 'bg-[#171717] text-white shadow-sm' : 'bg-white hover:bg-[#F9FAFB] text-[#4B5563] hover:text-[#111827] border border-[#E5E5E5]'"
            class="flex items-center gap-1.5 px-3 py-2 rounded-lg font-label-md text-[13px] font-medium shrink-0 transition-colors cursor-pointer">
            <span class="material-symbols-rounded text-[15px] text-amber-500">star</span>
            <span class="truncate">Favorit Kasir</span>
        </button>
    </div>

    <!-- Search Input & Barcode Ready Status -->
    <div class="flex items-center gap-3 mb-5 shrink-0">
        <div class="relative flex-1">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[#9CA3AF] text-[18px]">search</span>
            <input type="text"
                wire:model.live.debounce.300ms="search"
                id="search-input"
                placeholder="Cari nama produk, SKU, atau scan barcode (F2)..."
                class="w-full h-10 pl-9 pr-20 rounded-lg bg-white border border-[#E5E5E5] text-[#111827] placeholder-[#9CA3AF] font-body-sm text-[13px] focus:outline-none focus:border-[#171717] transition-all">
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-1.5 pointer-events-none">
                <span class="px-1.5 py-0.5 rounded bg-[#F1F1F1] border border-[#E5E5E5] text-[#6B7280] font-label-sm text-[11px] font-mono uppercase tracking-wide">F2</span>
            </div>
        </div>

        <button type="button" @click="focusSearch()" title="Klik untuk fokus scan barcode (F2)"
            class="flex items-center gap-2 px-3 h-10 rounded-lg bg-white border border-[#E5E5E5] hover:border-[#171717] hover:bg-[#F9FAFB] text-[#4B5563] shrink-0 shadow-xs transition-colors cursor-pointer">
            <span class="material-symbols-rounded text-[17px] text-[#111827]">qr_code_scanner</span>
            <span class="font-label-sm text-[12px] font-medium text-[#111827]">Barcode Ready</span>
        </button>
    </div>

    <!-- Product Cards Grid (Scrollable Container) -->
    <div id="top-products" class="flex-1 overflow-y-auto pr-1 pt-1.5 custom-scrollbar min-h-0">
        @if ($allProducts->isEmpty())
            <div class="h-64 flex flex-col items-center justify-center text-center p-8 select-none">
                <div class="w-12 h-12 rounded-xl bg-[#F4F4F5] flex items-center justify-center text-gray-400 mb-2 border border-[#E5E5E5]">
                    <span class="material-symbols-rounded text-[28px]">search_off</span>
                </div>
                <p class="font-headline-sm text-[14px] font-semibold text-[#111827]">Produk Tidak Ditemukan</p>
                <p class="font-body-sm text-[12px] text-[#6B7280] max-w-[280px] mt-1">
                    {{ $searchTerm !== '' ? 'Tidak ada produk yang cocok dengan pencarian "' . $this->search . '".' : 'Belum ada produk yang tersedia pada katalog.' }}
                </p>
            </div>
        @else
            <!-- Unified Responsive Grid: Fills across columns (2, 3, 4) first! -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-4 gap-4 pb-2">
                @foreach ($allProducts as $product)
                    @php
                        $inCartQty = $this->cartQtyByProduct[$product->id] ?? 0;
                        $tracksStock = (bool) ($product->track_inventory ?? true)
                            && ! ($product->isService() || $product->isNonInventory() || $product->isBundle());
                        $isOutOfStock = $tracksStock && !is_null($product->stock) && $product->stock <= 0;
                        $isLowStock = $tracksStock && !is_null($product->stock) && $product->stock > 0 && $product->stock <= 10;
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
                        aria-label="{{ $isOutOfStock ? ($product->product_name . ' - Stok Habis') : ('Add ' . $product->product_name . ' to cart, price Rp ' . number_format($product->product_price, 0, ',', '.')) }}"
                        class="{{ $isOutOfStock ? 'opacity-60 cursor-not-allowed bg-gray-50 border border-[#E5E5E5]' : 'group bg-white border border-[#E5E5E5] hover:border-[#171717] hover:shadow-sm cursor-pointer' }} relative flex flex-col justify-between p-3.5 rounded-xl transition-all duration-200 select-none outline-none focus-visible:ring-2 focus-visible:ring-[#171717]">

                        <div class="flex flex-col gap-2.5">
                            <div class="relative w-full h-[120px] rounded-lg overflow-hidden bg-[#F4F4F5] flex items-center justify-center">
                                @if ($isOutOfStock)
                                    <div class="absolute inset-0 bg-white/80 backdrop-blur-[1px] flex items-center justify-center z-10">
                                        <span class="px-2.5 py-1 rounded-[5px] bg-[#171717] text-white font-label-sm text-[10px] uppercase font-medium">Stok Habis</span>
                                    </div>
                                @endif

                                @if ($inCartQty > 0)
                                    <span class="absolute top-2 left-2 z-20 px-2 py-0.5 rounded-[5px] bg-[#171717] text-white font-label-sm text-[10px] font-mono font-medium shadow-sm">
                                        {{ $inCartQty }} di Pesanan
                                    </span>
                                @elseif ($isLowStock)
                                    <span class="absolute top-2 left-2 z-20 px-2 py-0.5 rounded-[5px] bg-[#FEE2E2] border border-[#FECACA] text-[#DC2626] font-label-sm text-[10px] font-medium font-mono">
                                        Sisa {{ $product->stock }}
                                    </span>
                                @endif

                                @if ($product->product_photo)
                                    <img src="{{ Storage::url($product->product_photo) }}"
                                        alt="{{ $product->product_name }}"
                                        loading="lazy"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                @else
                                    <div class="w-full h-full flex flex-col items-center justify-center text-gray-300">
                                        <span class="material-symbols-rounded text-[32px]">inventory_2</span>
                                    </div>
                                @endif

                                @if ($tracksStock && !is_null($product->stock) && $product->stock > 0)
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-[#E5E5E5] text-[#525252] font-label-sm text-[10px] font-mono shadow-2xs">
                                        Stok: {{ $product->stock }}
                                    </span>
                                @elseif ($product->isBundle())
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-[#E5E5E5] text-[#525252] font-label-sm text-[10px] shadow-2xs">
                                        Bundle
                                    </span>
                                @elseif ($product->isService() || $product->isNonInventory() || is_null($product->stock))
                                    <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-[5px] bg-white/95 backdrop-blur-sm border border-[#E5E5E5] text-[#525252] font-label-sm text-[10px] shadow-2xs">
                                        Tersedia
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-col">
                                <h3 class="font-headline-sm text-[14px] font-medium text-[#111827] truncate">
                                    {{ $product->product_name }}
                                </h3>
                                <div class="flex items-center justify-between">
                                    <span class="font-label-md text-[12px] text-[#6B7280] truncate">
                                        {{ $product->cat_name }}
                                    </span>
                                    @if ($product->modifierGroups->count() > 0)
                                        <span class="px-1.5 py-0.2 rounded bg-[#F1F1F1] text-[#111827] text-[10px] font-medium">Opsi</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-[#F1F1F1]">
                            <span class="font-numeric-currency text-[14px] font-semibold text-[#111827] font-mono">
                                Rp {{ number_format($product->product_price, 0, ',', '.') }}
                            </span>
                            <button class="flex items-center justify-center w-7 h-7 rounded-md bg-[#F4F4F5] hover:bg-[#171717] hover:text-white transition-colors text-[#111827] cursor-pointer"
                                type="button" title="Tambah"
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
    <div class="shrink-0 mt-2 px-3.5 py-2 rounded-lg bg-[#F9FAFB] border border-[#E5E5E5] flex items-center justify-between select-none">
        <div class="flex items-center gap-2.5">
            <span class="w-6 h-6 rounded bg-[#E5E5E5] flex items-center justify-center text-[#111827]">
                <span class="material-symbols-rounded text-[15px]">tune</span>
            </span>
            <div class="flex items-center gap-2">
                <span class="font-label-md text-[12px] font-medium text-[#111827]">Pintasan Kasir:</span>
                <span class="font-label-sm text-[12px] text-[#6B7280]">
                    <kbd class="font-mono bg-white border border-[#E5E5E5] px-1 rounded text-[10px]">F2</kbd> Cari &bull;
                    <kbd class="font-mono bg-white border border-[#E5E5E5] px-1 rounded text-[10px]">F4</kbd> Bayar &bull;
                    <kbd class="font-mono bg-white border border-[#E5E5E5] px-1 rounded text-[10px]">F8</kbd> Tahan &bull;
                    <kbd class="font-mono bg-white border border-[#E5E5E5] px-1 rounded text-[10px]">F9</kbd> Antrean &bull;
                    <kbd class="font-mono bg-white border border-[#E5E5E5] px-1 rounded text-[10px]">Esc</kbd> Tutup
                </span>
            </div>
        </div>
        <button type="button" wire:click="$set('isParkModalOpen', true)"
            class="px-2.5 py-1 rounded-[6px] bg-white border border-[#E5E5E5] hover:bg-[#F4F4F5] text-[#111827] font-label-sm text-[12px] font-medium transition-colors shadow-xs cursor-pointer">
            Tahan Pesanan (F8)
        </button>
    </div>
</div>
