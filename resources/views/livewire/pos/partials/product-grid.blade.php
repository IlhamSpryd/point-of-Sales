<!-- Area Grid: Menampilkan kumpulan galeri produk -->
<div id="top-products" class="p-4 lg:p-6 lg:pt-2 scroll-mt-32">
    @foreach ($this->categories as $category)
        @if ($category->products->count() > 0)
            <div id="cat-{{ $category->id }}" class="scroll-mt-32 mb-6">
                <div class="flex items-center justify-between mb-3 px-1">
                    <h3 class="text-xs font-black uppercase tracking-wider text-gray-400">{{ $category->category_name }}</h3>
                    <span class="text-[11px] font-semibold text-gray-400">{{ $category->products->count() }} item</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3 lg:gap-4">
                    @foreach ($category->products as $product)
                        @php
                            // O(1) in-memory lookup via #[Computed] cartQtyByProduct
                            $inCartQty = $this->cartQtyByProduct[$product->id] ?? 0;
                            $isOutOfStock = (bool) ($product->track_inventory ?? true)
                                && ! ($product->isService() || $product->isNonInventory() || $product->isBundle())
                                && !is_null($product->stock)
                                && $product->stock <= 0;
                        @endphp
                        <div wire:key="product-{{ $product->id }}"
                            @if(!$isOutOfStock)
                                x-on:click="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                                @keydown.enter="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                                @keydown.space.prevent="window.erpMotion?.pulseHighlight($el); $wire.{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                            @endif
                            role="button" tabindex="{{ $isOutOfStock ? '-1' : '0' }}"
                            @if($isOutOfStock) aria-disabled="true" @endif
                            aria-label="{{ $isOutOfStock ? ($product->product_name . ' - Stok Habis') : ('Tambah ' . $product->product_name . ' ke keranjang, harga Rp ' . number_format($product->product_price, 0, ',', '.')) }}"
                            class="{{ $isOutOfStock ? 'opacity-60 cursor-not-allowed bg-gray-50 border border-[#E5E7EB]' : 'group bg-white border border-[#E5E7EB] shadow-[0_2px_8px_-2px_rgba(0,0,0,0.05)] hover:border-apeiron-ink hover:shadow-md hover:-translate-y-0.5 cursor-pointer active:scale-[0.98]' }} rounded-2xl transition-all duration-200 flex flex-col h-full relative outline-none focus-visible:ring-2 focus-visible:ring-gray-900 select-none">

                            @if ($inCartQty > 0)
                                <div class="absolute -top-2 -right-2 z-20 flex">
                                    <span class="bg-apeiron-ink border-2 border-white text-white text-[11px] font-black min-w-[22px] h-5 px-1.5 rounded-full flex items-center justify-center shadow-md">
                                        {{ $inCartQty }}
                                    </span>
                                </div>
                            @endif

                            <div class="w-full aspect-square sm:aspect-[4/3] bg-gray-50 relative overflow-hidden group-hover:opacity-95 transition-opacity rounded-t-2xl shrink-0 border-b border-[#E5E7EB] flex items-center justify-center">
                                @if ($isOutOfStock)
                                    <div class="absolute inset-0 bg-white/75 backdrop-blur-[1px] flex items-center justify-center z-10">
                                        <span class="px-2.5 py-1 rounded-lg bg-gray-800 text-white text-[11px] font-bold uppercase tracking-wider">Stok Habis</span>
                                    </div>
                                @endif
                                @if ($product->product_photo)
                                    <img src="{{ Storage::url($product->product_photo) }}"
                                        alt="{{ $product->product_name }}"
                                        loading="lazy"
                                        class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex flex-col items-center justify-center text-gray-300">
                                        <span class="material-symbols-rounded text-[40px]">inventory_2</span>
                                    </div>
                                @endif
                            </div>

                            <div class="p-3 lg:p-3.5 flex flex-col flex-1 relative rounded-b-2xl">
                                <h4 class="text-[13px] lg:text-[13.5px] font-bold text-apeiron-ink mb-2 leading-snug line-clamp-2">
                                    {{ $product->product_name }}
                                </h4>

                                <div class="mt-auto flex items-end justify-between pt-1">
                                    <span class="block text-[13px] lg:text-[14px] font-black text-apeiron-ink tabular-nums">
                                        Rp {{ number_format($product->product_price, 0, ',', '.') }}
                                    </span>
                                    @if ($product->modifierGroups->count() > 0)
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 uppercase tracking-tight">Opsi</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</div>
