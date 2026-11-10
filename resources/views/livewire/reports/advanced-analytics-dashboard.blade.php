<div class="space-y-6 pb-12">
    <x-erp.module-sub-nav module="reports" />

    {{-- Header & Title --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border pb-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-lg bg-muted text-muted-foreground border border-border">
                    <span class="material-symbols-rounded text-2xl">insights</span>
                </span>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-foreground">
                        Business Intelligence & Advanced Analytics
                    </h1>
                    <p class="text-xs text-muted-foreground">
                        Integrated analytics across Sales, Inventory, General Ledger, and Customers.
                    </p>
                </div>
            </div>
        </div>

        {{-- Export Action & Feedback --}}
        <div class="flex items-center gap-3">
            @if ($exportFeedback)
                <div class="text-xs px-3 py-1.5 rounded-md bg-success-muted border border-success-border text-success flex items-center gap-1.5 animate-fade-in">
                    <span class="material-symbols-rounded text-sm">check_circle</span>
                    <span>{{ $exportFeedback }}</span>
                </div>
            @endif

            @if ($activeExportTask && $activeExportTask->status->value === 'completed')
                <a href="{{ route('exports.download', $activeExportTask) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground shadow-xs transition-colors">
                    <span class="material-symbols-rounded text-base">download</span>
                    <span>Download CSV</span>
                </a>
            @else
                <button wire:click="dispatchExport('{{ $activeTab }}_analytics')"
                    wire:loading.attr="disabled"
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground shadow-xs transition-all disabled:opacity-50 cursor-pointer">
                    <span wire:loading.remove wire:target="dispatchExport" class="material-symbols-rounded text-base">file_download</span>
                    <span wire:loading wire:target="dispatchExport" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
                    <span wire:loading.remove wire:target="dispatchExport">Export Report (Async)</span>
                    <span wire:loading wire:target="dispatchExport">Scheduling...</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="bg-card p-4 rounded-xl border border-border shadow-xs flex flex-wrap items-center justify-between gap-4">
        {{-- Navigation Tabs --}}
        <div class="flex items-center gap-1 p-1 bg-muted rounded-lg">
            <button wire:click="setTab('sales')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'sales' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">trending_up</span>
                    <span>Sales</span>
                </span>
            </button>
            <button wire:click="setTab('inventory')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'inventory' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">inventory_2</span>
                    <span>Inventory</span>
                </span>
            </button>
            <button wire:click="setTab('finance')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'finance' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">account_balance</span>
                    <span>General Ledger</span>
                </span>
            </button>
            <button wire:click="setTab('customers')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'customers' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">group</span>
                    <span>Customers & CRM</span>
                </span>
            </button>
        </div>

        {{-- Date Presets & Store Filter --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- Store Select --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-muted-foreground">Branch:</span>
                <select wire:model.live="selectedStoreId"
                    class="text-xs py-1.5 px-2.5 rounded-lg border border-input bg-background text-foreground focus:ring-1 focus:ring-ring">
                    <option value="">All Branches (Tenant Scope)</option>
                    @foreach ($stores as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Date Presets --}}
            <div class="flex items-center gap-1 bg-muted p-1 rounded-lg">
                <button wire:click="applyDatePreset('7d')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === '7d' ? 'bg-card font-semibold text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">7D</button>
                <button wire:click="applyDatePreset('30d')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === '30d' ? 'bg-card font-semibold text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">30D</button>
                <button wire:click="applyDatePreset('this_month')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === 'this_month' ? 'bg-card font-semibold text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">This Month</button>
                <button wire:click="applyDatePreset('last_month')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === 'last_month' ? 'bg-card font-semibold text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">Last Month</button>
            </div>

            {{-- Custom Date Inputs --}}
            <div class="flex items-center gap-1">
                <input type="date" wire:model.live="startDate"
                    class="text-xs py-1.5 px-2 rounded-lg border border-input bg-background text-foreground">
                <span class="text-xs text-muted-foreground">to</span>
                <input type="date" wire:model.live="endDate"
                    class="text-xs py-1.5 px-2 rounded-lg border border-input bg-background text-foreground">
            </div>
        </div>
    </div>

    {{-- Loading Skeleton State --}}
    <div wire:loading class="w-full">
        <div class="p-8 bg-card rounded-xl border border-border flex items-center justify-center gap-3">
            <span class="material-symbols-rounded text-2xl animate-spin text-primary">progress_activity</span>
            <span class="text-sm font-medium text-muted-foreground">Loading analytics data...</span>
        </div>
    </div>

    {{-- Active Tab Content --}}
    <div wire:loading.remove>
        {{-- ================= TAB 1: PENJUALAN ================= --}}
        @if ($activeTab === 'sales')
            <div class="space-y-6">
                {{-- MoM Growth Metric Cards --}}
                @if (isset($momComparison))
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Current Month Revenue</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-foreground">Rp {{ number_format((float) $momComparison['current_revenue'], 0, ',', '.') }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? 'bg-success-muted text-success border border-success-border' : 'bg-destructive-muted text-destructive border border-destructive-border' }}">
                                    {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['revenue_growth_pct'] }}% MoM
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Prior month: Rp {{ number_format((float) $momComparison['previous_revenue'], 0, ',', '.') }}</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Total Orders</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-foreground">{{ number_format($momComparison['current_orders']) }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['orders_growth_pct'] >= 0 ? 'bg-success-muted text-success border border-success-border' : 'bg-destructive-muted text-destructive border border-destructive-border' }}">
                                    {{ (float) $momComparison['orders_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['orders_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Prior month: {{ number_format($momComparison['previous_orders']) }} orders</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Average Order Value (AOV)</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-foreground">Rp {{ number_format((float) $momComparison['current_aov'], 0, ',', '.') }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['aov_growth_pct'] >= 0 ? 'bg-success-muted text-success border border-success-border' : 'bg-destructive-muted text-destructive border border-destructive-border' }}">
                                    {{ (float) $momComparison['aov_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['aov_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Prior month: Rp {{ number_format((float) $momComparison['previous_aov'], 0, ',', '.') }}</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Revenue Variance</span>
                            @php
                                $nominalDiff = bcsub($momComparison['current_revenue'], $momComparison['previous_revenue'], 2);
                            @endphp
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold {{ (float) $nominalDiff >= 0 ? 'text-success' : 'text-destructive' }}">
                                    {{ (float) $nominalDiff >= 0 ? '+' : '' }}Rp {{ number_format((float) $nominalDiff, 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? 'bg-success-muted text-success border border-success-border' : 'bg-destructive-muted text-destructive border border-destructive-border' }}">
                                    {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['revenue_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Comparison: {{ $momComparison['current_period'] }} vs {{ $momComparison['previous_period'] }}</span>
                        </div>
                    </div>
                @endif

                {{-- Top Products & Basket Analysis Tables --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Top 10 SKU --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <div class="flex items-center justify-between pb-3 border-b border-border">
                            <h2 class="text-sm font-semibold text-foreground">Top 10 Best Selling Products</h2>
                            <span class="text-xs text-muted-foreground">Date Range</span>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-muted-foreground border-b border-border">
                                        <th class="py-2">Product</th>
                                        <th class="py-2 text-right">Qty</th>
                                        <th class="py-2 text-right">Revenue</th>
                                        <th class="py-2 text-right">Contribution</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($topProducts ?? [] as $tp)
                                        <tr>
                                             <td class="py-2.5 font-medium text-foreground">
                                                <div>{{ $tp['product_name'] }}</div>
                                                <div class="text-[11px] text-muted-foreground">{{ $tp['sku'] }} • {{ $tp['category_name'] }}</div>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-foreground">{{ number_format($tp['quantity_sold']) }}</td>
                                            <td class="py-2.5 text-right font-semibold text-foreground">Rp {{ number_format((float) $tp['total_revenue'], 0, ',', '.') }}</td>
                                            <td class="py-2.5 text-right font-semibold text-primary">{{ $tp['contribution_pct'] }}%</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-muted-foreground">No sales history recorded for this period.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Market Basket Analysis (Co-occurrence) --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <div class="flex items-center justify-between pb-3 border-b border-border">
                            <h2 class="text-sm font-semibold text-foreground">Market Basket Analysis (Product Pairing)</h2>
                            <span class="text-xs text-muted-foreground">Cross-Selling</span>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-muted-foreground border-b border-border">
                                        <th class="py-2">Item Pair (A + B)</th>
                                        <th class="py-2 text-right">Frequency</th>
                                        <th class="py-2 text-right">Confidence</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($basketAnalysis ?? [] as $ba)
                                        <tr>
                                            <td class="py-2.5 font-medium text-foreground">
                                                <span>{{ $ba['product_a_name'] }}</span>
                                                <span class="text-muted-foreground mx-1">+</span>
                                                <span>{{ $ba['product_b_name'] }}</span>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-foreground">{{ number_format($ba['co_occurrence_count']) }} times</td>
                                            <td class="py-2.5 text-right font-semibold text-primary">{{ $ba['confidence_pct'] }}%</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="py-8 text-center text-muted-foreground">Insufficient multi-item basket data for this period.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= TAB 2: PERSEDIAAN ================= --}}
        @if ($activeTab === 'inventory')
            <div class="space-y-6">
                {{-- Valuation & Turnover Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Total Inventory Valuation</span>
                        <div class="mt-1">
                            <span class="text-xl font-bold text-foreground">Rp {{ number_format((float) ($valuation['total_valuation'] ?? 0), 0, ',', '.') }}</span>
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">Status: {{ $valuation['reconciliation_status'] ?? 'MATCHED' }} ({{ $valuation['total_items_tracked'] ?? 0 }} SKU)</span>
                    </div>

                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Inventory Turnover Ratio</span>
                        <div class="mt-1 flex items-baseline justify-between">
                            <span class="text-xl font-bold text-foreground">{{ $turnover['turnover_ratio'] ?? '0.00' }}x</span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary text-secondary-foreground border border-border">
                                {{ $turnover['velocity_category'] ?? 'HEALTHY' }}
                            </span>
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">DSI: {{ $turnover['days_sales_of_inventory'] ?? '0' }} days sales of inventory</span>
                    </div>

                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Capital Tied in Dead Stock</span>
                        <div class="mt-1">
                            <span class="text-xl font-bold text-destructive">Rp {{ number_format((float) ($deadStock['total_dead_stock_value'] ?? 0), 0, ',', '.') }}</span>
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">{{ $deadStock['dead_stock_count'] ?? 0 }} items without sales > 30 days</span>
                    </div>
                </div>

                {{-- Stock Aging Buckets & Dead Stock List --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Aging Buckets --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-foreground pb-3 border-b border-border">
                            Stock Aging Distribution
                        </h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($aging['buckets'] ?? [] as $key => $b)
                                <div class="p-3 rounded-lg bg-muted/50 border border-border flex items-center justify-between">
                                    <div>
                                        <div class="font-medium text-xs text-foreground">{{ $b['label'] }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $b['item_count'] }} SKU • {{ number_format((float) $b['total_quantity'], 0) }} units</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-xs text-foreground">Rp {{ number_format((float) $b['total_value'], 0, ',', '.') }}</div>
                                        <div class="text-[11px] font-semibold text-primary">{{ $b['percentage'] }}%</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dead Stock Items --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-foreground pb-3 border-b border-border">
                            Dead Stock Listing (> 30 Days)
                        </h2>
                        <div class="mt-3 overflow-x-auto max-h-80">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-muted-foreground border-b border-border">
                                        <th class="py-2">Item</th>
                                        <th class="py-2 text-right">Tied Capital</th>
                                        <th class="py-2 text-right">Days Inactive</th>
                                        <th class="py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($deadStock['items'] ?? [] as $ds)
                                        <tr>
                                            <td class="py-2 font-medium text-foreground">
                                                <div>{{ $ds['product_name'] }}</div>
                                                <div class="text-[11px] text-muted-foreground">{{ $ds['sku'] }} • Qty: {{ number_format((float) $ds['quantity'], 0) }}</div>
                                            </td>
                                            <td class="py-2 text-right font-semibold text-foreground">
                                                Rp {{ number_format((float) $ds['tied_up_capital'], 0, ',', '.') }}
                                            </td>
                                            <td class="py-2 text-right text-destructive font-medium">
                                                {{ $ds['days_without_sale'] }} days
                                            </td>
                                            <td class="py-2 text-right">
                                                <span class="text-xs text-muted-foreground font-medium">
                                                    {{ $ds['recommended_action'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-muted-foreground">No dead stock detected for this period.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= TAB 3: KEUANGAN GL ================= --}}
        @if ($activeTab === 'finance')
            <div class="space-y-6">
                @if (isset($plSummary))
                    {{-- Executive P&L Cards --}}
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Total Revenue</span>
                            <div class="mt-1 text-xl font-bold text-foreground">
                                Rp {{ number_format((float) $plSummary['revenue'], 0, ',', '.') }}
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">General Ledger Accounts 4xxx</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Cost of Goods Sold (COGS)</span>
                            <div class="mt-1 text-xl font-bold text-foreground">
                                Rp {{ number_format((float) $plSummary['cogs'], 0, ',', '.') }}
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">General Ledger Accounts 5xxx</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Gross Profit</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-xl font-bold text-success">
                                    Rp {{ number_format((float) $plSummary['gross_profit'], 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-success-muted text-success border border-success-border">
                                    {{ $plSummary['gross_margin_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Gross Profit Margin</span>
                        </div>

                        <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                            <span class="text-xs font-medium text-muted-foreground">Net Profit</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-xl font-bold text-primary">
                                    Rp {{ number_format((float) $plSummary['net_profit'], 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary text-secondary-foreground border border-border">
                                    {{ $plSummary['net_margin_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground mt-1 block">Net of OpEx & Other Income</span>
                        </div>
                    </div>

                    {{-- P&L Breakdown Table --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-foreground pb-3 border-b border-border">
                            General Ledger Account Breakdown (Posted Transactions)
                        </h2>
                        <div class="mt-4 space-y-4">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Revenue Breakdown</h3>
                                <div class="space-y-1">
                                    @forelse ($plSummary['revenue_breakdown'] as $rb)
                                        <div class="flex items-center justify-between py-1.5 px-3 rounded-md bg-muted/50 text-xs">
                                            <span class="text-foreground">{{ $rb['account_code'] }} - {{ $rb['account_name'] }}</span>
                                            <span class="font-semibold text-foreground">Rp {{ number_format((float) $rb['amount'], 0, ',', '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="text-xs text-muted-foreground py-2">No posted revenue transactions found.</div>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Operating Expenses (OpEx)</h3>
                                <div class="space-y-1">
                                    @forelse ($plSummary['expense_breakdown'] as $eb)
                                        <div class="flex items-center justify-between py-1.5 px-3 rounded-md bg-muted/50 text-xs">
                                            <span class="text-foreground">{{ $eb['account_code'] }} - {{ $eb['account_name'] }}</span>
                                            <span class="font-semibold text-foreground">Rp {{ number_format((float) $eb['amount'], 0, ',', '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="text-xs text-muted-foreground py-2">No operating expenses recorded for this period.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ================= TAB 4: PELANGGAN & CRM ================= --}}
        @if ($activeTab === 'customers')
            <div class="space-y-6">
                {{-- CLV & Loyalty Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Total Tracked Customers</span>
                        <div class="mt-1 text-xl font-bold text-foreground">
                            {{ number_format($clvSummary['total_tracked_customers'] ?? 0) }} customers
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">Customers with recorded transactions</span>
                    </div>

                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Average Lifetime Value (CLV)</span>
                        <div class="mt-1 text-xl font-bold text-foreground">
                            Rp {{ number_format((float) ($clvSummary['average_clv'] ?? 0), 0, ',', '.') }}
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">Average {{ $clvSummary['average_order_frequency'] ?? '0' }}x purchases</span>
                    </div>

                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Loyalty Points Earned</span>
                        <div class="mt-1 text-xl font-bold text-success">
                            +{{ number_format((float) ($loyaltyMovement['points_earned'] ?? 0), 0) }}
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">Audit ledger loyalty_ledger</span>
                    </div>

                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs font-medium text-muted-foreground">Loyalty Points Redeemed</span>
                        <div class="mt-1 text-xl font-bold text-warning">
                            -{{ number_format((float) ($loyaltyMovement['points_redeemed'] ?? 0), 0) }}
                        </div>
                        <span class="text-[11px] text-muted-foreground mt-1 block">Net delta: {{ $loyaltyMovement['net_points_change'] ?? '0' }} points</span>
                    </div>
                </div>

                {{-- RFM Segments & Top Customers --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- RFM Segments --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-foreground pb-3 border-b border-border">
                            Customer RFM Segmentation (Recency, Frequency, Monetary)
                        </h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($rfm['segments'] ?? [] as $key => $seg)
                                <div class="p-3 rounded-lg bg-muted/50 border border-border flex items-center justify-between">
                                    <div>
                                        <div class="font-medium text-xs text-foreground">{{ $seg['segment_name'] }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $seg['customer_count'] }} customers</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-xs text-foreground">Rp {{ number_format((float) $seg['total_revenue'], 0, ',', '.') }}</div>
                                        <div class="text-[11px] font-semibold text-primary">{{ $seg['percentage'] }}%</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Top Customers --}}
                    <div class="bg-card rounded-xl border border-border shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-foreground pb-3 border-b border-border">
                            Top 10 Most Loyal Customers
                        </h2>
                        <div class="mt-3 overflow-x-auto max-h-80">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-muted-foreground border-b border-border">
                                        <th class="py-2">Customer</th>
                                        <th class="py-2 text-right">Frequency</th>
                                        <th class="py-2 text-right">Total Spend</th>
                                        <th class="py-2 text-right">AOV</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($clvSummary['top_customers'] ?? [] as $tc)
                                        <tr>
                                            <td class="py-2.5 font-medium text-foreground">
                                                <div>{{ $tc['name'] }}</div>
                                                <div class="text-[11px] text-muted-foreground">{{ $tc['phone'] }}</div>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-foreground">{{ $tc['order_count'] }}x</td>
                                            <td class="py-2.5 text-right font-semibold text-foreground">
                                                Rp {{ number_format((float) $tc['total_spend'], 0, ',', '.') }}
                                            </td>
                                            <td class="py-2.5 text-right font-semibold text-primary">
                                                Rp {{ number_format((float) $tc['average_order_value'], 0, ',', '.') }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-muted-foreground">No customer transactions recorded.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
