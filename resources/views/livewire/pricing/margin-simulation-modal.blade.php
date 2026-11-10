<div>
    @if ($isOpen && $simulation)
        <x-ui.modal :open="true" maxWidth="4xl" onClose="$wire.close()">
            <x-slot:title>
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-primary/10 text-primary rounded-lg">
                        <span class="material-symbols-rounded text-xl">show_chart</span>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-foreground">Gross Margin Impact Simulation</h3>
                        <p class="text-xs text-muted-foreground">Predictive financial feasibility analysis prior to promotion publishing</p>
                    </div>
                </div>
            </x-slot:title>

            <div class="space-y-4">
                {{-- Status Alert Guard --}}
                @if ($simulation->hasNegativeMarginItems || $simulation->isOverallNegativeMargin)
                    <div class="p-3 bg-destructive/10 border border-destructive/20 rounded-xl flex items-center space-x-3 text-destructive text-sm">
                        <span class="material-symbols-rounded text-lg shrink-0">warning</span>
                        <span>
                            <strong>Negative Margin Warning:</strong> This promotion yields negative margins on specific items or in aggregate.
                            @if ($canPublish)
                                <span class="text-emerald-600 dark:text-emerald-400 font-semibold">(Owner Authorized: Publishing Permitted)</span>
                            @else
                                <span class="font-bold text-destructive">Publishing blocked unless approved by Owner.</span>
                            @endif
                        </span>
                    </div>
                @endif

                {{-- KPI Metric Summary Cards --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-muted/40 border border-border">
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs text-muted-foreground font-medium">Estimated Revenue</span>
                        <div class="text-lg font-bold text-foreground mt-1">Rp {{ number_format($simulation->proposedRevenue, 0, ',', '.') }}</div>
                        <span class="text-2xs text-muted-foreground">Baseline: Rp {{ number_format($simulation->baselineRevenue, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs text-muted-foreground font-medium">Estimated COGS</span>
                        <div class="text-lg font-bold text-foreground mt-1">Rp {{ number_format($simulation->proposedCogs, 0, ',', '.') }}</div>
                        <span class="text-2xs text-muted-foreground">Fixed Inventory Cost</span>
                    </div>
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs text-muted-foreground font-medium">Estimated Gross Profit</span>
                        <div class="text-lg font-bold @if($simulation->proposedGrossProfit < 0) text-destructive @else text-emerald-600 dark:text-emerald-400 @endif mt-1">
                            Rp {{ number_format($simulation->proposedGrossProfit, 0, ',', '.') }}
                        </div>
                        <span class="text-2xs @if($simulation->profitVariance < 0) text-destructive @else text-emerald-600 dark:text-emerald-400 @endif">
                            Var: Rp {{ number_format($simulation->profitVariance, 0, ',', '.') }}
                        </span>
                    </div>
                    <div class="bg-card p-4 rounded-xl border border-border shadow-xs">
                        <span class="text-xs text-muted-foreground font-medium">Gross Margin %</span>
                        <div class="text-lg font-bold @if($simulation->proposedMarginPercent < 0) text-destructive @else text-emerald-600 dark:text-emerald-400 @endif mt-1">
                            {{ number_format($simulation->proposedMarginPercent, 1) }}%
                        </div>
                        <span class="text-2xs text-muted-foreground">Baseline: {{ number_format($simulation->baselineMarginPercent, 1) }}%</span>
                    </div>
                </div>

                {{-- Table Details --}}
                <div class="overflow-x-auto max-h-80 border border-border rounded-xl">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 text-muted-foreground uppercase border-b border-border">
                            <tr>
                                <th class="px-3 py-2">Product</th>
                                <th class="px-3 py-2 text-right">Regular Price</th>
                                <th class="px-3 py-2 text-right">Unit Discount</th>
                                <th class="px-3 py-2 text-right">Promo Price</th>
                                <th class="px-3 py-2 text-right">COGS</th>
                                <th class="px-3 py-2 text-right">Margin %</th>
                                <th class="px-3 py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($simulation->itemSimulations as $item)
                                <tr class="@if($item['is_negative_margin']) bg-destructive/5 @endif">
                                    <td class="px-3 py-2.5 font-medium text-foreground">{{ $item['product_name'] }}</td>
                                    <td class="px-3 py-2.5 text-right text-muted-foreground">Rp {{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2.5 text-right text-amber-600 dark:text-amber-400 font-medium">-Rp {{ number_format($item['discount_amount'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold text-foreground">Rp {{ number_format($item['discounted_price'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2.5 text-right text-muted-foreground">Rp {{ number_format($item['standard_cost'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2.5 text-right font-bold @if($item['is_negative_margin']) text-destructive @else text-emerald-600 dark:text-emerald-400 @endif">
                                        {{ number_format($item['proposed_margin_percent'], 1) }}%
                                    </td>
                                    <td class="px-3 py-2.5 text-center">
                                        @if ($item['is_negative_margin'])
                                            <span class="px-2 py-0.5 rounded-full text-2xs font-bold bg-destructive/10 text-destructive">NEGATIVE</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-2xs font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">PROFITABLE</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex items-center justify-between w-full">
                    <span class="text-xs text-muted-foreground">Discount Exposure: <strong class="text-foreground">Rp {{ number_format($simulation->discountExposure, 0, ',', '.') }}</strong></span>
                    <button type="button" wire:click="close" class="px-4 py-2 bg-muted hover:bg-muted/80 text-foreground font-medium rounded-xl text-xs transition-colors">
                        Close Simulation
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>