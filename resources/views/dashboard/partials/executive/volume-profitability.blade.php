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
             storeId: {{ $activeStoreId ?? 'null' }}
         })">
        <div>
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Payment Method Volume</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Daily settlement breakdown (klik batang untuk drilldown kanal)</p>
                </div>
                <div class="text-[11.5px] font-semibold tabular-nums text-foreground">
                    Total: {{ $pm['total_volume_formatted'] ?? 'Rp ' . number_format($pmTotal, 0, ',', '.') }}
                </div>
            </div>

            <!-- ECharts Stacked Bar Container -->
            <div class="relative w-full h-[220px] select-none mt-2">
                <div x-show="loading" class="absolute inset-0 bg-card/60 backdrop-blur-xs flex items-center justify-center z-10" x-cloak>
                    <span class="w-2 h-2 rounded-full bg-accent animate-ping"></span>
                </div>
                <div x-ref="chartContainer" class="w-full h-full"></div>
            </div>

            <!-- Payment Method Legend -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2.5 mt-2 border-t border-border text-[11px]">
                @foreach($pmItems as $item)
                    <div class="flex items-center gap-1.5 truncate">
                        <span class="w-2 h-2 rounded-full shrink-0 {{ $item['name'] === 'QRIS' ? 'bg-[#38BDF8]' : ($item['name'] === 'Card / EDC' ? 'bg-[#A78BFA]' : ($item['name'] === 'Cash' ? 'bg-[#4ADE80]' : 'bg-[#FBBF24]')) }}"></span>
                        <span class="font-medium text-foreground truncate">{{ $item['name'] }}</span>
                        <span class="text-[10px] text-muted-foreground tabular-nums">({{ $item['share_pct'] }}%)</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-3 pt-2.5 border-t border-border flex items-center justify-between text-[10.5px] text-muted-foreground">
            <span>Aggregated via POS gateways &amp; cash registers</span>
            <span>MDR &amp; settlement verified</span>
        </div>
    </div>

    <!-- Panel 2: Gross Profit and Margin % Evolution (Dual-Axis Combo Chart) -->
    <div class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4 flex flex-col justify-between"
         x-data="dashboardEcharts({
             type: 'profitability',
             drilldownType: 'cogs-drivers',
             endpoint: '{{ route('dashboard.api.profitability-combo') }}',
             data: @js($profCombo),
             period: '{{ $executiveData['period'] ?? '30d' }}',
             storeId: {{ $activeStoreId ?? 'null' }}
         })">
        <div>
            <div class="flex items-start justify-between gap-3 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Sales &amp; Profit Margin Evolution</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Accrual profit after recipe COGS against blended margin %</p>
                </div>
                @if(!$hasMargin)
                    <span class="text-[11px] font-medium text-muted-foreground flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs">lock</span> Protected
                    </span>
                @endif
            </div>

            @if($hasMargin && !empty($profCombo['labels'] ?? []))
                <!-- ECharts Dual Axis Combo Container -->
                <div class="relative w-full h-[220px] select-none mt-2">
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
