{{-- ========================================================================= --}}
{{-- SECTION B: REVENUE ANALYTICS (ACCESSIBLE REACTIVE SVG MULTI-SERIES CHART) --}}
{{-- ========================================================================= --}}
@php
    $revSeries = $executiveData['revenue_series'] ?? [];
    $seriesData = $revSeries['data'] ?? [];
    $activeBranchName = $activeStoreName ?? 'All Outlets';
    $targetRev = (float) ($executiveData['planVsActual']['target_revenue'] ?? 0);
    $chartLabels = array_column($seriesData, 'label');
    $grossPoints = array_column($seriesData, 'gross');
    $netPoints = array_column($seriesData, 'net');
    $prevPoints = array_column($seriesData, 'prev');
    $targetPoints = array_fill(0, count($seriesData), $targetRev > 0 ? $targetRev : 0);
@endphp

<section aria-label="Revenue Analytics" class="rounded-xl border border-border bg-card p-4 sm:p-5">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-border">
        <div>
            <h2 class="text-sm sm:text-base font-semibold text-foreground">Revenue Analytics</h2>
            <p class="text-xs text-muted-foreground mt-0.5">
                Gross vs. net revenue trajectory and period-over-period comparison ({{ $activeBranchName }})
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2" x-data="{ showNet: true, showPrev: true, granularity: 'daily' }">
            <!-- Net Revenue Series Toggle -->
            <button type="button"
                    @click="showNet = !showNet; $dispatch('toggle-chart-series', { series: 'net', visible: showNet })"
                    class="h-7 px-2.5 rounded-md border text-xs font-medium inline-flex items-center gap-1.5 transition-colors cursor-pointer"
                    :class="showNet ? 'bg-secondary border-border-strong text-foreground font-semibold' : 'border-border text-muted-foreground'">
                <span class="w-2.5 h-0.5 bg-success rounded-full"></span>
                <span>Net Revenue</span>
            </button>

            <!-- Previous Period Series Toggle -->
            <button type="button"
                    @click="showPrev = !showPrev; $dispatch('toggle-chart-series', { series: 'prev', visible: showPrev })"
                    class="h-7 px-2.5 rounded-md border text-xs font-medium inline-flex items-center gap-1.5 transition-colors cursor-pointer"
                    :class="showPrev ? 'bg-secondary border-border-strong text-foreground font-semibold' : 'border-border text-muted-foreground'">
                <span class="w-2.5 h-0.5 border-t border-dashed border-muted-foreground"></span>
                <span>Previous Period</span>
            </button>

            <!-- Daily / Weekly / Monthly Switcher -->
            <div role="group" aria-label="Chart granularity" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $gKey => $gLbl)
                    <button type="button"
                            @click="granularity = '{{ $gKey }}'; $dispatch('set-chart-granularity', { granularity: '{{ $gKey }}' })"
                            :class="granularity === '{{ $gKey }}' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-0.5 rounded text-xs font-medium transition-colors cursor-pointer">
                        {{ $gLbl }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Interactive SVG Chart Canvas (dashboardCharts.js engine) -->
    <div id="revenue-chart-wrapper" class="relative w-full h-[320px] select-none mt-4"
         x-data="dashboardChart({
             type: 'line',
             chartId: 'revenue-analytics',
             data: {
                 labels: @js($chartLabels),
                 series: [
                     { key: 'gross', name: 'Gross Revenue', data: @js($grossPoints), colorToken: 'var(--foreground)' },
                     { key: 'net', name: 'Net Revenue', data: @js($netPoints), colorToken: 'var(--chart-accent)' },
                     { key: 'prev', name: 'Prior Period', data: @js($prevPoints), colorToken: 'var(--muted-foreground)', dashed: true },
                     { key: 'target', name: 'Target (KPI-PLN-01)', data: @js($targetPoints), colorToken: 'var(--warning)', dotted: true }
                 ]
             },
             options: {
                 currency: true,
                 height: 320,
                 interactive: true,
                 ariaLabel: 'Consolidated Revenue Analytics chart for {{ $activeBranchName }}',
                 onDrillDown: (idx) => {
                     const tableEl = document.querySelector('[aria-label=\'Recent Transactions\']');
                     if (tableEl) tableEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                 }
             }
         })">
    </div>
</section>

