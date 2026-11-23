{{-- ========================================================================= --}}
{{-- SECTION D: PRODUCT PERFORMANCE & UNIT ECONOMICS — COMPACT 14" SCALE        --}}
{{-- ========================================================================= --}}
@php
    $unitEco = $executiveData['product_unit_economics'] ?? [];
    $products = $unitEco['items'] ?? [];
    $totalProducts = $unitEco['total'] ?? count($products);
    $categories = array_values(array_unique(array_filter(array_column($products, 'category'))));
@endphp

<section aria-label="Product Performance" class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4"
         x-data="{
             search: '',
             selectedCat: 'ALL',
             sortField: 'revenue',
             sortAsc: false,
             page: 1,
             pageSize: 6,
             items: @js($products),
             get filteredItems() {
                 let res = this.items;
                 if (this.selectedCat !== 'ALL') {
                     res = res.filter(i => (i.category || i.category_name) === this.selectedCat);
                 }
                 if (this.search.trim()) {
                     const q = this.search.toLowerCase();
                     res = res.filter(i => (i.name && i.name.toLowerCase().includes(q)) || (i.sku && i.sku.toLowerCase().includes(q)));
                 }
                 return res.sort((a, b) => {
                     let valA = a[this.sortField];
                     let valB = b[this.sortField];
                     if (typeof valA === 'string') {
                         return this.sortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                     }
                     return this.sortAsc ? (valA - valB) : (valB - valA);
                 });
             },
             get pagedItems() {
                 const start = (this.page - 1) * this.pageSize;
                 return this.filteredItems.slice(start, start + this.pageSize);
             },
             get totalPages() {
                 return Math.max(1, Math.ceil(this.filteredItems.length / this.pageSize));
             },
             sortBy(field) {
                 if (this.sortField === field) {
                     this.sortAsc = !this.sortAsc;
                 } else {
                     this.sortField = field;
                     this.sortAsc = false;
                 }
                 this.page = 1;
             }
         }">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-3">
        <div>
            <h2 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Product Performance &amp; Unit Economics</h2>
            <p class="text-[11px] text-muted-foreground mt-0.5">
                Recipe-costed gross profit, margin %, and sales velocity. Klik baris untuk rincian BOM resep.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Search Input -->
            <div class="relative">
                <span class="material-symbols-rounded text-sm text-muted-foreground absolute left-2.5 top-1/2 -translate-y-1/2">search</span>
                <input type="text"
                       x-model="search"
                       @input="page = 1"
                       placeholder="Search SKU or product..."
                       class="h-7 pl-7 pr-2.5 rounded-md bg-secondary border border-border text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring" />
            </div>

            <!-- Category Filter -->
            <select x-model="selectedCat"
                    @change="page = 1"
                    aria-label="Filter by product category"
                    class="h-7 px-2 rounded-md bg-secondary border border-border text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring cursor-pointer">
                <option value="ALL">All Categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- 9-Column Unit Economics Table -->
    <div class="overflow-x-auto -mx-3.5 sm:-mx-4 px-3.5 sm:px-4">
        <table class="w-full min-w-[800px] text-xs text-left border-collapse">
            <thead>
                <tr class="border-b border-border text-muted-foreground font-semibold text-[11px]">
                    <th scope="col" class="py-2 pr-3 cursor-pointer hover:text-foreground" @click="sortBy('name')">
                        Product <span x-show="sortField === 'name'" x-text="sortAsc ? '↑' : '↓'"></span>
                    </th>
                    <th scope="col" class="py-2 px-2.5">SKU</th>
                    <th scope="col" class="py-2 px-2.5">Category</th>
                    <th scope="col" class="py-2 px-2.5 text-right cursor-pointer hover:text-foreground" @click="sortBy('units_sold')">
                        Units <span x-show="sortField === 'units_sold'" x-text="sortAsc ? '↑' : '↓'"></span>
                    </th>
                    <th scope="col" class="py-2 px-2.5 text-right cursor-pointer hover:text-foreground" @click="sortBy('revenue')">
                        Revenue <span x-show="sortField === 'revenue'" x-text="sortAsc ? '↑' : '↓'"></span>
                    </th>
                    <th scope="col" class="py-2 px-2.5 text-right">Unit COGS</th>
                    <th scope="col" class="py-2 px-2.5 text-right cursor-pointer hover:text-foreground" @click="sortBy('gross_profit')">
                        Gross Profit <span x-show="sortField === 'gross_profit'" x-text="sortAsc ? '↑' : '↓'"></span>
                    </th>
                    <th scope="col" class="py-2 px-2.5 text-right cursor-pointer hover:text-foreground" @click="sortBy('margin_pct')">
                        Margin <span x-show="sortField === 'margin_pct'" x-text="sortAsc ? '↑' : '↓'"></span>
                    </th>
                    <th scope="col" class="py-2 pl-2.5 text-right">Stock</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
                <template x-for="item in pagedItems" :key="item.product_id || item.sku">
                    <tr @click="openProductDrawer(item)"
                        class="cursor-pointer hover:bg-secondary/40 transition-colors">
                        <td class="py-2 pr-3 font-semibold text-foreground" x-text="item.name"></td>
                        <td class="py-2 px-2.5 font-mono text-[10.5px] text-muted-foreground" x-text="item.sku"></td>
                        <td class="py-2 px-2.5 text-muted-foreground" x-text="item.category || item.category_name"></td>
                        <td class="py-2 px-2.5 text-right tabular-nums font-medium text-foreground" x-text="new Intl.NumberFormat('id-ID').format(item.units_sold)"></td>
                        <td class="py-2 px-2.5 text-right tabular-nums font-semibold text-foreground" x-text="'Rp ' + new Intl.NumberFormat('id-ID').format(item.revenue)"></td>
                        <td class="py-2 px-2.5 text-right tabular-nums text-muted-foreground" x-text="item.unit_cogs_formatted || ('Rp ' + new Intl.NumberFormat('id-ID').format(item.unit_cogs))"></td>
                        <td class="py-2 px-2.5 text-right tabular-nums font-semibold text-foreground" x-text="item.gross_profit_formatted || ('Rp ' + new Intl.NumberFormat('id-ID').format(item.gross_profit))"></td>
                        <td class="py-2 px-2.5 text-right tabular-nums font-semibold text-positive" x-text="(item.margin_pct || item.gross_margin_pct || 0).toFixed(1) + '%'"></td>
                        <td class="py-2 pl-2.5 text-right tabular-nums font-medium"
                            :class="item.stock_balance <= 0 ? 'text-destructive font-bold' : (item.stock_balance <= 10 ? 'text-warning font-semibold' : 'text-foreground')"
                            x-text="item.stock_balance + ' pcs'"></td>
                    </tr>
                </template>
                <template x-if="filteredItems.length === 0">
                    <tr>
                        <td colspan="9" class="py-6 text-center text-xs text-muted-foreground">
                            No product records matching your search or category filter.
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div class="mt-2.5 pt-2.5 border-t border-border flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-muted-foreground">
        <span class="tabular-nums text-[11px]">
            Showing <strong class="text-foreground" x-text="filteredItems.length ? ((page - 1) * pageSize + 1) : 0"></strong>–<strong class="text-foreground" x-text="Math.min(filteredItems.length, page * pageSize)"></strong> of <strong class="text-foreground" x-text="filteredItems.length"></strong> products
        </span>
        <div class="flex items-center gap-1.5">
            <button type="button"
                    @click="page = Math.max(1, page - 1)"
                    :disabled="page <= 1"
                    class="h-6 px-2 rounded border border-border bg-card hover:bg-secondary disabled:opacity-40 text-foreground cursor-pointer transition-colors text-[11px]">
                Previous
            </button>
            <span class="px-1.5 tabular-nums text-[11px]">Page <span x-text="page"></span> of <span x-text="totalPages"></span></span>
            <button type="button"
                    @click="page = Math.min(totalPages, page + 1)"
                    :disabled="page >= totalPages"
                    class="h-6 px-2 rounded border border-border bg-card hover:bg-secondary disabled:opacity-40 text-foreground cursor-pointer transition-colors text-[11px]">
                Next
            </button>
        </div>
    </div>
</section>
