<div>
    @if ($isOpen && $simulation)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-4xl w-full shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden transform transition-all">
                {{-- Modal Header --}}
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between bg-slate-50 dark:bg-slate-800/50">
                    <div class="flex items-center space-x-3">
                        <div class="p-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">Simulasi Dampak Marjin Kotor</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Analisis prediktif kelayakan finansial promosi sebelum dipublikasikan</p>
                        </div>
                    </div>
                    <button wire:click="close" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Status Alert Guard --}}
                @if ($simulation->hasNegativeMarginItems || $simulation->isOverallNegativeMargin)
                    <div class="px-6 py-3 bg-rose-50 dark:bg-rose-950/40 border-b border-rose-200 dark:border-rose-900/50 flex items-center space-x-3 text-rose-800 dark:text-rose-200 text-sm">
                        <svg class="w-5 h-5 flex-shrink-0 text-rose-600 dark:text-rose-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <span>
                            <strong>Peringatan Marjin Negatif:</strong> Promosi ini menghasilkan marjin negatif pada produk tertentu atau secara agregat.
                            @if ($canPublish)
                                <span class="text-emerald-700 dark:text-emerald-400 font-semibold">(Otorisasi Owner Aktif: Publikasi Diizinkan)</span>
                            @else
                                <span class="font-bold text-rose-700 dark:text-rose-300">Publikasi diblokir kecuali disetujui Owner.</span>
                            @endif
                        </span>
                    </div>
                @endif

                {{-- KPI Metric Summary Cards --}}
                <div class="p-6 grid grid-cols-2 md:grid-cols-4 gap-4 bg-slate-50/50 dark:bg-slate-900/30">
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
                        <span class="text-xs text-slate-500 font-medium">Estimasi Pendapatan</span>
                        <div class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1">Rp {{ number_format($simulation->proposedRevenue, 0, ',', '.') }}</div>
                        <span class="text-xs text-slate-400">Baseline: Rp {{ number_format($simulation->baselineRevenue, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
                        <span class="text-xs text-slate-500 font-medium">Estimasi HPP (COGS)</span>
                        <div class="text-lg font-bold text-slate-800 dark:text-slate-100 mt-1">Rp {{ number_format($simulation->proposedCogs, 0, ',', '.') }}</div>
                        <span class="text-xs text-slate-400">Fixed Inventory Cost</span>
                    </div>
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
                        <span class="text-xs text-slate-500 font-medium">Estimasi Laba Kotor</span>
                        <div class="text-lg font-bold @if($simulation->proposedGrossProfit < 0) text-rose-600 @else text-emerald-600 @endif mt-1">
                            Rp {{ number_format($simulation->proposedGrossProfit, 0, ',', '.') }}
                        </div>
                        <span class="text-xs @if($simulation->profitVariance < 0) text-rose-500 @else text-emerald-500 @endif">
                            Var: Rp {{ number_format($simulation->profitVariance, 0, ',', '.') }}
                        </span>
                    </div>
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm">
                        <span class="text-xs text-slate-500 font-medium">Gross Margin %</span>
                        <div class="text-lg font-bold @if($simulation->proposedMarginPercent < 0) text-rose-600 @else text-emerald-600 @endif mt-1">
                            {{ number_format($simulation->proposedMarginPercent, 1) }}%
                        </div>
                        <span class="text-xs text-slate-400">Baseline: {{ number_format($simulation->baselineMarginPercent, 1) }}%</span>
                    </div>
                </div>

                {{-- Table Details --}}
                <div class="p-6 overflow-x-auto max-h-80">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                            <tr>
                                <th class="py-2">Produk</th>
                                <th class="py-2 text-right">Harga Normal</th>
                                <th class="py-2 text-right">Diskon Unit</th>
                                <th class="py-2 text-right">Harga Promo</th>
                                <th class="py-2 text-right">HPP</th>
                                <th class="py-2 text-right">Margin %</th>
                                <th class="py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($simulation->itemSimulations as $item)
                                <tr class="@if($item['is_negative_margin']) bg-rose-50/50 dark:bg-rose-950/20 @endif">
                                    <td class="py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $item['product_name'] }}</td>
                                    <td class="py-2.5 text-right text-slate-600 dark:text-slate-400">Rp {{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                                    <td class="py-2.5 text-right text-amber-600 font-medium">-Rp {{ number_format($item['discount_amount'], 0, ',', '.') }}</td>
                                    <td class="py-2.5 text-right font-semibold text-slate-800 dark:text-slate-200">Rp {{ number_format($item['discounted_price'], 0, ',', '.') }}</td>
                                    <td class="py-2.5 text-right text-slate-600 dark:text-slate-400">Rp {{ number_format($item['standard_cost'], 0, ',', '.') }}</td>
                                    <td class="py-2.5 text-right font-bold @if($item['is_negative_margin']) text-rose-600 @else text-emerald-600 @endif">
                                        {{ number_format($item['proposed_margin_percent'], 1) }}%
                                    </td>
                                    <td class="py-2.5 text-center">
                                        @if ($item['is_negative_margin'])
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">RUGI</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">AMAN</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Modal Footer --}}
                <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between bg-slate-50 dark:bg-slate-800/50">
                    <span class="text-xs text-slate-400">Discount Exposure: <strong>Rp {{ number_format($simulation->discountExposure, 0, ',', '.') }}</strong></span>
                    <button wire:click="close" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-medium rounded-xl text-sm transition">
                        Tutup Simulasi
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
