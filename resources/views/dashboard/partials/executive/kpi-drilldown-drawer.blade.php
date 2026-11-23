{{-- ========================================================================= --}}
{{-- SLIDE-OVER INSPECTOR: KPI DRILL-DOWN DRAWER                               --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="kpiDrawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="kpi-drawer-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="kpiDrawerOpen"
         x-transition:enter="ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeKpiDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div x-show="kpiDrawerOpen"
             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md lg:max-w-lg bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Header --}}
            <div class="p-5 sm:p-6 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-primary text-2xl">analytics</span>
                    <div>
                        <h2 class="text-base font-bold text-foreground" id="kpi-drawer-title" x-text="kpiMeta.title">
                            KPI Metric Drill-Down
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5" x-text="kpiMeta.subtitle">
                            Branch and channel distribution analysis
                        </p>
                    </div>
                </div>
                <button type="button"
                        @click="closeKpiDrawer()"
                        aria-label="Close drilldown drawer"
                        class="rounded-lg p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-5 sm:p-6 flex-1 overflow-y-auto space-y-6">
                {{-- KPI Highlight Box --}}
                <div class="p-4 rounded-xl bg-secondary border border-border space-y-2">
                    <div class="text-xs font-semibold uppercase tracking-wider text-muted-foreground" x-text="kpiMeta.label"></div>
                    <div class="flex items-baseline gap-3">
                        <span class="text-2xl sm:text-3xl font-extrabold text-foreground tabular-nums" x-text="kpiMeta.valueFormatted"></span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full"
                              :class="kpiMeta.isPositive ? 'bg-success-muted text-success border border-success-border' : 'bg-destructive-muted text-destructive border border-destructive-border'"
                              x-text="kpiMeta.delta"></span>
                    </div>
                    <p class="text-xs text-muted-foreground" x-text="kpiMeta.description"></p>
                </div>

                {{-- Multi-Branch Distribution Breakdown --}}
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Outlet Contribution Ranking</h3>
                        <span class="text-[11px] text-muted-foreground">Consolidated Share</span>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th scope="col" class="py-2.5 px-3">Branch</th>
                                    <th scope="col" class="py-2.5 px-3 text-right">Transactions</th>
                                    <th scope="col" class="py-2.5 px-3 text-right">Revenue</th>
                                    <th scope="col" class="py-2.5 px-3 text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse($executiveData['branchRanking'] ?? [] as $b)
                                    <tr wire:key="kpi-branch-{{ $b['name'] ?? $loop->index }}" class="hover:bg-muted/30">
                                        <td class="py-2.5 px-3 font-semibold text-foreground flex items-center gap-1.5">
                                            <span class="material-symbols-rounded text-sm text-primary">store</span>
                                            <span>{{ $b['name'] }}</span>
                                        </td>
                                        <td class="py-2.5 px-3 text-right tabular-nums text-muted-foreground">
                                            {{ number_format($b['orders_count'] ?? 0) }}
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-bold tabular-nums text-foreground">
                                            Rp {{ number_format($b['revenue'] ?? ($b['net_sales'] ?? 0), 0, ',', '.') }}
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-semibold tabular-nums text-primary">
                                            {{ $b['share_pct'] ?? 0 }}%
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-4 text-center text-xs text-muted-foreground">
                                            No branch breakdown available.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Payment Method Breakdown --}}
                <div class="space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Tender Channel Breakdown</h3>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($executiveData['payment_methods']['items'] ?? [] as $pm)
                            <div wire:key="kpi-pm-{{ $pm['method'] ?? $loop->index }}" class="p-3 rounded-lg bg-secondary border border-border">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="font-semibold text-foreground">{{ $pm['method'] }}</span>
                                    <span class="text-xs font-bold text-primary tabular-nums">{{ $pm['share_pct'] ?? 0 }}%</span>
                                </div>
                                <div class="text-xs font-bold text-foreground tabular-nums">
                                    Rp {{ number_format($pm['amount'] ?? 0, 0, ',', '.') }}
                                </div>
                                <div class="text-[10px] text-muted-foreground tabular-nums mt-0.5">
                                    {{ number_format($pm['count'] ?? 0) }} tx
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="p-4 border-t border-border bg-secondary flex justify-end">
                <button type="button"
                        @click="closeKpiDrawer()"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-foreground bg-card hover:bg-muted border border-border shadow-xs transition-colors cursor-pointer">
                    Close Inspector
                </button>
            </div>
        </div>
    </div>
</div>

