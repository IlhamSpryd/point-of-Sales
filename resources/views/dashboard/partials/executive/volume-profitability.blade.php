{{-- ========================================================================= --}}
{{-- SECTION C1: VOLUME & PROFITABILITY (STACKED BAR & DUAL-AXIS COMBO)       --}}
{{-- ========================================================================= --}}
@php
    $pm = $executiveData['payment_methods'] ?? [];
    $pmItems = $pm['items'] ?? [];
    $pmTotal = $pm['total_volume'] ?? 0;
    $stackedBar = $pm['stacked_bar'] ?? [];
    $stackedLabels = $stackedBar['labels'] ?? [];
    $stackedSeries = $stackedBar['series'] ?? [];

    $profCombo = $executiveData['profitability_combo'] ?? [];
    $profLabels = $profCombo['labels'] ?? [];
    $profSeries = $profCombo['gross_profit_series'] ?? [];
    $marginSeries = $profCombo['margin_pct_series'] ?? [];
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);
@endphp

<section aria-label="Volume and Profitability" class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    <!-- Panel 1: Transactions by Payment Method (Stacked Vertical Bar Chart) -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between">
        <div>
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <h3 class="text-sm font-semibold text-foreground">Transaction Volume by Payment Method</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">Payment settlement breakdown per day across reporting scope</p>
                </div>
                <span class="text-xs font-semibold tabular-nums text-foreground bg-secondary px-2 py-0.5 rounded border border-border">
                    Total: {{ $pm['total_volume_formatted'] ?? 'Rp ' . number_format($pmTotal, 0, ',', '.') }}
                </span>
            </div>

            <!-- Stacked Vertical Bar Chart (dashboardCharts.js) -->
            <div class="relative w-full h-[220px] select-none mt-2"
                 x-data="dashboardChart({
                     type: 'stacked-bar',
                     chartId: 'payment-volume-stacked',
                     data: {
                         labels: @js($stackedLabels),
                         series: @js($stackedSeries)
                     },
                     options: {
                         currency: true,
                         height: 220,
                         interactive: true,
                         ariaLabel: 'Transaction volume by payment method over 30 days'
                     }
                 })">
            </div>

            <!-- Payment Method Legend Indicators -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-3 mt-2 border-t border-border/60 text-xs">
                @foreach($pmItems as $item)
                    <div class="flex items-center gap-1.5 truncate">
                        <span class="w-2.5 h-2.5 rounded-[2px] shrink-0 {{ $item['name'] === 'QRIS' ? 'bg-success' : ($item['name'] === 'Card / EDC' ? 'bg-primary' : ($item['name'] === 'Cash' ? 'bg-warning' : 'bg-muted-foreground')) }}"></span>
                        <span class="font-medium text-foreground truncate">{{ $item['name'] }}</span>
                        <span class="text-[11px] text-muted-foreground tabular-nums">({{ $item['share_pct'] }}%)</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[11px] text-muted-foreground">
            <span>Aggregated via POS payment gateways &amp; cash registers</span>
            <span>MDR &amp; settlement verified</span>
        </div>
    </div>

    <!-- Panel 2: Gross Profit and Margin % Evolution (Dual-Axis Combo Chart) -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between">
        <div>
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <h3 class="text-sm font-semibold text-foreground">Sales &amp; Profit Margin Evolution</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">Accrual profit after recipe COGS against blended margin percentage</p>
                </div>
                @if(!$hasMargin)
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-muted text-muted-foreground border border-border flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs">lock</span> Protected
                    </span>
                @endif
            </div>

            @if($hasMargin && !empty($profLabels))
                <div class="relative w-full h-[220px] select-none mt-2"
                     x-data="dashboardChart({
                         type: 'combo',
                         chartId: 'profit-margin-combo',
                         data: {
                             labels: @js($profLabels),
                             barSeries: { name: 'Gross Profit (IDR)', values: @js($profSeries), data: @js($profSeries), colorToken: 'var(--chart-accent)' },
                             lineSeries: { name: 'Margin %', values: @js($marginSeries), data: @js($marginSeries), colorToken: 'var(--foreground)' }
                         },
                         options: {
                             currency: true,
                             height: 220,
                             interactive: true,
                             ariaLabel: 'Gross Profit and Margin Trajectory'
                         }
                     })">
                </div>

                <!-- Combo Chart Legend -->
                <div class="flex items-center gap-4 pt-3 mt-2 border-t border-border/60 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-[2px] bg-success"></span>
                        <span class="font-medium text-foreground">Gross Profit (Left Axis)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-0.5 bg-foreground"></span>
                        <span class="font-medium text-foreground">Margin % (Right Axis)</span>
                    </div>
                </div>
            @else
                <div class="py-12 text-center text-xs text-muted-foreground space-y-1">
                    <span class="material-symbols-rounded text-2xl text-muted-foreground/60">analytics</span>
                    <p>{{ $hasMargin ? 'No recipe COGS or transaction movements recorded in this period.' : 'Profit margin metrics require elevated role authorization.' }}</p>
                </div>
            @endif
        </div>

        <div class="mt-4 pt-3 border-t border-border text-[11px] text-muted-foreground">
            Recipe BOM valuation based on FIFO material cost layers (PSAK compliant).
        </div>
    </div>
</section>

