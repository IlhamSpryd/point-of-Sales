{{-- ========================================================================= --}}
{{-- SECTION C1: VOLUME & PROFITABILITY (STACKED BAR & DUAL-AXIS COMBO)       --}}
{{-- ========================================================================= --}}
@php
    $pm = $executiveData['payment_methods'] ?? [];
    $pmItems = $pm['items'] ?? [];
    $pmCategories = $pm['categories'] ?? ['QRIS', 'Card / EDC', 'Cash', 'Bank Transfer / Invoice'];
    $pmAmounts = $pm['series'] ?? array_column($pmItems, 'amount');
    $pmTotal = $pm['total_volume'] ?? 0;

    $profCombo = $executiveData['profitability_combo'] ?? [];
    $profLabels = $profCombo['labels'] ?? [];
    $profSeries = $profCombo['gross_profit_series'] ?? [];
    $marginSeries = $profCombo['margin_pct_series'] ?? [];
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);
@endphp

<section aria-label="Volume and Profitability" class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    <!-- Panel 1: Transactions by Payment Method -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between">
        <div>
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-foreground">Transaction Volume by Payment Method</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">Payment settlement breakdown in the active reporting scope</p>
                </div>
                <span class="text-xs font-semibold tabular-nums text-foreground bg-secondary px-2 py-0.5 rounded border border-border">
                    Total: {{ $pm['total_volume_formatted'] ?? 'Rp ' . number_format($pmTotal, 0, ',', '.') }}
                </span>
            </div>

            <!-- Stacked Horizontal Bar Visualization -->
            <div class="space-y-3 mt-4">
                @forelse($pmItems as $item)
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full {{ $item['name'] === 'QRIS' ? 'bg-success' : ($item['name'] === 'Card / EDC' ? 'bg-primary' : ($item['name'] === 'Cash' ? 'bg-warning' : 'bg-muted-foreground')) }}"></span>
                                {{ $item['name'] }}
                            </span>
                            <span class="tabular-nums text-muted-foreground">
                                <strong class="text-foreground font-semibold">{{ $item['amount_formatted'] }}</strong>
                                ({{ $item['share_pct'] }}% · {{ number_format($item['count']) }} tx)
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-secondary overflow-hidden">
                            <div class="h-full rounded-full {{ $item['name'] === 'QRIS' ? 'bg-success' : ($item['name'] === 'Card / EDC' ? 'bg-primary' : ($item['name'] === 'Cash' ? 'bg-warning' : 'bg-muted-foreground')) }}"
                                 style="width: {{ max(1, min(100, (float)$item['share_pct'])) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-xs text-muted-foreground">
                        No payment settlement transactions recorded in this period.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[11px] text-muted-foreground">
            <span>Aggregated via POS payment gateways &amp; cash registers</span>
            <span>MDR &amp; settlement verified</span>
        </div>
    </div>

    <!-- Panel 2: Gross Profit and Margin % Evolution -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between">
        <div>
            <div class="flex items-start justify-between gap-3 mb-3">
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
                         type: 'line',
                         chartId: 'profit-margin-combo',
                         data: {
                             labels: @js($profLabels),
                             series: [
                                 { key: 'gp', name: 'Gross Profit (IDR)', data: @js($profSeries), colorToken: 'var(--chart-accent)' },
                                 { key: 'margin', name: 'Margin %', data: @js($marginSeries), colorToken: 'var(--foreground)', dashed: true }
                             ]
                         },
                         options: {
                             currency: true,
                             height: 220,
                             interactive: true,
                             ariaLabel: 'Gross Profit and Margin Trajectory'
                         }
                     })">
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

