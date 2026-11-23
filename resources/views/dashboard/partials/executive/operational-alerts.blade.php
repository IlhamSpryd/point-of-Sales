{{-- ========================================================================= --}}
{{-- SECTION G: OPERATIONAL ALERTS & BUSINESS INSIGHTS CARDS                   --}}
{{-- ========================================================================= --}}
@php
    $alerts = $executiveData['operational_alerts'] ?? [];
    $pva = $executiveData['planVsActual'] ?? [];
    $attainPct = (float) ($pva['attainment_pct'] ?? 0);
    $actualRev = (float) ($pva['actual_revenue'] ?? ($pva['actual_net_sales'] ?? 0));
    $targetRev = (float) ($pva['target_revenue'] ?? ($pva['target_net_sales'] ?? 0));

    $invSummary = $executiveData['inventory_intelligence']['summary'] ?? [];
    $lowStock = (int) ($invSummary['low_stock_count'] ?? 0);
    $outOfStock = (int) ($invSummary['out_of_stock_count'] ?? 0);
    $totalSkus = (int) ($invSummary['total_skus'] ?? 0);
@endphp

<section aria-label="Operational Alerts and Business Insights" class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-sm sm:text-base font-semibold text-foreground">Operational Alerts &amp; Business Insights</h2>
            <p class="text-xs text-muted-foreground mt-0.5">
                Real-time exceptions, inventory risks, and strategic attainment metrics.
            </p>
        </div>
        <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ count($alerts) > 0 ? 'bg-warning-muted text-warning border border-warning-border' : 'bg-success-muted text-success border border-success-border' }}">
            {{ count($alerts) > 0 ? count($alerts) . ' Action Items' : 'All Systems Normal' }}
        </span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {{-- Section G1: Real-time Operational Exception Alerts --}}
        @forelse($alerts as $alert)
            @php
                $isCritical = ($alert['type'] ?? '') === 'critical';
                $badgeClass = $isCritical 
                    ? 'bg-destructive-muted text-destructive border-destructive-border' 
                    : 'bg-warning-muted text-warning border-warning-border';
                $icon = match($alert['id'] ?? '') {
                    'alert_stockout' => 'inventory_2',
                    'alert_shift' => 'schedule',
                    'alert_void' => 'verified_user',
                    default => 'warning'
                };
            @endphp
            <div wire:key="alert-card-{{ $alert['id'] }}"
                 class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between hover:border-border-strong transition-colors">
                <div class="space-y-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-lg {{ $isCritical ? 'text-destructive' : 'text-warning' }}">{{ $icon }}</span>
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-md border {{ $badgeClass }}">
                                {{ $alert['metric_badge'] ?? ($isCritical ? 'Critical' : 'Warning') }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs sm:text-sm font-bold text-foreground">
                            {{ $alert['title'] }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                            {{ $alert['description'] }}
                        </p>
                    </div>
                </div>

                @if(!empty($alert['action_url']))
                    <div class="pt-4 mt-3 border-t border-border/60">
                        <a href="{{ $alert['action_url'] }}" wire:navigate
                           class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
                            <span>{{ $alert['action_label'] ?? 'Resolve Issue' }}</span>
                            <span class="material-symbols-rounded text-sm">arrow_forward</span>
                        </a>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex items-center gap-3 col-span-1 md:col-span-2 lg:col-span-1">
                <span class="material-symbols-rounded text-success text-2xl">check_circle</span>
                <div>
                    <h3 class="text-xs font-bold text-foreground">Operational Checklist Clear</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">No open cashier exceptions or stockouts recorded.</p>
                </div>
            </div>
        @endforelse

        {{-- Section G2: Strategic Attainment Insight Card --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between hover:border-border-strong transition-colors">
            <div class="space-y-2.5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Target Attainment</span>
                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-md border {{ $attainPct >= 100 ? 'bg-success-muted text-success border-success-border' : 'bg-primary/10 text-primary border-primary/20' }}">
                        {{ $attainPct }}%
                    </span>
                </div>
                <div>
                    <div class="text-lg sm:text-xl font-bold text-foreground tabular-nums">
                        Rp {{ number_format($actualRev, 0, ',', '.') }}
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5 tabular-nums">
                        of Monthly Target Rp {{ number_format($targetRev, 0, ',', '.') }}
                    </div>
                </div>
                <div class="w-full bg-muted rounded-full h-2 overflow-hidden">
                    <div class="bg-primary h-full transition-all duration-300 rounded-full" style="width: {{ min(100, $attainPct) }}%"></div>
                </div>
            </div>

            <div class="pt-4 mt-3 border-t border-border/60 flex items-center justify-between">
                <span class="text-[11px] text-muted-foreground">Store Targets (KPI-PLN-01)</span>
                @if(auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false) || auth()->user()?->hasPermission('*') || auth()->user()?->hasPermission('stores.manage'))
                    <button type="button"
                            @click="openStoreTargetModal()"
                            class="text-xs font-semibold text-primary hover:underline cursor-pointer">
                        Atur Target
                    </button>
                @endif
            </div>
        </div>

        {{-- Section G3: Inventory Health Insight Card --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between hover:border-border-strong transition-colors">
            <div class="space-y-2.5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Inventory Health</span>
                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-md border {{ ($outOfStock + $lowStock) > 0 ? 'bg-warning-muted text-warning border-warning-border' : 'bg-success-muted text-success border-success-border' }}">
                        {{ $totalSkus > 0 ? number_format(max(0, 100 - (($outOfStock + $lowStock) / max(1, $totalSkus) * 100)), 1) : 100 }}% Safe
                    </span>
                </div>
                <div>
                    <div class="text-lg sm:text-xl font-bold text-foreground tabular-nums">
                        {{ max(0, $totalSkus - ($outOfStock + $lowStock)) }} / {{ $totalSkus }} SKUs
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5 tabular-nums">
                        {{ $outOfStock }} stockout, {{ $lowStock }} low stock across monitored warehouses
                    </div>
                </div>
                <div class="w-full bg-muted rounded-full h-2 overflow-hidden flex">
                    <div class="bg-primary h-full transition-all duration-300" style="width: {{ $totalSkus > 0 ? max(0, 100 - (($outOfStock + $lowStock) / max(1, $totalSkus) * 100)) : 100 }}%"></div>
                    <div class="bg-destructive h-full transition-all duration-300" style="width: {{ $totalSkus > 0 ? ($outOfStock / max(1, $totalSkus) * 100) : 0 }}%"></div>
                </div>
            </div>

            <div class="pt-4 mt-3 border-t border-border/60 flex items-center justify-between">
                <span class="text-[11px] text-muted-foreground">Inventory Ledger</span>
                <a href="{{ route('inventory.ingredients') }}" wire:navigate
                   class="text-xs font-semibold text-primary hover:underline">
                    Manage Stock
                </a>
            </div>
        </div>
    </div>
</section>

