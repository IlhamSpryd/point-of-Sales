{{-- ========================================================================= --}}
{{-- SECTION B: REVENUE ANALYTICS (INTERACTIVE APACHE ECHARTS & CLICK DRILLDOWN) --}}
{{-- ========================================================================= --}}
@php
    $revSeries = $executiveData['revenue_series'] ?? [];
    $activeBranchName = $activeStoreName ?? 'All Outlets';
@endphp

<section aria-label="Revenue Analytics"
         class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4"
         x-data="dashboardEcharts({
             type: 'revenue',
             endpoint: '{{ route('dashboard.api.revenue-series') }}',
             data: @js($revSeries),
             period: '{{ $executiveData['period'] ?? '30d' }}',
             granularity: 'daily',
             chartStyle: 'line',
             storeId: {{ $activeStoreId ?? 'null' }}
         })">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 pb-3 border-b border-border">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Revenue Analytics</h2>
                <span class="text-[10.5px] text-muted-foreground hidden sm:inline">· {{ $activeBranchName }}</span>
            </div>
            <p class="text-[11px] text-muted-foreground mt-0.5">
                Gross vs. net revenue trajectory and period-over-period comparison (klik tanggal untuk rincian)
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Series Toggles (Net Revenue, Prior Period, Target) -->
            <div role="group" aria-label="Toggle series visibility" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                <button type="button"
                        @click="toggleSeries('net')"
                        :class="activeSeries.net ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground opacity-60'"
                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-all cursor-pointer flex items-center gap-1.5"
                        title="Tampilkan / Sembunyikan Net Revenue">
                    <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="activeSeries.net ? 'bg-positive' : 'bg-muted-foreground/40'"></span>
                    <span>Net Revenue</span>
                </button>
                <button type="button"
                        @click="toggleSeries('prior')"
                        :class="activeSeries.prior ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground opacity-60'"
                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-all cursor-pointer flex items-center gap-1.5"
                        title="Tampilkan / Sembunyikan Prior Period">
                    <span class="w-2 h-0.5 shrink-0" :class="activeSeries.prior ? 'bg-muted-foreground' : 'bg-muted-foreground/30'"></span>
                    <span>Prior Period</span>
                </button>
                <button type="button"
                        @click="toggleSeries('target')"
                        :class="activeSeries.target ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground opacity-60'"
                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-all cursor-pointer flex items-center gap-1.5"
                        title="Tampilkan / Sembunyikan Target Penjualan (KPI-PLN-01)">
                    <span class="w-1.5 h-1.5 rounded-full border border-warning shrink-0" :class="activeSeries.target ? 'bg-warning' : 'bg-transparent'"></span>
                    <span>Target</span>
                </button>
            </div>

            <!-- Line / Bar Switcher -->
            <div role="group" aria-label="Chart style" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                <button type="button"
                        @click="setChartStyle('line')"
                        :class="chartStyle === 'line' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer"
                        title="Tampilan Grafik Garis">
                    Line
                </button>
                <button type="button"
                        @click="setChartStyle('bar')"
                        :class="chartStyle === 'bar' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer"
                        title="Tampilan Grafik Batang">
                    Bar
                </button>
            </div>

            <!-- Daily / Weekly / Monthly Switcher -->
            <div role="group" aria-label="Chart granularity" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $gKey => $gLbl)
                    <button type="button"
                            @click="setGranularity('{{ $gKey }}')"
                            :class="granularity === '{{ $gKey }}' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer">
                        {{ $gLbl }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- ECharts Render Canvas -->
    <div class="relative w-full h-[280px] sm:h-[300px] select-none mt-3">
        <div x-show="loading"
             class="absolute inset-0 bg-card/60 backdrop-blur-xs flex items-center justify-center z-10 transition-opacity"
             x-cloak>
            <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                <span class="w-2 h-2 rounded-full bg-accent animate-ping"></span>
                <span>Memuat data analitik...</span>
            </div>
        </div>
        <div x-ref="chartContainer" class="w-full h-full"></div>
    </div>
</section>
