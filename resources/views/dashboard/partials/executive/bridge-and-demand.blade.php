{{-- ========================================================================= --}}
{{-- SECTION C2: PROFITABILITY WATERFALL BRIDGE & DAY×HOUR DEMAND HEATMAP      --}}
{{-- ========================================================================= --}}
@php
    $wf = $executiveData['waterfall_bridge']['steps'] ?? [];
    $hasMargin = !empty($executiveData['has_profit_margin_permission']);
    $grossVal = (float) ($wf[0]['amount'] ?? 1);
    if ($grossVal <= 0) $grossVal = 1;

    $hm = $executiveData['demand_heatmap'] ?? [];
    $cells = $hm['cells'] ?? [];
    $peak = $hm['peak'] ?? ['day' => 'Senin', 'hour' => '12:00', 'formatted' => '0 tx'];
    $quiet = $hm['quiet'] ?? ['day' => 'Minggu', 'hour' => '07:00', 'formatted' => '0 tx'];
    $dayparts = $hm['dayparts'] ?? [];
    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $hours = range(7, 21);
@endphp

<section aria-label="Profitability and Demand" class="grid grid-cols-1 xl:grid-cols-5 gap-4">
    <!-- Panel 1: Waterfall Bridge (2 cols) -->
    <div class="xl:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between">
        <div>
            <div class="mb-3">
                <h3 class="text-sm font-semibold text-foreground">Revenue-to-Gross-Profit Waterfall</h3>
                <p class="text-xs text-muted-foreground mt-0.5">Accrual transition from top-line gross receipts to gross profit</p>
            </div>

            <div class="space-y-2 mt-4">
                @forelse($wf as $step)
                    @php
                        $amt = (float) $step['amount'];
                        $isNegative = $step['type'] === 'deduction';
                        $pct = round((abs($amt) / $grossVal) * 100, 1);
                    @endphp
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full {{ $step['type'] === 'final' ? 'bg-success' : ($isNegative ? 'bg-destructive' : 'bg-foreground') }}"></span>
                                {{ $step['label'] }}
                            </span>
                            <span class="tabular-nums font-semibold {{ $step['type'] === 'final' ? 'text-success' : ($isNegative ? 'text-destructive' : 'text-foreground') }}">
                                {{ $step['amount_formatted'] }}
                                <span class="text-[10px] text-muted-foreground font-normal">({{ $pct }}%)</span>
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-secondary overflow-hidden">
                            <div class="h-full rounded-full {{ $step['type'] === 'final' ? 'bg-success' : ($isNegative ? 'bg-destructive' : 'bg-foreground') }}"
                                 style="width: {{ min(100, max(2, $pct)) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-xs text-muted-foreground">
                        No revenue or deduction records to compute bridge.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-border text-[11px] text-muted-foreground">
            PSAK revenue recognition: Operating expenses (OPEX) not deducted in this view.
        </div>
    </div>

    <!-- Panel 2: Day × Hour Demand Heatmap (3 cols) -->
    <div class="xl:col-span-3 rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col justify-between"
         x-data="{
             hoveredCell: null,
             activeCellText: 'Peak: {{ $peak['day'] }} {{ $peak['hour'] }} ({{ $peak['formatted'] }}) · Quiet: {{ $quiet['day'] }} {{ $quiet['hour'] }}'
         }">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div>
                    <h3 class="text-sm font-semibold text-foreground">Demand by Day and Hour</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">Operating hours (07:00–21:00) traffic matrix aggregated over 30 days</p>
                </div>
                <div class="text-[11px] text-muted-foreground font-medium bg-secondary px-2.5 py-1 rounded border border-border"
                     x-text="activeCellText">
                </div>
            </div>

            <!-- Heatmap Grid (7 Days x 15 Hours) -->
            <div class="overflow-x-auto mt-3">
                <div class="min-w-[560px]">
                    <!-- Hour Axis Header -->
                    <div class="grid grid-cols-[48px_repeat(15,1fr)] gap-1 text-[10px] text-muted-foreground text-center tabular-nums pb-1 mb-1 border-b border-border/40">
                        <span class="text-left font-medium">Day</span>
                        @foreach($hours as $h)
                            <span>{{ sprintf('%02d', $h) }}</span>
                        @endforeach
                    </div>

                    <!-- Day Rows -->
                    @foreach($days as $dIdx => $dName)
                        <div class="grid grid-cols-[48px_repeat(15,1fr)] gap-1 items-center py-0.5">
                            <span class="text-[11px] font-medium text-muted-foreground text-left truncate">{{ substr($dName, 0, 3) }}</span>
                            @foreach($hours as $h)
                                @php
                                    $cell = collect($cells)->first(fn($c) => ($c['day_index'] ?? -1) === $dIdx && ($c['hour'] ?? -1) === $h);
                                    $intensity = (float) ($cell['intensity'] ?? 0);
                                    $val = (float) ($cell['value'] ?? 0);
                                @endphp
                                <div class="h-6 rounded flex items-center justify-center text-[10px] font-medium tabular-nums transition-transform hover:scale-110 cursor-pointer {{ $intensity > 0.5 ? 'text-primary-foreground font-bold' : 'text-foreground' }}"
                                     style="background-color: rgba(16, 163, 127, {{ max(0.08, $intensity) }});"
                                     @mouseenter="activeCellText = '{{ $dName }} {{ sprintf('%02d:00', $h) }}: {{ number_format($val) }} tx'"
                                     @mouseleave="activeCellText = 'Peak: {{ $peak['day'] }} {{ $peak['hour'] }} ({{ $peak['formatted'] }})'"
                                     title="{{ $dName }} {{ sprintf('%02d:00', $h) }}: {{ number_format($val) }} transactions">
                                    @if($val > 0)
                                        {{ number_format($val) }}
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- 4 Dayparts Summary Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-3 border-t border-border">
                @foreach($dayparts as $dpKey => $dpInfo)
                    <div class="p-2 rounded-lg bg-secondary/60 border border-border">
                        <div class="text-[11px] text-muted-foreground font-medium truncate">{{ $dpInfo['label'] }}</div>
                        <div class="text-sm font-bold text-foreground tabular-nums mt-0.5">
                            {{ number_format($dpInfo['transactions'] ?? 0) }} tx
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-border flex items-center justify-between text-[11px] text-muted-foreground">
            <span>Color intensity scales with transaction density</span>
            <span>Peak kitchen hours highlighted</span>
        </div>
    </div>
</section>

