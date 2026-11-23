{{-- ========================================================================= --}}
{{-- SECTION A: 12-COL ASYMMETRIC KPI ARCHITECTURE (CLICKABLE FOR DRILLDOWN)   --}}
{{-- ========================================================================= --}}
@php
    $k = $executiveData['kpi'] ?? $executiveData['accrualSales'] ?? [];
    $grossSales = (float) ($k['gross_sales'] ?? 0);
    $netSales = (float) ($k['net_sales'] ?? 0);
    $discounts = (float) ($k['total_discounts'] ?? 0);
    $ordersCount = (int) ($k['total_orders'] ?? 0);
    $aov = (float) ($k['aov'] ?? 0);
    $grossProfit = (float) ($k['gross_profit'] ?? 0);
    $grossMarginPct = (float) ($k['gross_margin_pct'] ?? 0);
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);

    // Sparkline data series from revenue_series or fallback
    $revSeries = $executiveData['revenue_series']['data'] ?? $executiveData['revenue_series'] ?? [];
    $grossSpark = !empty($revSeries) ? array_column($revSeries, 'gross') : [0,0,0,0,0];
    $netSpark = !empty($revSeries) ? array_column($revSeries, 'net') : [0,0,0,0,0];
    $txSpark = !empty($revSeries) ? array_column($revSeries, 'tx') : [0,0,0,0,0];
    $atvSpark = !empty($revSeries) ? array_map(fn($r) => ($r['tx'] ?? 0) > 0 ? (float) ($r['net'] / $r['tx']) : 0, $revSeries) : [0,0,0,0,0];
    $profitSpark = !empty($revSeries) ? array_map(fn($r) => (float) (($r['net'] ?? 0) * ($grossMarginPct / 100)), $revSeries) : [0,0,0,0,0];
    $activeOrdersSpark = [2, 4, 3, 5, 4, 6, 5];

    $netDelta = $k['net_sales_delta'] ?? '+0.0%';
    $netDeltaPositive = !str_starts_with((string)$netDelta, '-');
    $ordersDelta = $k['orders_delta'] ?? '+0.0%';
    $ordersDeltaPositive = !str_starts_with((string)$ordersDelta, '-');
@endphp

<section aria-label="Key Performance Indicators" class="grid grid-cols-12 gap-3 sm:gap-4">
    <!-- Wide Tile 1: Gross Revenue -->
    <button type="button"
            @click="openKpiDrawer('grossRevenue')"
            class="text-left rounded-xl border border-border bg-card p-4 sm:p-5 hover:border-border-strong transition-colors col-span-12 md:col-span-6 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Gross Revenue</div>
        <div class="flex items-baseline gap-2.5 mt-1 flex-wrap">
            <span class="font-semibold tabular-nums tracking-tight text-foreground text-2xl sm:text-3xl">
                Rp {{ number_format($grossSales, 0, ',', '.') }}
            </span>
            <span class="text-xs font-semibold tabular-nums {{ $netDeltaPositive ? 'text-success' : 'text-destructive' }}">
                {{ $netDeltaPositive ? '↗' : '↘' }} {{ $netDelta }}
            </span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">Incl. PB1 tax and service charge · Click to inspect per-outlet breakdown</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($grossSpark), options: { strokeColor: 'var(--foreground)' } })">
        </div>
    </button>

    <!-- Wide Tile 2: Net Revenue -->
    <button type="button"
            @click="openKpiDrawer('netRevenue')"
            class="text-left rounded-xl border border-border bg-card p-4 sm:p-5 hover:border-border-strong transition-colors col-span-12 md:col-span-6 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Net Revenue</div>
        <div class="flex items-baseline gap-2.5 mt-1 flex-wrap">
            <span class="font-semibold tabular-nums tracking-tight text-foreground text-2xl sm:text-3xl">
                Rp {{ number_format($netSales, 0, ',', '.') }}
            </span>
            <span class="text-xs font-semibold tabular-nums {{ $netDeltaPositive ? 'text-success' : 'text-destructive' }}">
                {{ $netDeltaPositive ? '↗' : '↘' }} {{ $netDelta }}
            </span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">After Rp {{ number_format($discounts, 0, ',', '.') }} in discounts · PSAK Accrual</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($netSpark), options: { strokeColor: 'var(--chart-accent)' } })">
        </div>
    </button>

    <!-- Compact Tile 1: Completed Transactions -->
    <button type="button"
            @click="openKpiDrawer('transactions')"
            class="text-left rounded-xl border border-border bg-card p-4 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Transactions</div>
        <div class="flex items-baseline gap-2 mt-1 flex-wrap">
            <span class="font-semibold tabular-nums tracking-tight text-foreground text-xl">
                {{ number_format($ordersCount, 0, ',', '.') }}
            </span>
            <span class="text-xs font-semibold tabular-nums {{ $ordersDeltaPositive ? 'text-success' : 'text-destructive' }}">
                {{ $ordersDeltaPositive ? '↗' : '↘' }} {{ $ordersDelta }}
            </span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">Completed checkout tickets</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($txSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>

    <!-- Compact Tile 2: Average Transaction Value (ATV) -->
    <button type="button"
            @click="openKpiDrawer('atv')"
            class="text-left rounded-xl border border-border bg-card p-4 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Average Transaction</div>
        <div class="flex items-baseline gap-2 mt-1 flex-wrap">
            <span class="font-semibold tabular-nums tracking-tight text-foreground text-xl">
                Rp {{ number_format($aov, 0, ',', '.') }}
            </span>
            <span class="text-xs font-semibold tabular-nums text-muted-foreground">ATV</span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">Net revenue ÷ completed transactions</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($atvSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>

    <!-- Compact Tile 3: Gross Profit -->
    <button type="button"
            @click="openKpiDrawer('grossProfit')"
            class="text-left rounded-xl border border-border bg-card p-4 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Gross Profit</div>
        <div class="flex items-baseline gap-2 mt-1 flex-wrap">
            @if($hasMargin)
                <span class="font-semibold tabular-nums tracking-tight text-foreground text-xl">
                    Rp {{ number_format($grossProfit, 0, ',', '.') }}
                </span>
                <span class="text-xs font-semibold tabular-nums text-success">
                    {{ number_format($grossMarginPct, 1, ',', '.') }}%
                </span>
            @else
                <span class="font-semibold text-muted-foreground text-sm flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">lock</span> Protected
                </span>
            @endif
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">{{ $hasMargin ? 'Profit after recipe COGS deduction' : 'Restricted by role permission' }}</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($profitSpark), options: { strokeColor: 'var(--chart-accent)' } })">
        </div>
    </button>

    <!-- Compact Tile 4: Active Orders -->
    <button type="button"
            @click="openKpiDrawer('activeOrders')"
            class="text-left rounded-xl border border-border bg-card p-4 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="text-xs text-muted-foreground font-medium">Active Orders</div>
        <div class="flex items-baseline gap-2 mt-1 flex-wrap">
            <span class="font-semibold tabular-nums tracking-tight text-foreground text-xl">
                {{ $openOrdersCount ?? 0 }} open
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-success"></span> Live
            </span>
        </div>
        <div class="text-xs text-muted-foreground mt-0.5">Live open kitchen &amp; delivery orders</div>
        <div class="mt-3 h-9 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($activeOrdersSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>
</section>

