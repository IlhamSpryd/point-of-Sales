<div class="space-y-6 pb-12">
    {{-- Header & Title --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                    <span class="material-symbols-rounded text-2xl">insights</span>
                </span>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        Business Intelligence & Advanced Analytics
                    </h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Modul 15 — Analitik terintegrasi Penjualan, Persediaan, Buku Besar FIN, dan Pelanggan.
                    </p>
                </div>
            </div>
        </div>

        {{-- Export Action & Feedback --}}
        <div class="flex items-center gap-3">
            @if ($exportFeedback)
                <div class="text-xs px-3 py-1.5 rounded-md bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5 animate-fade-in">
                    <span class="material-symbols-rounded text-sm">check_circle</span>
                    <span>{{ $exportFeedback }}</span>
                </div>
            @endif

            @if ($activeExportTask && $activeExportTask->status->value === 'completed')
                <a href="{{ route('exports.download', $activeExportTask) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white shadow-xs transition-colors">
                    <span class="material-symbols-rounded text-base">download</span>
                    <span>Unduh CSV</span>
                </a>
            @else
                <button wire:click="dispatchExport('{{ $activeTab }}_analytics')"
                    wire:loading.attr="disabled"
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:hover:bg-zinc-200 dark:text-zinc-900 text-white shadow-xs transition-all disabled:opacity-50 cursor-pointer">
                    <span wire:loading.remove wire:target="dispatchExport" class="material-symbols-rounded text-base">file_download</span>
                    <span wire:loading wire:target="dispatchExport" class="material-symbols-rounded text-base animate-spin">progress_activity</span>
                    <span wire:loading.remove wire:target="dispatchExport">Ekspor Laporan (Async)</span>
                    <span wire:loading wire:target="dispatchExport">Menjadwalkan...</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs flex flex-wrap items-center justify-between gap-4">
        {{-- Navigation Tabs --}}
        <div class="flex items-center gap-1 p-1 bg-zinc-100 dark:bg-zinc-900 rounded-lg">
            <button wire:click="setTab('sales')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'sales' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">trending_up</span>
                    <span>Penjualan</span>
                </span>
            </button>
            <button wire:click="setTab('inventory')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'inventory' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">inventory_2</span>
                    <span>Persediaan</span>
                </span>
            </button>
            <button wire:click="setTab('finance')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'finance' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">account_balance</span>
                    <span>Keuangan GL</span>
                </span>
            </button>
            <button wire:click="setTab('customers')" type="button"
                class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-all cursor-pointer {{ $activeTab === 'customers' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">group</span>
                    <span>Pelanggan & CRM</span>
                </span>
            </button>
        </div>

        {{-- Date Presets & Store Filter --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- Store Select --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Cabang:</span>
                <select wire:model.live="selectedStoreId"
                    class="text-xs py-1.5 px-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 focus:ring-1 focus:ring-indigo-500">
                    <option value="">Semua Cabang (Tenant Scope)</option>
                    @foreach ($stores as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Date Presets --}}
            <div class="flex items-center gap-1 bg-zinc-100 dark:bg-zinc-900 p-1 rounded-lg">
                <button wire:click="applyDatePreset('7d')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === '7d' ? 'bg-white dark:bg-zinc-800 font-semibold text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400' }}">7H</button>
                <button wire:click="applyDatePreset('30d')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === '30d' ? 'bg-white dark:bg-zinc-800 font-semibold text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400' }}">30H</button>
                <button wire:click="applyDatePreset('this_month')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === 'this_month' ? 'bg-white dark:bg-zinc-800 font-semibold text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400' }}">Bulan Ini</button>
                <button wire:click="applyDatePreset('last_month')" type="button"
                    class="px-2.5 py-1 text-xs rounded-md {{ $datePreset === 'last_month' ? 'bg-white dark:bg-zinc-800 font-semibold text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400' }}">Bulan Lalu</button>
            </div>

            {{-- Custom Date Inputs --}}
            <div class="flex items-center gap-1">
                <input type="date" wire:model.live="startDate"
                    class="text-xs py-1.5 px-2 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200">
                <span class="text-xs text-zinc-400">s/d</span>
                <input type="date" wire:model.live="endDate"
                    class="text-xs py-1.5 px-2 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200">
            </div>
        </div>
    </div>

    {{-- Loading Skeleton State --}}
    <div wire:loading class="w-full">
        <div class="p-8 bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 flex items-center justify-center gap-3">
            <span class="material-symbols-rounded text-2xl animate-spin text-indigo-600 dark:text-indigo-400">progress_activity</span>
            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Memuat data analitik...</span>
        </div>
    </div>

    {{-- Active Tab Content --}}
    <div wire:loading.remove>
        {{-- ================= TAB 1: PENJUALAN ================= --}}
        @if ($activeTab === 'sales')
            <div class="space-y-6">
                {{-- MoM Growth Metric Cards --}}
                @if (isset($momComparison))
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pendapatan Bulan Berjalan</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $momComparison['current_revenue'], 0, ',', '.') }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                                    {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['revenue_growth_pct'] }}% MoM
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Bulan lalu: Rp {{ number_format((float) $momComparison['previous_revenue'], 0, ',', '.') }}</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Pesanan</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($momComparison['current_orders']) }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['orders_growth_pct'] >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                                    {{ (float) $momComparison['orders_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['orders_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Bulan lalu: {{ number_format($momComparison['previous_orders']) }} pesanan</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Rata-rata Nilai Pesanan (AOV)</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $momComparison['current_aov'], 0, ',', '.') }}</span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['aov_growth_pct'] >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                                    {{ (float) $momComparison['aov_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['aov_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Bulan lalu: Rp {{ number_format((float) $momComparison['previous_aov'], 0, ',', '.') }}</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pertumbuhan Nominal Omzet</span>
                            @php
                                $nominalDiff = bcsub($momComparison['current_revenue'], $momComparison['previous_revenue'], 2);
                            @endphp
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-lg font-bold {{ (float) $nominalDiff >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ (float) $nominalDiff >= 0 ? '+' : '' }}Rp {{ number_format((float) $nominalDiff, 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' }}">
                                    {{ (float) $momComparison['revenue_growth_pct'] >= 0 ? '+' : '' }}{{ $momComparison['revenue_growth_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Periode Acuan: {{ $momComparison['current_period'] }} vs {{ $momComparison['previous_period'] }}</span>
                        </div>
                    </div>
                @endif

                {{-- Top Products & Basket Analysis Tables --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Top 10 SKU --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Top 10 Produk Terlaris</h2>
                            <span class="text-xs text-zinc-400">Rentang Periode</span>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                                        <th class="py-2">Produk</th>
                                        <th class="py-2 text-right">Qty</th>
                                        <th class="py-2 text-right">Pendapatan</th>
                                        <th class="py-2 text-right">Kontribusi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($topProducts ?? [] as $tp)
                                        <tr>
                                            <td class="py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                                <div>{{ $tp['product_name'] }}</div>
                                                <div class="text-[11px] text-zinc-400">{{ $tp['sku'] }} • {{ $tp['category_name'] }}</div>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-zinc-700 dark:text-zinc-300">{{ number_format($tp['quantity_sold']) }}</td>
                                            <td class="py-2.5 text-right font-semibold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $tp['total_revenue'], 0, ',', '.') }}</td>
                                            <td class="py-2.5 text-right font-medium text-indigo-600 dark:text-indigo-400">{{ $tp['contribution_pct'] }}%</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-zinc-400">Tidak ada riwayat penjualan pada periode ini.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Market Basket Analysis (Co-occurrence) --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Market Basket Analysis (Pasangan Produk)</h2>
                            <span class="text-xs text-zinc-400">Cross-Selling</span>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                                        <th class="py-2">Pasangan Item (A + B)</th>
                                        <th class="py-2 text-right">Frekuensi</th>
                                        <th class="py-2 text-right">Confidence</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($basketAnalysis ?? [] as $ba)
                                        <tr>
                                            <td class="py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                                <span>{{ $ba['product_a_name'] }}</span>
                                                <span class="text-zinc-400 mx-1">+</span>
                                                <span>{{ $ba['product_b_name'] }}</span>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-zinc-700 dark:text-zinc-300">{{ number_format($ba['co_occurrence_count']) }} kali</td>
                                            <td class="py-2.5 text-right font-semibold text-indigo-600 dark:text-indigo-400">{{ $ba['confidence_pct'] }}%</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="py-8 text-center text-zinc-400">Belum cukup data keranjang multi-item pada periode ini.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= TAB 2: PERSEDIAAN ================= --}}
        @if ($activeTab === 'inventory')
            <div class="space-y-6">
                {{-- Valuation & Turnover Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Valuasi Persediaan</span>
                        <div class="mt-1">
                            <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) ($valuation['total_valuation'] ?? 0), 0, ',', '.') }}</span>
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">Status: {{ $valuation['reconciliation_status'] ?? 'MATCHED' }} ({{ $valuation['total_items_tracked'] ?? 0 }} SKU)</span>
                    </div>

                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Rasio Perputaran (Turnover Rate)</span>
                        <div class="mt-1 flex items-baseline justify-between">
                            <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ $turnover['turnover_ratio'] ?? '0.00' }}x</span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400">
                                {{ $turnover['velocity_category'] ?? 'HEALTHY' }}
                            </span>
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">DSI: {{ $turnover['days_sales_of_inventory'] ?? '0' }} hari penjualan persediaan</span>
                    </div>

                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Modal Tertanam di Stok Mati</span>
                        <div class="mt-1">
                            <span class="text-xl font-bold text-rose-600 dark:text-rose-400">Rp {{ number_format((float) ($deadStock['total_dead_stock_value'] ?? 0), 0, ',', '.') }}</span>
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">{{ $deadStock['dead_stock_count'] ?? 0 }} item tanpa penjualan > 30 hari</span>
                    </div>
                </div>

                {{-- Stock Aging Buckets & Dead Stock List --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Aging Buckets --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            Distribusi Umur Stok (Stock Aging)
                        </h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($aging['buckets'] ?? [] as $key => $b)
                                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                                    <div>
                                        <div class="font-medium text-xs text-zinc-800 dark:text-zinc-200">{{ $b['label'] }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ $b['item_count'] }} SKU • {{ number_format((float) $b['total_quantity'], 0) }} unit</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-xs text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $b['total_value'], 0, ',', '.') }}</div>
                                        <div class="text-[11px] font-medium text-indigo-600 dark:text-indigo-400">{{ $b['percentage'] }}%</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dead Stock Items --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            Daftar Barang Mengendap (Dead Stock > 30 Hari)
                        </h2>
                        <div class="mt-3 overflow-x-auto max-h-80">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                                        <th class="py-2">Item</th>
                                        <th class="py-2 text-right">Modal Tertanam</th>
                                        <th class="py-2 text-right">Hari Diam</th>
                                        <th class="py-2 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($deadStock['items'] ?? [] as $ds)
                                        <tr>
                                            <td class="py-2 font-medium text-zinc-800 dark:text-zinc-200">
                                                <div>{{ $ds['product_name'] }}</div>
                                                <div class="text-[11px] text-zinc-400">{{ $ds['sku'] }} • Qty: {{ number_format((float) $ds['quantity'], 0) }}</div>
                                            </td>
                                            <td class="py-2 text-right font-semibold text-zinc-900 dark:text-zinc-100">
                                                Rp {{ number_format((float) $ds['tied_up_capital'], 0, ',', '.') }}
                                            </td>
                                            <td class="py-2 text-right text-rose-600 dark:text-rose-400 font-medium">
                                                {{ $ds['days_without_sale'] }} hari
                                            </td>
                                            <td class="py-2 text-right">
                                                <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                    {{ $ds['recommended_action'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-zinc-400">Luar biasa! Tidak ada stok mati yang terdeteksi.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= TAB 3: KEUANGAN GL ================= --}}
        @if ($activeTab === 'finance')
            <div class="space-y-6">
                @if (isset($plSummary))
                    {{-- Executive P&L Cards --}}
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Pendapatan (Revenue)</span>
                            <div class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">
                                Rp {{ number_format((float) $plSummary['revenue'], 0, ',', '.') }}
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Buku Besar Akun 4xxx</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Harga Pokok Penjualan (HPP)</span>
                            <div class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">
                                Rp {{ number_format((float) $plSummary['cogs'], 0, ',', '.') }}
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Buku Besar Akun 5xxx</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Laba Kotor (Gross Profit)</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-xl font-bold text-emerald-600 dark:text-emerald-400">
                                    Rp {{ number_format((float) $plSummary['gross_profit'], 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                    {{ $plSummary['gross_margin_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Margin Kotor Penjualan</span>
                        </div>

                        <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Laba Bersih (Net Profit)</span>
                            <div class="mt-1 flex items-baseline justify-between">
                                <span class="text-xl font-bold text-indigo-600 dark:text-indigo-400">
                                    Rp {{ number_format((float) $plSummary['net_profit'], 0, ',', '.') }}
                                </span>
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400">
                                    {{ $plSummary['net_margin_pct'] }}%
                                </span>
                            </div>
                            <span class="text-[11px] text-zinc-400 mt-1 block">Setelah OpEx & Pendapatan Lain</span>
                        </div>
                    </div>

                    {{-- P&L Breakdown Table --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            Rincian Pos Akun Buku Besar (Posting Terkonfirmasi)
                        </h2>
                        <div class="mt-4 space-y-4">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-2">Komponen Pendapatan</h3>
                                <div class="space-y-1">
                                    @forelse ($plSummary['revenue_breakdown'] as $rb)
                                        <div class="flex items-center justify-between py-1.5 px-3 rounded-md bg-zinc-50 dark:bg-zinc-900/40 text-xs">
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $rb['account_code'] }} - {{ $rb['account_name'] }}</span>
                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $rb['amount'], 0, ',', '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="text-xs text-zinc-400 py-2">Belum ada transaksi pendapatan terposting.</div>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-2">Komponen Beban Operasional (OpEx)</h3>
                                <div class="space-y-1">
                                    @forelse ($plSummary['expense_breakdown'] as $eb)
                                        <div class="flex items-center justify-between py-1.5 px-3 rounded-md bg-zinc-50 dark:bg-zinc-900/40 text-xs">
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $eb['account_code'] }} - {{ $eb['account_name'] }}</span>
                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $eb['amount'], 0, ',', '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="text-xs text-zinc-400 py-2">Tidak ada beban operasional tercatat pada periode ini.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ================= TAB 4: PELANGGAN & CRM ================= --}}
        @if ($activeTab === 'customers')
            <div class="space-y-6">
                {{-- CLV & Loyalty Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Pelanggan Terlacak</span>
                        <div class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">
                            {{ number_format($clvSummary['total_tracked_customers'] ?? 0) }} orang
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">Pelanggan teridentifikasi transaksi</span>
                    </div>

                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Rata-rata Nilai Seumur Hidup (CLV)</span>
                        <div class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">
                            Rp {{ number_format((float) ($clvSummary['average_clv'] ?? 0), 0, ',', '.') }}
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">Rata-rata {{ $clvSummary['average_order_frequency'] ?? '0' }}x belanja</span>
                    </div>

                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Poin Loyalitas Diperoleh</span>
                        <div class="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400">
                            +{{ number_format((float) ($loyaltyMovement['points_earned'] ?? 0), 0) }}
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">Audit buku besar loyalty_ledger</span>
                    </div>

                    <div class="bg-white dark:bg-card p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Poin Ditukarkan (Redeemed)</span>
                        <div class="mt-1 text-xl font-bold text-amber-600 dark:text-amber-400">
                            -{{ number_format((float) ($loyaltyMovement['points_redeemed'] ?? 0), 0) }}
                        </div>
                        <span class="text-[11px] text-zinc-400 mt-1 block">Net delta: {{ $loyaltyMovement['net_points_change'] ?? '0' }} poin</span>
                    </div>
                </div>

                {{-- RFM Segments & Top Customers --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- RFM Segments --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            Segmentasi Pelanggan RFM (Recency, Frequency, Monetary)
                        </h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($rfm['segments'] ?? [] as $key => $seg)
                                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                                    <div>
                                        <div class="font-medium text-xs text-zinc-800 dark:text-zinc-200">{{ $seg['segment_name'] }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ $seg['customer_count'] }} pelanggan</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-xs text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $seg['total_revenue'], 0, ',', '.') }}</div>
                                        <div class="text-[11px] font-medium text-indigo-600 dark:text-indigo-400">{{ $seg['percentage'] }}%</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Top Customers --}}
                    <div class="bg-white dark:bg-card rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs p-5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                            Top 10 Pelanggan Paling Loyal
                        </h2>
                        <div class="mt-3 overflow-x-auto max-h-80">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                                        <th class="py-2">Pelanggan</th>
                                        <th class="py-2 text-right">Frekuensi</th>
                                        <th class="py-2 text-right">Total Belanja</th>
                                        <th class="py-2 text-right">AOV</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($clvSummary['top_customers'] ?? [] as $tc)
                                        <tr>
                                            <td class="py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                                <div>{{ $tc['name'] }}</div>
                                                <div class="text-[11px] text-zinc-400">{{ $tc['phone'] }}</div>
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-zinc-700 dark:text-zinc-300">{{ $tc['order_count'] }}x</td>
                                            <td class="py-2.5 text-right font-semibold text-zinc-900 dark:text-zinc-100">
                                                Rp {{ number_format((float) $tc['total_spend'], 0, ',', '.') }}
                                            </td>
                                            <td class="py-2.5 text-right font-medium text-indigo-600 dark:text-indigo-400">
                                                Rp {{ number_format((float) $tc['average_order_value'], 0, ',', '.') }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-zinc-400">Belum ada transaksi pelanggan terdata.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
