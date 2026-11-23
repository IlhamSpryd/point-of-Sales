{{-- ========================================================================= --}}
{{-- SECTION C1: VOLUME & PROFITABILITY (STACKED BAR & DUAL-AXIS COMBO ECHARTS) --}}
{{-- ========================================================================= --}}
@php
    $pm = $executiveData['payment_methods'] ?? [];
    $pmItems = $pm['items'] ?? [];
    $pmTotal = $pm['total_volume'] ?? 0;
    $stackedBar = $pm['stacked_bar'] ?? [];

    $profCombo = $executiveData['profitability_combo'] ?? [];
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);
@endphp

<section aria-label="Volume and Profitability" class="grid grid-cols-1 xl:grid-cols-2 gap-3">
    <!-- Panel 1: Transactions by Payment Method (Stacked Vertical Bar Chart) -->
    <div class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4 flex flex-col justify-between"
         x-data="dashboardEcharts({
             type: 'stacked-bar',
             drilldownType: 'payment-method',
             endpoint: '{{ route('dashboard.api.payment-volume') }}',
             data: @js($stackedBar),
             period: '{{ $executiveData['period'] ?? '30d' }}',
             metricType: 'revenue',
             storeId: {{ $activeStoreId ?? 'null' }}
         })">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Payment Method Volume</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Daily settlement breakdown (klik batang untuk drilldown kanal)</p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <!-- Period Filter 7D | 14D | 30D -->
                    <div role="group" aria-label="Filter payment period" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                        @foreach(['7d' => '7D', '14d' => '14D', '30d' => '30D'] as $pVal => $pLbl)
                            <button type="button"
                                    @click="setPeriod('{{ $pVal }}')"
                                    :class="period === '{{ $pVal }}' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                    class="px-1.5 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer">
                                {{ $pLbl }}
                            </button>
                        @endforeach
                    </div>

                    <!-- Revenue vs Tx Count Switcher -->
                    <div role="group" aria-label="Toggle metric revenue or count" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                        <button type="button"
                                @click="setMetricType('revenue')"
                                :class="metricType === 'revenue' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                class="px-2 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer"
                                title="Lihat total nilai omzet">
                            Revenue
                        </button>
                        <button type="button"
                                @click="setMetricType('transactions')"
                                :class="metricType === 'transactions' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                class="px-2 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer"
                                title="Lihat frekuensi transaksi">
                            Tx
                        </button>
                    </div>
                </div>
            </div>

            <!-- ECharts Stacked Bar Container -->
            <div class="relative w-full h-[240px] select-none mt-2">
                <div x-show="loading" class="absolute inset-0 bg-card/60 backdrop-blur-xs flex items-center justify-center z-10" x-cloak>
                    <span class="w-2 h-2 rounded-full bg-accent animate-ping"></span>
                </div>
                <div x-ref="chartContainer" class="w-full h-full"></div>
            </div>

            <!-- Payment Method Legend -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2.5 mt-2 border-t border-border text-[11px]">
                @foreach($pmItems as $item)
                    <div class="flex items-center gap-1.5 truncate" title="{{ $item['name'] }}">
                        <span class="w-2 h-2 rounded-full shrink-0 {{ $item['name'] === 'QRIS' ? 'bg-info' : ($item['name'] === 'Card / EDC' ? 'bg-chart-4' : ($item['name'] === 'Cash' ? 'bg-positive' : 'bg-warning')) }}"></span>
                        <span class="font-medium text-foreground truncate max-w-[95px]">{{ $item['name'] === 'Bank Transfer / Invoice' ? 'Transfer/Inv' : $item['name'] }}</span>
                        <span class="text-[10px] text-muted-foreground tabular-nums">({{ $item['share_pct'] }}%)</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-3 pt-2.5 border-t border-border flex items-center justify-between text-[10.5px] text-muted-foreground">
            <span>Aggregated via POS gateways &amp; cash registers</span>
            <span class="tabular-nums font-medium text-foreground">Total: {{ $pm['total_volume_formatted'] ?? 'Rp ' . number_format($pmTotal, 0, ',', '.') }}</span>
        </div>
    </div>

    <!-- Panel 2: Gross Profit and Margin % Evolution (Dual-Axis Combo Chart) -->
    <div class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4 flex flex-col justify-between"
         x-data="dashboardEcharts({
             type: 'profitability',
             drilldownType: 'date',
             endpoint: '{{ route('dashboard.api.profitability-combo') }}',
             data: @js($profCombo),
             period: '{{ $executiveData['period'] ?? '30d' }}',
             granularity: 'daily',
             storeId: {{ $activeStoreId ?? 'null' }}
         })">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Sales &amp; Profit Margin Evolution</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Accrual profit after recipe COGS against blended margin % (klik untuk rincian)</p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    @if($hasMargin)
                        <!-- Period Filter 7D | 14D | 30D -->
                        <div role="group" aria-label="Filter margin period" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                            @foreach(['7d' => '7D', '14d' => '14D', '30d' => '30D'] as $pVal => $pLbl)
                                <button type="button"
                                        @click="setPeriod('{{ $pVal }}')"
                                        :class="period === '{{ $pVal }}' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                        class="px-1.5 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer">
                                    {{ $pLbl }}
                                </button>
                            @endforeach
                        </div>

                        <!-- Granularity Daily | Weekly -->
                        <div role="group" aria-label="Toggle granularity" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                            <button type="button"
                                    @click="setGranularity('daily')"
                                    :class="granularity === 'daily' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                    class="px-2 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer">
                                Daily
                            </button>
                            <button type="button"
                                    @click="setGranularity('weekly')"
                                    :class="granularity === 'weekly' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                    class="px-2 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer">
                                Weekly
                            </button>
                        </div>
                    @else
                        <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                            <span class="material-symbols-rounded text-xs">lock</span> Protected
                        </span>
                    @endif
                </div>
            </div>

            @if($hasMargin && !empty($profCombo['labels'] ?? []))
                <!-- ECharts Dual Axis Combo Container -->
                <div class="relative w-full h-[240px] select-none mt-2">
                    <div x-show="loading" class="absolute inset-0 bg-card/60 backdrop-blur-xs flex items-center justify-center z-10" x-cloak>
                        <span class="w-2 h-2 rounded-full bg-accent animate-ping"></span>
                    </div>
                    <div x-ref="chartContainer" class="w-full h-full"></div>
                </div>

                <!-- Combo Chart Legend -->
                <div class="flex items-center gap-4 pt-2.5 mt-2 border-t border-border text-[11px]">
                    <div class="flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-xs bg-positive"></span>
                        <span class="font-medium text-foreground">Gross Profit (IDR)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-0.5 bg-foreground"></span>
                        <span class="font-medium text-foreground">Margin %</span>
                    </div>
                </div>
            @else
                <div class="py-12 text-center text-xs text-muted-foreground space-y-1">
                    <span class="material-symbols-rounded text-2xl text-muted-foreground/60">analytics</span>
                    <p>{{ $hasMargin ? 'No recipe COGS or transaction movements recorded in this period.' : 'Profit margin metrics require elevated role authorization.' }}</p>
                </div>
            @endif
        </div>

        <div class="mt-3 pt-2.5 border-t border-border text-[10.5px] text-muted-foreground">
            Recipe BOM valuation based on FIFO material cost layers (PSAK compliant).
        </div>
    </div>
</section>
