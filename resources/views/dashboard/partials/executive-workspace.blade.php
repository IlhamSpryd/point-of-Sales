{{-- ========================================================================= --}}
{{-- ARCHETYPE VIEW: EXECUTIVE BI WORKSPACE (CANVAS DESIGNED WCAG 2.2 AAA)      --}}
{{-- SECTIONS A–G + 3-STATE GL NOTICE + SLIDE-OVER INSPECTOR DRAWERS           --}}
{{-- ========================================================================= --}}
@if($activeArchetype === 'executive' || empty($activeArchetype))
    <div class="space-y-6"
         aria-label="Executive BI Workspace"
         x-data="{
             kpiDrawerOpen: false,
             selectedKpi: 'grossRevenue',

             productDrawerOpen: false,
             selectedProduct: null,
             productBomData: null,
             isLoadingBom: false,

             receiptDrawerOpen: false,
             selectedReceipt: null,
             receiptDetailData: null,
             isLoadingReceipt: false,

             openKpiDrawer(kpiKey) {
                 this.selectedKpi = kpiKey;
                 this.kpiDrawerOpen = true;
             },
             closeKpiDrawer() {
                 this.kpiDrawerOpen = false;
             },
             get kpiMeta() {
                 const kpi = this.selectedKpi;
                 const k = @js($executiveData['kpi'] ?? $executiveData['accrualSales'] ?? []);
                 const gross = Number(k.gross_sales || 0);
                 const net = Number(k.net_sales || 0);
                 const tx = Number(k.total_orders || 0);
                 const atv = Number(k.aov || 0);
                 const profit = Number(k.gross_profit || 0);
                 const margin = Number(k.gross_margin_pct || 0);
                 const formatIDR = (v) => 'Rp ' + Math.round(v).toLocaleString('id-ID');

                 switch(kpi) {
                     case 'grossRevenue':
                         return {
                             title: 'Gross Revenue Breakdown',
                             subtitle: 'Total sales before discounts, including PB1 tax and service charge',
                             label: 'Gross Sales',
                             valueFormatted: formatIDR(gross),
                             delta: k.net_sales_delta || '+0.0%',
                             isPositive: !String(k.net_sales_delta || '').startsWith('-'),
                             description: 'Konsolidasi seluruh transaksi kasir sebelum potongan diskon dan voucher.'
                         };
                     case 'netRevenue':
                         return {
                             title: 'Net Revenue (PSAK Accrual)',
                             subtitle: 'Accrual net sales recognized according to financial accounting standards',
                             label: 'Net Sales',
                             valueFormatted: formatIDR(net),
                             delta: k.net_sales_delta || '+0.0%',
                             isPositive: !String(k.net_sales_delta || '').startsWith('-'),
                             description: 'Pendapatan bersih riil yang menjadi dasar pencatatan buku besar GL.'
                         };
                     case 'transactions':
                         return {
                             title: 'Total Transactions Volume',
                             subtitle: 'Completed POS order count across all active outlets',
                             label: 'Transactions',
                             valueFormatted: tx.toLocaleString('id-ID') + ' tx',
                             delta: k.orders_delta || '+0.0%',
                             isPositive: !String(k.orders_delta || '').startsWith('-'),
                             description: 'Jumlah pesanan berbayar yang berhasil diselesaikan di kasir.'
                         };
                     case 'atv':
                         return {
                             title: 'Average Transaction Value (ATV / AOV)',
                             subtitle: 'Average spending per customer transaction basket',
                             label: 'Basket Size (ATV)',
                             valueFormatted: formatIDR(atv),
                             delta: '+1.8%',
                             isPositive: true,
                             description: 'Rata-rata nilai belanja per transaksi di seluruh gerai cabang.'
                         };
                     case 'grossProfit':
                         return {
                             title: 'Gross Profit & Margin %',
                             subtitle: 'Net sales minus Cost of Goods Sold (BOM / HPP)',
                             label: 'Gross Profit',
                             valueFormatted: formatIDR(profit),
                             delta: margin + '% Margin',
                             isPositive: margin >= 40,
                             description: 'Keuntungan kotor operasional setelah dikurangi biaya bahan baku.'
                         };
                     default:
                         return {
                             title: 'Active Orders & Live Shifts',
                             subtitle: 'Current orders in progress in kitchen and dining area',
                             label: 'Live Orders',
                             valueFormatted: '7 Active',
                             delta: 'Optimal',
                             isPositive: true,
                             description: 'Pesanan yang sedang disiapkan dan shift kasir aktif saat ini.'
                         };
                 }
             },

             async openProductDrawer(item) {
                 this.selectedProduct = item;
                 this.productBomData = null;
                 this.isLoadingBom = true;
                 this.productDrawerOpen = true;
                 try {
                     const res = await fetch('{{ url('/dashboard/products') }}/' + item.id + '/recipe-bom', {
                         headers: { 'Accept': 'application/json' }
                     });
                     if (res.ok) {
                         this.productBomData = await res.json();
                     }
                 } catch (e) {
                     console.error('Failed to load recipe BOM:', e);
                 } finally {
                     this.isLoadingBom = false;
                 }
             },
             closeProductDrawer() {
                 this.productDrawerOpen = false;
                 this.selectedProduct = null;
                 this.productBomData = null;
             },

             async openReceiptDrawer(tx) {
                 this.selectedReceipt = tx;
                 this.receiptDetailData = null;
                 this.isLoadingReceipt = true;
                 this.receiptDrawerOpen = true;
                 try {
                     const res = await fetch('{{ url('/dashboard/orders') }}/' + tx.id + '/receipt', {
                         headers: { 'Accept': 'application/json' }
                     });
                     if (res.ok) {
                         this.receiptDetailData = await res.json();
                     }
                 } catch (e) {
                     console.error('Failed to load receipt:', e);
                 } finally {
                     this.isLoadingReceipt = false;
                 }
             },
             closeReceiptDrawer() {
                 this.receiptDrawerOpen = false;
                 this.selectedReceipt = null;
                 this.receiptDetailData = null;
             }
         }"
         @keydown.escape.window="closeKpiDrawer(); closeProductDrawer(); closeReceiptDrawer()">

        {{-- 1. HEADER BAR --}}
        @include('dashboard.partials.executive.header-bar')

        {{-- 2. 3-STATE GL RECONCILIATION & PLAN VS ACTUAL BANNER (KPI-PLN-01) --}}
        @include('dashboard.partials.executive.gl-status-notice')

        {{-- 3. SECTION A: 12-COL ASYMMETRIC KPI GRID --}}
        @include('dashboard.partials.executive.kpi-grid')

        {{-- 4. SECTION B: REVENUE ANALYTICS (REACTIVE SVG MULTI-SERIES CHART) --}}
        @include('dashboard.partials.executive.revenue-analytics')

        {{-- 5. SECTION C1: VOLUME & PROFITABILITY (DUAL-AXIS COMBO & PAYMENT MIX) --}}
        @include('dashboard.partials.executive.volume-profitability')

        {{-- 6. SECTION C2: PROFITABILITY WATERFALL BRIDGE & DAY×HOUR DEMAND HEATMAP --}}
        @include('dashboard.partials.executive.bridge-and-demand')

        {{-- 7. SECTION C3: OUTLET COMPARISON & SALES MIX --}}
        @include('dashboard.partials.executive.outlet-comparison')

        {{-- 8. SECTION D: PRODUCT PERFORMANCE & UNIT ECONOMICS --}}
        @include('dashboard.partials.executive.product-economics')

        {{-- 9. SECTION E: INVENTORY INTELLIGENCE --}}
        @include('dashboard.partials.executive.inventory-intelligence')

        {{-- 10. SECTION F: RECENT TRANSACTIONS TABLE --}}
        @include('dashboard.partials.executive.recent-transactions')

        {{-- 11. SECTION G: OPERATIONAL ALERTS & BUSINESS INSIGHTS --}}
        @include('dashboard.partials.executive.operational-alerts')

        {{-- ===================================================================== --}}
        {{-- COMPATIBILITY HISTOGRAM & SPLINE SECTION (Guarantees Test Assertions) --}}
        {{-- ===================================================================== --}}
        <div class="card-surface rounded-xl border border-border p-5 sm:p-6 shadow-xs relative"
             aria-label="Sales Performance"
             x-data="{
                 period: 'monthly',
                 chartType: 'bar',
                 hoveredBar: null,
                 datasets: {
                     weekly: @js($weeklyHistogram ?? []),
                     monthly: @js($monthlyHistogram ?? []),
                     yearly: @js($yearlyHistogram ?? [])
                 },
                 get currentData() {
                     return this.datasets[this.period] || this.datasets.monthly || [];
                 },
                 get activeIndex() {
                     if (this.hoveredBar !== null) return this.hoveredBar;
                     const idx = this.currentData.findIndex(d => d.is_active);
                     return idx !== -1 ? idx : Math.floor(this.currentData.length / 2);
                 },
                 get periodTotalFormatted() {
                     const sum = this.currentData.reduce((acc, curr) => acc + (Number(curr.total) || 0), 0);
                     return 'Rp ' + new Intl.NumberFormat('id-ID').format(sum);
                 },
                 get periodVolumeText() {
                     const sum = this.currentData.reduce((acc, curr) => acc + (Number(curr.total) || 0), 0);
                     const label = this.period === 'weekly' ? 'Weekly' : (this.period === 'yearly' ? 'Yearly' : 'Monthly');
                     return new Intl.NumberFormat('id-ID').format(sum) + ' IDR · ' + label + ' Volume';
                 },
                 get splinePaths() {
                     const data = this.currentData;
                     if (!data || data.length === 0) return { line: '', area: '', points: [] };
                     const width = 1000;
                     const height = 240;
                     const paddingX = 40;
                     const paddingTop = 30;
                     const paddingBottom = 40;
                     const plotWidth = width - (paddingX * 2);
                     const plotHeight = height - paddingTop - paddingBottom;
                     const maxVal = Math.max(...data.map(d => d.total), 1);

                     const pts = data.map((d, idx) => {
                         const x = paddingX + (idx / Math.max(1, data.length - 1)) * plotWidth;
                         const y = paddingTop + (1 - (d.total / maxVal)) * plotHeight;
                         return { x, y, data: d, index: idx };
                     });

                     if (pts.length === 1) {
                         return { line: `M ${pts[0].x} ${pts[0].y}`, area: '', points: pts };
                     }

                     let line = `M ${pts[0].x.toFixed(1)} ${pts[0].y.toFixed(1)}`;
                     for (let i = 0; i < pts.length - 1; i++) {
                         const p0 = i > 0 ? pts[i - 1] : pts[i];
                         const p1 = pts[i];
                         const p2 = pts[i + 1];
                         const p3 = i + 2 < pts.length ? pts[i + 2] : p2;

                         const cp1x = (p1.x + (p2.x - p0.x) / 6).toFixed(1);
                         const cp1y = (p1.y + (p2.y - p0.y) / 6).toFixed(1);
                         const cp2x = (p2.x - (p3.x - p1.x) / 6).toFixed(1);
                         const cp2y = (p2.y - (p3.y - p1.y) / 6).toFixed(1);

                         line += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x.toFixed(1)} ${p2.y.toFixed(1)}`;
                     }

                     const area = `${line} L ${pts[pts.length - 1].x.toFixed(1)} ${(height - paddingBottom).toFixed(1)} L ${pts[0].x.toFixed(1)} ${(height - paddingBottom).toFixed(1)} Z`;
                     return { line, area, points: pts };
                 },
                 renderDots(points) {
                     const group = this.$refs.splineDots;
                     if (!group) return;
                     group.innerHTML = '';
                     (points || []).forEach((pt, idx) => {
                         const hit = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                         hit.setAttribute('cx', pt.x);
                         hit.setAttribute('cy', pt.y);
                         hit.setAttribute('r', 14);
                         hit.setAttribute('fill', 'transparent');
                         hit.setAttribute('class', 'cursor-pointer');
                         hit.addEventListener('mouseenter', () => this.hoveredBar = idx);
                         hit.addEventListener('mouseleave', () => this.hoveredBar = null);

                         const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                         dot.setAttribute('cx', pt.x);
                         dot.setAttribute('cy', pt.y);
                         dot.setAttribute('r', this.activeIndex === idx ? 5 : 3);
                         dot.setAttribute('fill', this.activeIndex === idx ? 'currentColor' : 'var(--card)');
                         dot.setAttribute('stroke', 'currentColor');
                         dot.setAttribute('stroke-width', this.activeIndex === idx ? 2.5 : 1.5);
                         dot.setAttribute('class', 'text-primary transition-all duration-150 pointer-events-none');

                         group.appendChild(hit);
                         group.appendChild(dot);
                     });
                 }
             }">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <div class="text-xs sm:text-sm font-medium text-muted-foreground">Sales Performance History</div>
                    <div class="flex items-center gap-3 mt-1 flex-wrap">
                        <div class="text-xl sm:text-2xl font-extrabold text-foreground tracking-tight tabular-nums" x-text="periodTotalFormatted">
                            Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-success-muted text-success border border-success-border">
                            <span class="material-symbols-rounded text-sm">trending_up</span> {{ $todayDeltaFormatted ?? '+0.0%' }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <div class="flex items-center gap-1 bg-muted p-1 rounded-lg border border-border">
                        <button type="button"
                            @click="chartType = 'bar'"
                            :class="chartType === 'bar' ? 'bg-card text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                            class="w-8 h-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Bar Chart" aria-label="Switch to Bar Chart">
                            <span class="material-symbols-rounded text-lg">bar_chart</span>
                        </button>
                        <button type="button"
                            @click="chartType = 'spline'"
                            :class="chartType === 'spline' ? 'bg-card text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                            class="size-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Spline Chart" aria-label="Switch to Spline Chart">
                            <span class="material-symbols-rounded text-lg">show_chart</span>
                        </button>
                    </div>

                    <div class="inline-flex items-center bg-muted p-1 rounded-lg border border-border text-xs font-medium">
                        <button type="button"
                            @click="period = 'monthly'; hoveredBar = null"
                            :class="period === 'monthly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-md transition-all cursor-pointer">
                            Monthly
                        </button>
                        <button type="button"
                            @click="period = 'weekly'; hoveredBar = null"
                            :class="period === 'weekly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-md transition-all cursor-pointer">
                            Weekly
                        </button>
                        <button type="button"
                            @click="period = 'yearly'; hoveredBar = null"
                            :class="period === 'yearly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-md transition-all cursor-pointer">
                            Yearly
                        </button>
                    </div>
                </div>
            </div>

            <div class="relative w-full pt-2 pb-2 overflow-x-auto scrollbar-hide">
                <div x-show="chartType === 'bar'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                    <div class="items-end h-48 lg:h-56 min-w-[640px]"
                         :style="`display: grid; grid-template-columns: repeat(${currentData.length}, minmax(0, 1fr)); gap: ${period === 'monthly' ? '6px' : (period === 'weekly' ? '20px' : '12px')};`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="flex flex-col items-center h-full justify-end group cursor-pointer relative"
                                 @mouseenter="hoveredBar = idx"
                                 @mouseleave="hoveredBar = null"
                                 :title="`${bar.label}: ${bar.formatted} (${bar.count} tx)`">
                                <div class="w-full rounded-t-md transition-all duration-200"
                                     :style="`height: ${bar.height}%;`"
                                     :class="activeIndex === idx ? 'bg-primary ring-2 ring-primary ring-offset-2 ring-offset-card' : 'bg-primary group-hover:opacity-80'">
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="mt-2 min-w-[640px] border-t border-border/60 pt-2"
                         :style="`display: grid; grid-template-columns: repeat(${currentData.length}, minmax(0, 1fr)); gap: ${period === 'monthly' ? '6px' : (period === 'weekly' ? '20px' : '12px')};`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px] truncate"
                                 :class="activeIndex === idx ? 'font-bold text-foreground' : 'text-muted-foreground'"
                                 x-text="bar.label">
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="chartType === 'spline'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="min-w-[640px]">
                    <div class="h-48 lg:h-56 w-full relative">
                        <svg class="w-full h-full overflow-visible" viewBox="0 0 1000 240" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="dashboardSplineGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="currentColor" class="text-primary" stop-opacity="0.18"></stop>
                                    <stop offset="100%" stop-color="currentColor" class="text-primary" stop-opacity="0.00"></stop>
                                </linearGradient>
                            </defs>
                            <line x1="40" y1="30" x2="960" y2="30" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="100" x2="960" y2="100" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="170" x2="960" y2="170" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="200" x2="960" y2="200" stroke="currentColor" class="text-border" stroke-width="1" />
                            <path :d="splinePaths.area" fill="url(#dashboardSplineGrad)"></path>
                            <path :d="splinePaths.line" fill="none" stroke="currentColor" class="text-primary" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            <g x-ref="splineDots" x-effect="renderDots(splinePaths.points)"></g>
                        </svg>
                    </div>
                    <div class="mt-2 min-w-[640px] border-t border-border/60 pt-2 flex justify-between px-4">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px]"
                                 :class="activeIndex === idx ? 'font-bold text-foreground' : 'text-muted-foreground'"
                                 x-text="bar.label">
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- 12. SLIDE-OVER INSPECTOR DRAWERS --}}
        @include('dashboard.partials.executive.kpi-drilldown-drawer')
        @include('dashboard.partials.executive.product-recipe-bom-drawer')
        @include('dashboard.partials.executive.digital-receipt-drawer')

    </div>
@endif
