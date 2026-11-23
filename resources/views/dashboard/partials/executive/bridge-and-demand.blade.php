{{-- ========================================================================= --}}
{{-- SECTION C2: PROFITABILITY WATERFALL BRIDGE & DAY×HOUR DEMAND HEATMAP      --}}
{{-- ========================================================================= --}}
@php
    $wf = $executiveData['waterfall_bridge']['steps'] ?? [];
    $grossVal = (float) ($wf[0]['amount'] ?? 1);
    if ($grossVal <= 0) $grossVal = 1;

    $hm = $executiveData['demand_heatmap'] ?? [];
    $cells = $hm['cells'] ?? [];
    $peak = $hm['peak'] ?? $hm['peak_hour'] ?? ['day' => 'Senin', 'hour' => '12:00', 'formatted' => '0 tx'];
    $quiet = $hm['quiet'] ?? $hm['quietest_hour'] ?? ['day' => 'Minggu', 'hour' => '07:00', 'formatted' => '0 tx'];
    $dayparts = $hm['dayparts'] ?? [];
    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $hours = range(7, 21);
@endphp

<section aria-label="Profitability and Demand" class="grid grid-cols-1 xl:grid-cols-5 gap-3">
    <!-- Panel 1: Waterfall Bridge (2 cols) -->
    <div class="xl:col-span-2 rounded-[10px] border border-border bg-card p-3.5 sm:p-4 flex flex-col justify-between"
         x-data="{
             period: '{{ $executiveData['period'] ?? '30d' }}',
             steps: @js($wf),
             loading: false,
             get grossVal() {
                 const first = (this.steps && this.steps[0]) ? Number(this.steps[0].amount) : 1;
                 return first > 0 ? first : 1;
             },
             async setPeriod(p) {
                 this.period = p;
                 this.loading = true;
                 try {
                     const url = new URL('{{ route('dashboard.api.waterfall-bridge') }}', window.location.origin);
                     url.searchParams.set('period', p);
                     @if(request()->filled('store_id')) url.searchParams.set('store_id', '{{ request()->query('store_id') }}'); @endif
                     const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                     if (res.ok) {
                         const json = await res.json();
                         this.steps = json.steps || [];
                     }
                 } catch (e) {
                     console.error('Failed to update waterfall:', e);
                 } finally {
                     this.loading = false;
                 }
             }
         }">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Revenue-to-Gross-Profit Waterfall</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Accrual transition from gross receipts to gross profit (klik baris untuk rincian)</p>
                </div>
                <!-- Inline Period Switcher (Today | 7D | 14D | 30D) -->
                <div role="group" aria-label="Filter waterfall period" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border shrink-0">
                    @foreach(['today' => 'Today', '7d' => '7D', '14d' => '14D', '30d' => '30D'] as $pVal => $pLbl)
                        <button type="button"
                                @click="setPeriod('{{ $pVal }}')"
                                :class="period === '{{ $pVal }}' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                class="px-1.5 py-0.5 rounded text-[10.5px] font-medium transition-colors cursor-pointer">
                            {{ $pLbl }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="space-y-2 mt-3 relative min-h-[160px]">
                <div x-show="loading" class="absolute inset-0 bg-card/60 backdrop-blur-xs flex items-center justify-center z-10" x-cloak>
                    <span class="w-2 h-2 rounded-full bg-accent animate-ping"></span>
                </div>
                <template x-for="(step, idx) in steps" :key="step.label || idx">
                    <button type="button"
                            @click="$dispatch('open-dashboard-drilldown', { drilldownType: 'waterfall-step', step: step.label })"
                            class="w-full text-left space-y-1 p-1.5 -mx-1.5 rounded-md hover:bg-secondary/60 transition-colors cursor-pointer group">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full"
                                      :class="step.type === 'final' ? 'bg-positive' : (step.type === 'deduction' ? 'bg-destructive' : 'bg-foreground')"></span>
                                <span x-text="step.label"></span>
                            </span>
                            <span class="tabular-nums font-semibold"
                                  :class="step.type === 'final' ? 'text-positive' : (step.type === 'deduction' ? 'text-destructive' : 'text-foreground')">
                                <span x-text="step.amount_formatted"></span>
                                <span class="text-[10px] text-muted-foreground font-normal"
                                      x-text="'(' + Math.round((Math.abs(Number(step.amount) || 0) / grossVal) * 1000) / 10 + '%)'"></span>
                            </span>
                        </div>
                        <div class="h-1.5 rounded-full bg-secondary overflow-hidden">
                            <div class="h-full rounded-full"
                                 :class="step.type === 'final' ? 'bg-positive' : (step.type === 'deduction' ? 'bg-destructive' : 'bg-foreground')"
                                 :style="'width: ' + Math.min(100, Math.max(2, Math.round((Math.abs(Number(step.amount) || 0) / grossVal) * 100))) + '%'"></div>
                        </div>
                    </button>
                </template>
                <div x-show="!loading && (!steps || steps.length === 0)" class="py-8 text-center text-xs text-muted-foreground" x-cloak>
                    No revenue or deduction records to compute bridge.
                </div>
            </div>
        </div>

        <div class="mt-3 pt-2.5 border-t border-border text-[10.5px] text-muted-foreground">
            PSAK revenue recognition: OPEX not deducted in this operational view.
        </div>
    </div>

    <!-- Panel 2: Day × Hour Demand Heatmap (3 cols) -->
    <div class="xl:col-span-3 rounded-[10px] border border-border bg-card p-3.5 sm:p-4 flex flex-col justify-between"
         x-data="{
             metric: 'transactions',
             hoveredCell: null,
             activeCellText: 'Peak: {{ $peak['day'] }} {{ $peak['hour'] }} ({{ $peak['formatted'] }}) · Quiet: {{ $quiet['day'] }} {{ $quiet['hour'] }} ({{ $quiet['formatted'] }})'
         }">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div>
                    <h3 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Demand by Day and Hour (WIB)</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">Traffic density 07:00–21:00 (klik sel untuk rincian tiket jam tersebut)</p>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Toggle Metric: Transactions vs Net Revenue -->
                    <div role="group" aria-label="Heatmap Metric" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
                        <button type="button"
                                @click="metric = 'transactions'"
                                :class="metric === 'transactions' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer">
                            Transactions
                        </button>
                        <button type="button"
                                @click="metric = 'revenue'"
                                :class="metric === 'revenue' ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer">
                            Revenue
                        </button>
                    </div>

                    <!-- Active telemetry hover feedback -->
                    <div class="text-[11px] font-medium text-foreground bg-secondary px-2 py-0.5 rounded border border-border truncate max-w-[220px]"
                         x-text="activeCellText"
                         title="Active Traffic Telemetry">
                    </div>
                </div>
            </div>

            <!-- Heatmap Grid (7 Days x 15 Hours) -->
            <div class="overflow-x-auto mt-2">
                <div class="min-w-[540px]">
                    <!-- Hour Axis Header -->
                    <div class="grid grid-cols-[40px_repeat(15,1fr)] gap-1 text-[10px] text-muted-foreground text-center tabular-nums pb-1 mb-1 border-b border-border/40">
                        <span class="text-left font-medium">Day</span>
                        @foreach($hours as $h)
                            <span>{{ sprintf('%02d', $h) }}</span>
                        @endforeach
                    </div>

                    <!-- Day Rows -->
                    @foreach($days as $dIdx => $dName)
                        <div class="grid grid-cols-[40px_repeat(15,1fr)] gap-1 items-center py-0.5">
                            <span class="text-[10.5px] font-medium text-muted-foreground text-left truncate">{{ substr($dName, 0, 3) }}</span>
                            @foreach($hours as $h)
                                @php
                                    $cell = collect($cells)->first(fn($c) => ($c['day_index'] ?? -1) === $dIdx && ($c['hour'] ?? -1) === $h);
                                    $intensity = (float) ($cell['intensity'] ?? 0);
                                    $txVal = (float) ($cell['value'] ?? 0);
                                    $revVal = (float) ($cell['revenue'] ?? 0);
                                    $revFormatted = 'Rp ' . number_format($revVal, 0, ',', '.');
                                    $dayNum = ($dIdx + 1) % 7; // Convert to 0=Sun..6=Sat for query
                                @endphp
                                <button type="button"
                                     class="h-5.5 rounded flex items-center justify-center text-[9.5px] font-medium tabular-nums transition-transform hover:scale-110 cursor-pointer {{ $intensity > 0.5 ? 'text-primary-foreground font-bold' : 'text-foreground' }}"
                                     style="background-color: rgba(16, 163, 127, {{ max(0.08, $intensity) }});"
                                     @click="$dispatch('open-dashboard-drilldown', { drilldownType: 'hourly', day_of_week: {{ $dayNum }}, hour: {{ $h }}, day_name: '{{ $dName }}', time_label: '{{ sprintf('%02d:00', $h) }} WIB' })"
                                     @mouseenter="activeCellText = metric === 'revenue' ? '{{ $dName }} {{ sprintf('%02d:00', $h) }}: {{ $revFormatted }}' : '{{ $dName }} {{ sprintf('%02d:00', $h) }}: {{ number_format($txVal) }} tx'"
                                     @mouseleave="activeCellText = 'Peak: {{ $peak['day'] }} {{ $peak['hour'] }} ({{ $peak['formatted'] }})'"
                                     title="{{ $dName }} {{ sprintf('%02d:00', $h) }} WIB: {{ number_format($txVal) }} tx · {{ $revFormatted }}">
                                    <span x-show="metric === 'transactions'">
                                        {{ $txVal > 0 ? number_format($txVal) : '' }}
                                    </span>
                                    <span x-show="metric === 'revenue'" class="truncate px-0.5">
                                        @if($revVal >= 1000000)
                                            {{ round($revVal / 1000000, 1) }}M
                                        @elseif($revVal >= 1000)
                                            {{ round($revVal / 1000) }}k
                                        @elseif($revVal > 0)
                                            {{ round($revVal) }}
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- 4 Dayparts Summary Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3 pt-2.5 border-t border-border">
                @foreach($dayparts as $dpKey => $dpInfo)
                    <div class="p-2 rounded-[6px] bg-secondary border border-border">
                        <div class="text-[10.5px] text-muted-foreground font-medium truncate">{{ $dpInfo['label'] }}</div>
                        <div class="text-xs font-bold text-foreground tabular-nums mt-0.5">
                            <span x-show="metric === 'transactions'">{{ number_format($dpInfo['transactions'] ?? 0) }} tx</span>
                            <span x-show="metric === 'revenue'">{{ $dpInfo['revenue_formatted'] ?? 'Rp 0' }}</span>
                            <span class="text-[10px] text-muted-foreground font-normal">({{ $dpInfo['share_pct'] }}%)</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-3 pt-2.5 border-t border-border flex items-center justify-between text-[10.5px] text-muted-foreground">
            <span>Color intensity scales with transaction density</span>
            <span>Peak kitchen hours highlighted (WIB)</span>
        </div>
    </div>
</section>
