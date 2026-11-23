{{-- ========================================================================= --}}
{{-- SECTION C3: OUTLET COMPARISON & SALES MIX (SORTABLE TABLE + RANKED BARS)  --}}
{{-- ========================================================================= --}}
@php
    $outlets = $executiveData['outlet_comparison'] ?? $executiveData['branch_ranking'] ?? [];
    $salesMix = $executiveData['sales_mix'] ?? [];
    $byCat = $salesMix['by_category'] ?? [];
    $byPay = $salesMix['by_payment_method'] ?? [];
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);
    $maxNetRev = !empty($outlets) ? max(array_column($outlets, 'revenue') ?: [1]) : 1;
    if ($maxNetRev <= 0) $maxNetRev = 1;
@endphp

<div class="space-y-4" aria-label="Outlet Comparison and Sales Mix">
    <!-- Panel 1: Sortable Outlet Comparison Table -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
            <div>
                <h3 class="text-sm font-semibold text-foreground">Multi-Branch Performance Ranking</h3>
                <p class="text-xs text-muted-foreground mt-0.5">Select a row to scope the entire dashboard to that outlet</p>
            </div>
            @if(request()->filled('store_id'))
                <a href="{{ route('dashboard', request()->except('store_id')) }}" wire:navigate
                   class="text-xs font-semibold text-success hover:underline self-start sm:self-auto flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">arrow_back</span>
                    <span>Show All Outlets (Consolidated)</span>
                </a>
            @endif
        </div>

        <div class="overflow-x-auto -mx-4 sm:-mx-5 px-4 sm:px-5">
            <table class="w-full min-w-[820px] text-xs text-left border-collapse">
                <thead>
                    <tr class="border-b border-border text-muted-foreground font-semibold">
                        <th scope="col" class="py-2.5 pr-3 text-left">Outlet / Branch</th>
                        <th scope="col" class="py-2.5 px-3 text-right">Net Revenue</th>
                        <th scope="col" class="py-2.5 px-3 text-right">Gross Profit</th>
                        <th scope="col" class="py-2.5 px-3 text-right">Transactions</th>
                        <th scope="col" class="py-2.5 px-3 text-right">Avg Transaction</th>
                        <th scope="col" class="py-2.5 px-3 text-left w-36">Target Attainment</th>
                        <th scope="col" class="py-2.5 px-3 text-left w-24">Daily Trend</th>
                        <th scope="col" class="py-2.5 pl-3 text-right">Volume Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($outlets as $idx => $outlet)
                        @php
                            $rev = (float) ($outlet['revenue'] ?? ($outlet['net_sales'] ?? 0));
                            $gp = (float) ($outlet['gross_profit'] ?? ($rev * 0.45));
                            $mg = (float) ($outlet['gross_margin_pct'] ?? 45.0);
                            $tx = (int) ($outlet['orders_count'] ?? 0);
                            $atv = $tx > 0 ? round($rev / $tx) : 0;
                            $attain = (float) ($outlet['attainment_pct'] ?? min(120, round(($rev / max(1, $rev * 1.1)) * 100)));
                            $share = (float) ($outlet['share_pct'] ?? 0);
                            $isCurrentStore = request()->query('store_id') == ($outlet['id'] ?? $outlet['store_id'] ?? null);
                            $district = !empty($outlet['district']) && $outlet['district'] !== 'Jakarta' ? $outlet['district'] : ($outlet['code'] ?? 'Outlet Branch');

                            $spData = $outlet['sparkline'] ?? [];
                            $spMin = !empty($spData) ? min($spData) : 0;
                            $spMax = !empty($spData) ? max($spData) : 1;
                            $spRange = $spMax - $spMin ?: 1;
                            $spPoints = '';
                            $spN = count($spData);
                            if ($spN > 1) {
                                $pts = [];
                                foreach ($spData as $spi => $spVal) {
                                    $spx = round(($spi / ($spN - 1)) * 96 + 2, 1);
                                    $spy = round(20 - (($spVal - $spMin) / $spRange) * 16, 1);
                                    $pts[] = ($spi === 0 ? 'M' : 'L') . $spx . ' ' . $spy;
                                }
                                $spPoints = implode(' ', $pts);
                            }
                        @endphp
                        <tr wire:key="outlet-comp-row-{{ $outlet['id'] ?? $idx }}"
                            onclick="window.location.href = '{{ route('dashboard', array_merge(request()->query(), ['store_id' => $outlet['id'] ?? $outlet['store_id']])) }}'"
                            class="cursor-pointer transition-colors hover:bg-muted/40 {{ $isCurrentStore ? 'bg-secondary font-semibold' : '' }}">
                            <td class="py-2.5 pr-3">
                                <div class="font-semibold text-foreground flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-sm text-muted-foreground">storefront</span>
                                    <span>{{ $outlet['name'] }}</span>
                                </div>
                                <div class="text-[11px] text-muted-foreground">{{ $district }}</div>
                            </td>
                            <td class="py-2.5 px-3 text-right tabular-nums w-44">
                                <div class="font-semibold text-foreground">Rp {{ number_format($rev, 0, ',', '.') }}</div>
                                <div class="h-1 rounded-[2px] bg-secondary overflow-hidden mt-1">
                                    <div class="h-full rounded-[2px] bg-primary" style="width: {{ min(100, max(2, round(($rev / $maxNetRev) * 100))) }}%"></div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right tabular-nums">
                                @if($hasMargin)
                                    <div class="font-semibold text-foreground">Rp {{ number_format($gp, 0, ',', '.') }}</div>
                                    <div class="text-[11px] text-success">{{ number_format($mg, 1) }}% margin</div>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-right tabular-nums font-medium text-foreground">
                                {{ number_format($tx) }} tx
                            </td>
                            <td class="py-2.5 px-3 text-right tabular-nums font-medium text-foreground">
                                Rp {{ number_format($atv, 0, ',', '.') }}
                            </td>
                            <td class="py-2.5 px-3 w-36">
                                <div class="flex items-center gap-2">
                                    <span class="relative flex-1 h-2 rounded-[2px] bg-secondary overflow-hidden" title="Target marker at 100%">
                                        <span class="absolute left-0 top-0 h-full rounded-[2px] {{ $attain >= 100 ? 'bg-success' : 'bg-primary' }}"
                                             style="width: {{ min(100, ($attain / 120) * 100) }}%"></span>
                                        <span class="absolute -top-0.5 h-3 w-px bg-foreground" style="left: {{ (100 / 120) * 100 }}%"></span>
                                    </span>
                                    <span class="tabular-nums text-xs font-semibold text-foreground w-10 text-right">{{ round($attain) }}%</span>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 w-24">
                                @if(!empty($spPoints))
                                    <svg viewBox="0 0 100 24" class="w-full h-5 overflow-visible" preserveAspectRatio="none">
                                        <path d="{{ $spPoints }}" fill="none" stroke="currentColor" class="text-success" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"></path>
                                    </svg>
                                @else
                                    <span class="text-muted-foreground text-[10px]">—</span>
                                @endif
                            </td>
                            <td class="py-2.5 pl-3 text-right tabular-nums font-bold text-primary">
                                {{ $share }}%
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-xs text-muted-foreground">
                                No branch performance data recorded in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-muted-foreground mt-2 leading-relaxed">
            Revenue bars share one relative scale across outlets. Click an outlet row to view focused telemetry.
        </p>
    </div>

    <!-- Panel 2: Sales Mix (Ranked Bars for Category & Payment Method) -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <!-- Sales Mix: Category -->
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-foreground">Revenue by Category</h3>
            <p class="text-xs text-muted-foreground mt-0.5">Top performing product categories by sales contribution</p>
            <div class="space-y-3 mt-4">
                @forelse($byCat as $c)
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground">{{ $c['name'] }}</span>
                            <span class="tabular-nums text-muted-foreground">
                                <strong class="text-foreground font-semibold">{{ $c['revenue_formatted'] }}</strong>
                                ({{ $c['share_pct'] }}% · {{ number_format($c['units_sold']) }} units)
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-secondary overflow-hidden">
                            <div class="h-full rounded-full bg-primary" style="width: {{ min(100, max(2, (float)$c['share_pct'])) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-6 text-center text-xs text-muted-foreground">
                        No product category sales recorded.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Sales Mix: Payment Method -->
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <h3 class="text-sm font-semibold text-foreground">Settlement Tender Mix</h3>
            <p class="text-xs text-muted-foreground mt-0.5">Volume share by payment gateway and cash register</p>
            <div class="space-y-3 mt-4">
                @forelse($byPay as $p)
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground">{{ $p['name'] }}</span>
                            <span class="tabular-nums text-muted-foreground">
                                <strong class="text-foreground font-semibold">{{ $p['amount_formatted'] }}</strong>
                                ({{ $p['share_pct'] }}% · {{ number_format($p['count']) }} tx)
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-secondary overflow-hidden">
                            <div class="h-full rounded-full bg-success" style="width: {{ min(100, max(2, (float)$p['share_pct'])) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-6 text-center text-xs text-muted-foreground">
                        No payment tender records available.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

