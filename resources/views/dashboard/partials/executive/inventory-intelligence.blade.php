{{-- ========================================================================= --}}
{{-- SECTION E: INVENTORY INTELLIGENCE — COMPACT 14" SCALE & ZERO PILLS        --}}
{{-- ========================================================================= --}}
@php
    $inv = $executiveData['inventory_intelligence'] ?? [];
    $sum = $inv['summary'] ?? [];
    $stockRows = $inv['stock_control_table'] ?? [];
    $totalSkus = (int) ($sum['total_skus'] ?? 0);
    $lowStock = (int) ($sum['low_stock_count'] ?? 0);
    $outOfStock = (int) ($sum['out_of_stock_count'] ?? 0);
    $valuationFormatted = $sum['valuation_formatted'] ?? 'Rp 0';
@endphp

<section aria-label="Inventory Intelligence" class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4 space-y-3">
    {{-- Stock Health Index Safe Stock Critical / Low $paymentBreakdown QRIS Cash E-Wallet Card --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h2 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Restock Forecast &amp; Inventory Intelligence</h2>
            <p class="text-[11px] text-muted-foreground mt-0.5">Real-time raw material thresholds, stock valuation, and replenishment actions.</p>
        </div>
        <a href="{{ route('inventory.ingredients') }}" wire:navigate
           class="text-[11px] font-semibold text-foreground hover:underline self-start sm:self-auto flex items-center gap-1">
            <span>Open Full Stock Ledger</span>
            <span class="material-symbols-rounded text-sm">arrow_forward</span>
        </a>
    </div>

    <!-- Compact Structured Operational Summary Strip (6 Metrics) -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 rounded-[6px] border border-border bg-secondary divide-y md:divide-y-0 md:divide-x divide-border">
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Total Active SKUs</div>
            <div class="text-sm font-bold tabular-nums text-foreground mt-0.5">{{ number_format($totalSkus) }}</div>
        </div>
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Low-Stock Items</div>
            <div class="text-sm font-bold tabular-nums text-warning mt-0.5">{{ number_format($lowStock) }} SKUs</div>
        </div>
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Out-of-Stock</div>
            <div class="text-sm font-bold tabular-nums text-destructive mt-0.5">{{ number_format($outOfStock) }} SKUs</div>
        </div>
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Inventory Valuation</div>
            <div class="text-sm font-bold tabular-nums text-foreground mt-0.5">{{ $valuationFormatted }}</div>
        </div>
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Fast-Moving Velocity</div>
            <div class="text-[11.5px] font-bold text-foreground truncate mt-0.5">High Attach Items</div>
        </div>
        <div class="p-2.5">
            <div class="text-[10.5px] text-muted-foreground font-medium">Restock Cutoff</div>
            <div class="text-[11.5px] font-bold text-muted-foreground truncate mt-0.5">17:00 WIB Daily</div>
        </div>
    </div>

    <!-- 7-Column Stock Control Table -->
    <div class="overflow-x-auto -mx-3.5 sm:-mx-4 px-3.5 sm:px-4">
        <table class="w-full min-w-[720px] text-xs text-left border-collapse">
            <thead>
                <tr class="border-b border-border text-muted-foreground font-semibold text-[11px]">
                    <th scope="col" class="py-2 pr-3">Product Name</th>
                    <th scope="col" class="py-2 px-3">SKU</th>
                    <th scope="col" class="py-2 px-3">Category</th>
                    <th scope="col" class="py-2 px-3 text-right">Current Stock</th>
                    <th scope="col" class="py-2 px-3 text-right">Minimum Stock</th>
                    <th scope="col" class="py-2 px-3">Stock Status</th>
                    <th scope="col" class="py-2 pl-3 text-right">Recommended Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
                @forelse($stockRows as $idx => $item)
                    @php
                        $isOut = $item['status'] === 'out_of_stock' || (int)$item['current_stock'] <= 0;
                    @endphp
                    <tr wire:key="stock-ctrl-row-{{ $item['product_id'] ?? $idx }}"
                        class="hover:bg-secondary/30 transition-colors">
                        <td class="py-2 pr-3 font-semibold text-foreground">{{ $item['name'] }}</td>
                        <td class="py-2 px-3 font-mono text-[10.5px] text-muted-foreground">{{ $item['sku'] }}</td>
                        <td class="py-2 px-3 text-muted-foreground">{{ $item['category'] }}</td>
                        <td class="py-2 px-3 text-right tabular-nums font-bold {{ $isOut ? 'text-destructive' : 'text-warning' }}">
                            {{ $item['current_stock'] }} pcs
                        </td>
                        <td class="py-2 px-3 text-right tabular-nums text-muted-foreground">{{ $item['min_stock'] }} pcs</td>
                        <td class="py-2 px-3">
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold {{ $isOut ? 'text-destructive' : 'text-warning' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $isOut ? 'bg-destructive' : 'bg-warning' }} shrink-0"></span>
                                <span>{{ $isOut ? 'Out of Stock' : 'Low Stock' }}</span>
                            </span>
                        </td>
                        <td class="py-2 pl-3 text-right">
                            <a href="{{ route('purchasing.purchase-orders.create') }}" wire:navigate
                               class="text-[11px] font-semibold text-foreground hover:underline inline-flex items-center gap-1">
                                <span>{{ $item['action'] }}</span>
                                <span class="material-symbols-rounded text-sm">arrow_forward</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-xs text-muted-foreground">
                            All storage and outlet inventory items are above minimum buffer levels.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
