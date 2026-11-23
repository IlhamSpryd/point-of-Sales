{{-- ========================================================================= --}}
{{-- SLIDE-OVER INSPECTOR: PRODUCT RECIPE BOM & UNIT ECONOMICS DRAWER          --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="productDrawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="product-drawer-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="productDrawerOpen"
         x-transition:enter="ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeProductDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div x-show="productDrawerOpen"
             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md lg:max-w-lg bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Header --}}
            <div class="p-5 sm:p-6 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-primary text-2xl">restaurant</span>
                    <div>
                        <h2 class="text-base font-bold text-foreground" id="product-drawer-title"
                            x-text="selectedProduct ? selectedProduct.name : 'Product Recipe BOM'">
                            Product Recipe BOM
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5"
                           x-text="selectedProduct ? (selectedProduct.sku + ' · ' + (selectedProduct.category || 'General')) : 'Bill of Materials & Unit Economics'">
                            Bill of Materials &amp; Unit Economics
                        </p>
                    </div>
                </div>
                <button type="button"
                        @click="closeProductDrawer()"
                        aria-label="Close product inspector drawer"
                        class="rounded-lg p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-5 sm:p-6 flex-1 overflow-y-auto space-y-6">
                {{-- Loading Skeleton --}}
                <template x-if="isLoadingBom">
                    <div class="space-y-4 animate-pulse">
                        <div class="h-24 bg-muted rounded-xl"></div>
                        <div class="h-40 bg-muted rounded-xl"></div>
                        <div class="h-32 bg-muted rounded-xl"></div>
                    </div>
                </template>

                {{-- Loaded Content --}}
                <template x-if="!isLoadingBom && selectedProduct">
                    <div class="space-y-6">
                        {{-- Economics Overview Card --}}
                        <div class="p-4 rounded-xl bg-secondary border border-border space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Unit Economics</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                      :class="(productBomData?.product?.margin_pct ?? selectedProduct.margin_pct) >= 40 ? 'bg-success-muted text-success border border-success-border' : 'bg-warning-muted text-warning border border-warning-border'"
                                      x-text="(productBomData?.product?.margin_pct ?? selectedProduct.margin_pct) + '% Margin'">
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-3 pt-1">
                                <div>
                                    <span class="text-[11px] text-muted-foreground">Selling Price</span>
                                    <div class="text-base font-bold text-foreground tabular-nums"
                                         x-text="'Rp ' + new Intl.NumberFormat('id-ID').format(productBomData?.product?.selling_price ?? selectedProduct.price)"></div>
                                </div>
                                <div>
                                    <span class="text-[11px] text-muted-foreground">COGS (HPP Resep)</span>
                                    <div class="text-base font-bold text-foreground tabular-nums"
                                         x-text="'Rp ' + new Intl.NumberFormat('id-ID').format(productBomData?.product?.cogs ?? selectedProduct.cogs)"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Recipe BOM Ingredients Table --}}
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Recipe Ingredients (BOM)</h3>
                                <span class="text-[11px] text-muted-foreground"
                                      x-text="(productBomData?.ingredients?.length || 0) + ' Ingredients'"></span>
                            </div>

                            <div class="overflow-x-auto rounded-xl border border-border">
                                <table class="w-full text-xs text-left">
                                    <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                        <tr>
                                            <th scope="col" class="py-2.5 px-3">Ingredient</th>
                                            <th scope="col" class="py-2.5 px-2.5 text-right">Qty</th>
                                            <th scope="col" class="py-2.5 px-2.5 text-right">Cost</th>
                                            <th scope="col" class="py-2.5 px-3 text-right">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        <template x-for="ing in (productBomData?.ingredients || [])" :key="ing.id">
                                            <tr class="hover:bg-muted/30">
                                                <td class="py-2.5 px-3 font-semibold text-foreground">
                                                    <div x-text="ing.name"></div>
                                                    <div class="text-[10px] text-muted-foreground font-mono" x-text="ing.sku"></div>
                                                </td>
                                                <td class="py-2.5 px-2.5 text-right tabular-nums text-foreground"
                                                    x-text="ing.quantity + ' ' + ing.uom"></td>
                                                <td class="py-2.5 px-2.5 text-right tabular-nums font-medium text-foreground"
                                                    x-text="'Rp ' + new Intl.NumberFormat('id-ID').format(ing.total_cost)"></td>
                                                <td class="py-2.5 px-3 text-right tabular-nums font-semibold text-primary"
                                                    x-text="ing.cost_share_pct + '%'"></td>
                                            </tr>
                                        </template>
                                        <template x-if="!productBomData?.ingredients || productBomData.ingredients.length === 0">
                                            <tr>
                                                <td colspan="4" class="py-4 text-center text-xs text-muted-foreground">
                                                    Standard retail item (No dynamic recipe BOM attached).
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Multi-Branch Stock Distribution --}}
                        <div class="space-y-3">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Store Stock Distribution</h3>
                            <div class="space-y-2">
                                <template x-for="s in (productBomData?.store_distribution || [])" :key="s.store_id">
                                    <div class="p-2.5 rounded-lg bg-secondary border border-border flex items-center justify-between text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-rounded text-sm text-primary">storefront</span>
                                            <span class="font-semibold text-foreground" x-text="s.store_name"></span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="tabular-nums font-bold text-foreground" x-text="s.current_stock + ' in stock'"></span>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold"
                                                  :class="s.status === 'healthy' ? 'bg-success-muted text-success border border-success-border' : (s.status === 'low' ? 'bg-warning-muted text-warning border border-warning-border' : 'bg-destructive-muted text-destructive border border-destructive-border')"
                                                  x-text="s.status"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div class="p-4 border-t border-border bg-secondary flex justify-end">
                <button type="button"
                        @click="closeProductDrawer()"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-foreground bg-card hover:bg-muted border border-border shadow-xs transition-colors cursor-pointer">
                    Close Inspector
                </button>
            </div>
        </div>
    </div>
</div>

