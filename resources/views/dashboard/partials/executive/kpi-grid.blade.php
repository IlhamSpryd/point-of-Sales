{{-- ========================================================================= --}}
{{-- SECTION A: 12-COL ASYMMETRIC KPI GRID — COMPACT 14" SCALE & ZERO PILLS    --}}
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
    $openCount = (int) ($openOrdersCount ?? ($k['open_orders'] ?? 0));
    $activeOrdersSpark = $openCount > 0 ? [max(1, $openCount - 2), $openCount, max(1, $openCount - 1), $openCount + 1, $openCount] : [0, 0, 0, 0, 0, 0, 0];

    $netDelta = $k['net_sales_delta'] ?? '+0.0%';
    $netDeltaPositive = !str_starts_with((string)$netDelta, '-');
    $ordersDelta = $k['orders_delta'] ?? '+0.0%';
    $ordersDeltaPositive = !str_starts_with((string)$ordersDelta, '-');
@endphp

<section aria-label="Key Performance Indicators" class="grid grid-cols-12 gap-3">
    <!-- Wide Tile 1: Gross Revenue -->
    <button type="button"
            @click="openKpiDrawer('grossRevenue')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 sm:p-4 hover:border-border-strong transition-colors col-span-12 md:col-span-6 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Gross Revenue</span>
            <span class="text-[11px] font-medium tabular-nums {{ $netDeltaPositive ? 'text-positive' : 'text-destructive' }}">
                {{ $netDeltaPositive ? '↗' : '↘' }} {{ $netDelta }}
            </span>
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[19px] sm:text-[20px]">
                Rp {{ number_format($grossSales, 0, ',', '.') }}
            </span>
        </div>
        <div class="text-[10.5px] text-muted-foreground mt-0.5 truncate">Incl. PB1 tax &amp; service charge · Click to inspect per-outlet breakdown</div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($grossSpark), options: { strokeColor: 'var(--foreground)' } })">
        </div>
    </button>

    <!-- Wide Tile 2: Net Revenue -->
    <button type="button"
            @click="openKpiDrawer('netRevenue')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 sm:p-4 hover:border-border-strong transition-colors col-span-12 md:col-span-6 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Net Revenue (Accrual)</span>
            <span class="text-[11px] font-medium tabular-nums {{ $netDeltaPositive ? 'text-positive' : 'text-destructive' }}">
                {{ $netDeltaPositive ? '↗' : '↘' }} {{ $netDelta }}
            </span>
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[19px] sm:text-[20px]">
                Rp {{ number_format($netSales, 0, ',', '.') }}
            </span>
        </div>
        <div class="text-[10.5px] text-muted-foreground mt-0.5 truncate">After Rp {{ number_format($discounts, 0, ',', '.') }} discounts · PSAK Accrual</div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($netSpark), options: { strokeColor: 'var(--accent)' } })">
        </div>
    </button>

    <!-- Compact Tile 1: Completed Transactions -->
    <button type="button"
            @click="openKpiDrawer('transactions')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Transactions</span>
            <span class="text-[11px] font-medium tabular-nums {{ $ordersDeltaPositive ? 'text-positive' : 'text-destructive' }}">
                {{ $ordersDeltaPositive ? '↗' : '↘' }} {{ $ordersDelta }}
            </span>
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[18px]">
                {{ number_format($ordersCount, 0, ',', '.') }}
            </span>
            <span class="text-[10.5px] text-muted-foreground">tickets</span>
        </div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($txSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>

    <!-- Compact Tile 2: Average Transaction Value (ATV) -->
    <button type="button"
            @click="openKpiDrawer('atv')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Average Ticket</span>
            <span class="text-[10.5px] font-medium text-muted-foreground">ATV</span>
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[18px]">
                Rp {{ number_format($aov, 0, ',', '.') }}
            </span>
        </div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($atvSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>

    <!-- Compact Tile 3: Gross Profit -->
    <button type="button"
            @click="openKpiDrawer('grossProfit')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Gross Profit</span>
            @if($hasMargin)
                <span class="text-[11px] font-medium tabular-nums text-positive">
                    {{ number_format($grossMarginPct, 1, ',', '.') }}%
                </span>
            @endif
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            @if($hasMargin)
                <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[18px]">
                    Rp {{ number_format($grossProfit, 0, ',', '.') }}
                </span>
            @else
                <span class="font-medium text-muted-foreground text-xs flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">lock</span> Protected
                </span>
            @endif
        </div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($profitSpark), options: { strokeColor: 'var(--accent)' } })">
        </div>
    </button>

    <!-- Compact Tile 4: Active Orders -->
    <button type="button"
            @click="openKpiDrawer('activeOrders')"
            class="text-left rounded-[8px] border border-border bg-card p-3.5 hover:border-border-strong transition-colors col-span-12 sm:col-span-6 xl:col-span-3 cursor-pointer group">
        <div class="flex items-center justify-between">
            <span class="text-[11.5px] text-muted-foreground font-medium">Active Orders</span>
            <span class="inline-flex items-center gap-1 text-[11px] text-positive font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-positive"></span> Live
            </span>
        </div>
        <div class="flex items-baseline gap-2 mt-1">
            <span class="font-semibold tabular-nums tracking-[-0.02em] text-foreground text-[18px]">
                {{ $openOrdersCount ?? 0 }} open
            </span>
        </div>
        <div class="mt-2 h-10 w-full"
             x-data="dashboardChart({ type: 'sparkline', data: @js($activeOrdersSpark), options: { strokeColor: 'var(--muted-foreground)' } })">
        </div>
    </button>
</section>
