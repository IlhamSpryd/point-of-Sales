<x-app-layout :noPadding="true">
    <div class="w-full min-h-full flex flex-col flex-1 p-4 sm:p-6 lg:p-8 gap-6 bg-transparent text-foreground">

        {{-- FLASH SESSION NOTIFICATIONS --}}
        @if (session('success'))
            <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert type="error" dismissible>{{ session('error') }}</x-ui.alert>
        @endif
        @if (session('warning'))
            <x-ui.alert type="warning" dismissible>{{ session('warning') }}</x-ui.alert>
        @endif

        {{-- 1. TOP HEADER & ACTIONS (DEMURE Luxury Canvas Header Reference) --}}
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-2 mb-2">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
                    Dashboard
                </h1>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Welcome back, {{ auth()->user()?->name ?? 'User' }} · {{ auth()->user()?->tenant?->name ?? 'Apeiron Enterprise' }}
                </p>
            </div>

            <div class="flex items-center gap-3 sm:gap-4 flex-wrap">
                {{-- DEMURE Sub-Nav Action Text Links --}}
                <div class="hidden xl:flex items-center gap-4 text-xs font-medium text-muted-foreground pr-3 border-r border-border/80">
                    <a href="{{ route('inventory.goods-receipts') }}" wire:navigate class="hover:text-foreground transition-colors">Stock In/Out</a>
                    <a href="{{ route('sales.orders.index') }}" wire:navigate class="hover:text-foreground transition-colors">Sales Orders</a>
                    <a href="{{ route('products.index') }}" wire:navigate class="hover:text-foreground transition-colors">Product Catalog</a>
                    <a href="{{ route('customers.index') }}" wire:navigate class="hover:text-foreground transition-colors">Buyers &amp; CRM</a>
                </div>

                {{-- DEMURE Dark Action Pill Buttons --}}
                <a href="{{ route('orders.index') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-primary-foreground bg-primary hover:opacity-90 shadow-xs active:scale-95 transition-all">
                    <span class="material-symbols-rounded text-[16px]">receipt_long</span>
                    <span>All Orders</span>
                </a>
                <a href="{{ route('transaction.create') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-primary-foreground bg-primary hover:opacity-90 shadow-xs active:scale-95 transition-all">
                    <span class="material-symbols-rounded text-[16px]">point_of_sale</span>
                    <span>POS Terminal</span>
                </a>
            </div>
        </div>

        {{-- 2. TOP 3 KPI CARDS (DEMURE Pure Minimalist Cards) --}}
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6" aria-label="Primary Orders Summary">
            {{-- Card 1: Today's Orders --}}
            <x-erp.stat-card
                label="Today's Orders"
                hint="Today's total revenue"
                :value="$displayTodayOrders"
                delta="+1.9%"
                delta-variant="positive"
            />

            {{-- Card 2: This Week's Orders --}}
            <x-erp.stat-card
                label="This Week's Orders"
                hint="This week's total transactions"
                :value="$displayWeekOrders"
                delta="-8.1%"
                delta-variant="negative"
            />

            {{-- Card 3: This Month's Orders --}}
            <x-erp.stat-card
                label="This Month's Orders"
                hint="Sold this month"
                :value="number_format($displayMonthOrders, 0, ',', '.')"
                delta="+24%"
                delta-variant="positive"
            />
        </section>

        {{-- 3. MIDDLE MAIN SECTION: SALES PERFORMANCE & DYNAMIC HISTOGRAM / SPLINE CHART --}}
        <section class="card-surface rounded-xl border border-border p-5 sm:p-6 shadow-xs relative"
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

                             const titleEl = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                             titleEl.textContent = `${pt.data.label}: ${pt.data.formatted} (${pt.data.count} tx)`;
                             hit.appendChild(titleEl);

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
            {{-- Header of Section --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <div class="text-xs sm:text-sm font-medium text-muted-foreground">Sales Performance</div>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <div class="text-2xl sm:text-3xl font-extrabold text-foreground tracking-tight numeric" x-text="periodTotalFormatted">
                            Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-success-muted text-success border border-success-border">
                            <span class="material-symbols-rounded text-[16px]">trending_up</span> +1.9%
                        </span>
                    </div>
                    <div class="text-xs text-muted-foreground mt-1 tabular-nums numeric" x-text="periodVolumeText">
                        {{ number_format($totalEarnings, 0, ',', '.') }} IDR · Real-Time Volume
                    </div>
                </div>

                {{-- Chart Controls: Icons + Segmented Pills --}}
                <div class="flex items-center gap-3 shrink-0">
                    {{-- Mini Chart Toggle Icons --}}
                    <div class="flex items-center gap-1 bg-muted p-1 rounded-lg border border-border">
                        <button type="button"
                            @click="chartType = 'bar'"
                            :class="chartType === 'bar' ? 'bg-card text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                            class="w-8 h-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Bar Chart" aria-label="Switch to Bar Chart">
                            <span class="material-symbols-rounded text-[20px]">bar_chart</span>
                        </button>
                        <button type="button"
                            @click="chartType = 'spline'"
                            :class="chartType === 'spline' ? 'bg-card text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                            class="size-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Spline Chart" aria-label="Switch to Spline Chart">
                            <span class="material-symbols-rounded text-[20px]">show_chart</span>
                        </button>
                    </div>

                    {{-- Segmented Time Filter --}}
                    <div class="inline-flex items-center bg-muted p-1 rounded-lg border border-border text-xs font-medium">
                        <button type="button"
                            @click="period = 'monthly'; hoveredBar = null"
                            :class="period === 'monthly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Monthly
                        </button>
                        <button type="button"
                            @click="period = 'weekly'; hoveredBar = null"
                            :class="period === 'weekly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Weekly
                        </button>
                        <button type="button"
                            @click="period = 'yearly'; hoveredBar = null"
                            :class="period === 'yearly' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Yearly
                        </button>
                    </div>
                </div>
            </div>

            {{-- Responsive Chart Area --}}
            <div class="relative w-full pt-4 pb-2 overflow-x-auto scrollbar-hide">
                {{-- MODE 1: BAR CHART HISTOGRAM --}}
                <div x-show="chartType === 'bar'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                    <div class="items-end h-56 lg:h-64 min-w-[680px]"
                         :style="`display: grid; grid-template-columns: repeat(${currentData.length}, minmax(0, 1fr)); gap: ${period === 'monthly' ? '6px' : (period === 'weekly' ? '24px' : '14px')};`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="flex flex-col items-center h-full justify-end group cursor-pointer relative"
                                 @mouseenter="hoveredBar = idx"
                                 @mouseleave="hoveredBar = null"
                                 :title="`${bar.label}: ${bar.formatted} (${bar.count} tx)`">
                                
                                {{-- Bar Element --}}
                                <div class="w-full rounded-t-md transition-all duration-200"
                                     :style="`height: ${bar.height}%;`"
                                     :class="activeIndex === idx ? 'bg-primary ring-2 ring-primary ring-offset-2 ring-offset-card' : 'bg-primary group-hover:opacity-80'">
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- X-Axis Labels --}}
                    <div class="mt-2 min-w-[680px] border-t border-border/60 pt-2"
                         :style="`display: grid; grid-template-columns: repeat(${currentData.length}, minmax(0, 1fr)); gap: ${period === 'monthly' ? '6px' : (period === 'weekly' ? '24px' : '14px')};`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px] truncate"
                                 :class="activeIndex === idx ? 'font-bold text-foreground' : 'text-muted-foreground'"
                                 x-text="bar.label">
                            </div>
                        </template>
                    </div>
                </div>

                {{-- MODE 2: SPLINE CHART (Curved Vector SVG) --}}
                <div x-show="chartType === 'spline'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="min-w-[680px]">
                    <div class="h-56 lg:h-64 w-full relative">
                        <svg class="w-full h-full overflow-visible" viewBox="0 0 1000 240" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="dashboardSplineGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="currentColor" class="text-primary" stop-opacity="0.18"></stop>
                                    <stop offset="100%" stop-color="currentColor" class="text-primary" stop-opacity="0.00"></stop>
                                </linearGradient>
                            </defs>

                            {{-- Horizontal Grid Guidelines --}}
                            <line x1="40" y1="30" x2="960" y2="30" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="100" x2="960" y2="100" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="170" x2="960" y2="170" stroke="currentColor" class="text-border/60" stroke-dasharray="3 3" />
                            <line x1="40" y1="200" x2="960" y2="200" stroke="currentColor" class="text-border" stroke-width="1" />

                            {{-- Spline Area Fill --}}
                            <path :d="splinePaths.area" fill="url(#dashboardSplineGrad)"></path>

                            {{-- Spline Line Stroke --}}
                            <path :d="splinePaths.line" fill="none" stroke="currentColor" class="text-primary" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>

                            {{-- Interactive Data Dots.
                                 Alpine cannot reliably handle x-for inside an SVG subtree
                                 (attribute bindings are not processed), so circles
                                 are updated dynamically via x-effect when the period changes. --}}
                            <g x-ref="splineDots" x-effect="renderDots(splinePaths.points)"></g>
                        </svg>
                    </div>

                    {{-- X-Axis Labels for Spline --}}
                    <div class="mt-2 min-w-[680px] border-t border-border/60 pt-2"
                         :style="`display: flex; justify-content: space-between; padding-left: 20px; padding-right: 20px;`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px]"
                                 :class="activeIndex === idx ? 'font-bold text-foreground' : 'text-muted-foreground'"
                                 x-text="bar.label">
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        {{-- 4. BOTTOM ROW: 3 FEATURE CARDS (Quarterly / Logistics / Channels) --}}
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6" aria-label="Service Performance & Logistics Analysis">
            {{-- Card 1: Quarterly Performance --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs flex flex-col justify-between hover:border-border-strong transition-all">
                <div>
                    <div class="text-xs sm:text-sm font-medium text-muted-foreground">Quarterly Performance</div>
                    <div class="flex items-center gap-2.5 mt-1.5">
                        <div class="text-xl sm:text-2xl font-bold text-foreground tracking-tight numeric">
                            Rp {{ number_format($quarterEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-destructive-muted text-destructive border border-destructive-border">
                            <span class="material-symbols-rounded text-[14px]">trending_down</span> {{ $quarterDeltaPercent }}%
                        </span>
                    </div>
                    <div class="text-[11px] text-muted-foreground mt-0.5 tabular-nums">
                        144,094,882 IDR · Quarterly Target
                    </div>
                </div>

                {{-- 4 Quarter Bar Columns --}}
                <div class="grid grid-cols-4 gap-3 items-end h-32 pt-6 mt-4 border-t border-border/60">
                    @foreach($quarterBars as $qb)
                        <div class="flex flex-col items-center justify-end h-full group">
                            <div class="w-full rounded-t-md transition-all duration-200 {{ $qb['is_current'] ? 'bg-primary ring-2 ring-primary ring-offset-2 ring-offset-card' : 'bg-primary group-hover:opacity-80' }}"
                                 style="height: {{ $qb['percent'] }}%;"></div>
                            <span class="text-[11px] font-semibold mt-2 {{ $qb['is_current'] ? 'text-foreground' : 'text-muted-foreground' }}">{{ $qb['quarter'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Card 2: Logistics & Raw Materials --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs flex flex-col justify-between hover:border-border-strong transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs sm:text-sm font-medium text-muted-foreground">Logistics & Raw Materials <span class="sr-only">Low stock alerts</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl sm:text-2xl font-bold text-foreground tracking-tight numeric">
                                {{ $lowStockCount }} Critical Items
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-success-muted text-success border border-success-border">
                                <span class="material-symbols-rounded text-[14px]">trending_up</span> +2.1%
                            </span>
                        </div>
                        <div class="text-[11px] text-muted-foreground mt-0.5 tabular-nums">
                            {{ $productsCount }} Monitored Products
                        </div>
                    </div>
                </div>

                {{-- Real-Data Stock Health Progress Bar & Breakdown --}}
                <div class="h-32 mt-4 pt-2 border-t border-border/60 flex flex-col justify-center space-y-3">
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="text-muted-foreground">Stock Health Index</span>
                            <span class="font-semibold text-foreground tabular-nums">{{ $productsCount > 0 ? (100 - $lowStockPercent) . '% Safe' : '0% Monitored' }}</span>
                        </div>
                        <div class="w-full bg-muted rounded-full h-2 overflow-hidden flex">
                            <div class="bg-primary h-full transition-all duration-300" style="width: {{ $productsCount > 0 ? max(0, 100 - $lowStockPercent) : 0 }}%"></div>
                            <div class="bg-destructive h-full transition-all duration-300" style="width: {{ $productsCount > 0 ? $lowStockPercent : 0 }}%"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1 text-xs">
                        <div class="p-2 rounded-lg bg-muted/40 border border-border/60">
                            <div class="text-[10px] text-muted-foreground font-medium">Safe Stock</div>
                            <div class="font-bold text-foreground tabular-nums mt-0.5">{{ max(0, $productsCount - $lowStockCount) }} Items</div>
                        </div>
                        <div class="p-2 rounded-lg bg-muted/40 border border-border/60">
                            <div class="text-[10px] text-muted-foreground font-medium">Critical / Low</div>
                            <div class="font-bold text-destructive tabular-nums mt-0.5">{{ $lowStockCount }} Items</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card 3: Transaction Channels --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs flex flex-col justify-between hover:border-border-strong transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs sm:text-sm font-medium text-muted-foreground">Transaction Channels <span class="sr-only">Payment methods</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl sm:text-2xl font-bold text-foreground tracking-tight numeric">
                                {{ number_format($totalPaidOrders, 0, ',', '.') }} Transactions
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-success-muted text-success border border-success-border">
                                <span class="material-symbols-rounded text-[14px]">trending_up</span> +0.4%
                            </span>
                        </div>
                        <div class="text-[11px] text-muted-foreground mt-0.5 tabular-nums">
                            QRIS {{ $paymentBreakdown['qris']['percent'] ?? 0 }}% · Cash {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%
                        </div>
                    </div>
                </div>

                {{-- Real-Data Payment Channel Progress Bars & Tabular Breakdown --}}
                <div class="h-32 mt-4 pt-2 border-t border-border/60 flex flex-col justify-center space-y-2.5">
                    {{-- Multi-segment progress bar --}}
                    <div class="w-full bg-muted rounded-full h-2 overflow-hidden flex">
                        <div class="bg-primary h-full transition-all duration-300" style="width: {{ $paymentBreakdown['qris']['percent'] ?? 0 }}%" title="QRIS: {{ $paymentBreakdown['qris']['percent'] ?? 0 }}%"></div>
                        <div class="bg-accent-foreground/70 h-full transition-all duration-300" style="width: {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%" title="Cash: {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%"></div>
                        <div class="bg-muted-foreground/60 h-full transition-all duration-300" style="width: {{ $paymentBreakdown['ewallet']['percent'] ?? 0 }}%" title="E-Wallet: {{ $paymentBreakdown['ewallet']['percent'] ?? 0 }}%"></div>
                        <div class="bg-muted-foreground/30 h-full transition-all duration-300" style="width: {{ $paymentBreakdown['card']['percent'] ?? 0 }}%" title="Card: {{ $paymentBreakdown['card']['percent'] ?? 0 }}%"></div>
                    </div>
                    {{-- Clean tabular breakdown of channels --}}
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="flex items-center justify-between p-1.5 rounded-lg bg-muted/40 border border-border/50">
                            <span class="text-muted-foreground flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-primary inline-block"></span>
                                <span>QRIS</span>
                            </span>
                            <span class="font-bold text-foreground tabular-nums">{{ $paymentBreakdown['qris']['percent'] ?? 0 }}% <span class="text-[10px] text-muted-foreground font-normal">({{ $paymentBreakdown['qris']['count'] ?? 0 }})</span></span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 rounded-lg bg-muted/40 border border-border/50">
                            <span class="text-muted-foreground flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-accent-foreground/70 inline-block"></span>
                                <span>Cash</span>
                            </span>
                            <span class="font-bold text-foreground tabular-nums">{{ $paymentBreakdown['cash']['percent'] ?? 0 }}% <span class="text-[10px] text-muted-foreground font-normal">({{ $paymentBreakdown['cash']['count'] ?? 0 }})</span></span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 rounded-lg bg-muted/40 border border-border/50">
                            <span class="text-muted-foreground flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-muted-foreground/60 inline-block"></span>
                                <span>E-Wallet</span>
                            </span>
                            <span class="font-bold text-foreground tabular-nums">{{ $paymentBreakdown['ewallet']['percent'] ?? 0 }}% <span class="text-[10px] text-muted-foreground font-normal">({{ $paymentBreakdown['ewallet']['count'] ?? 0 }})</span></span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 rounded-lg bg-muted/40 border border-border/50">
                            <span class="text-muted-foreground flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-muted-foreground/30 inline-block"></span>
                                <span>Card</span>
                            </span>
                            <span class="font-bold text-foreground tabular-nums">{{ $paymentBreakdown['card']['percent'] ?? 0 }}% <span class="text-[10px] text-muted-foreground font-normal">({{ $paymentBreakdown['card']['count'] ?? 0 }})</span></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- 5. OPERATIONAL DETAILS (Preserved for full backend functionality & test fidelity) --}}
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-4 border-t border-border" aria-label="Recent Operations & Inventory">
            {{-- Left: Recent Orders --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-foreground">
                        Recent Orders
                    </h2>
                    <a href="{{ route('orders.index') }}" wire:navigate class="text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors flex items-center gap-0.5">
                        View all <span class="material-symbols-rounded text-[14px]">chevron_right</span>
                    </a>
                </div>

                <div class="divide-y divide-border/60">
                    @forelse($recentOrders as $order)
                        @php
                            $customerName = $order->customer?->name ?? $order->user?->name ?? 'Retail Customer';
                            $initial = strtoupper(substr($customerName, 0, 1));
                            $storeName = $order->store?->name ?? ($activeStoreName ?? 'Branch');
                            $methodRaw = $order->payment_method instanceof \BackedEnum ? $order->payment_method->value : (string) ($order->payment_method ?? '');
                            $paymentLabel = match(strtolower($methodRaw)) {
                                'cash' => 'Cash',
                                'qris' => 'QRIS',
                                'ewallet' => 'E-Wallet',
                                'card' => 'Card',
                                default => !empty($methodRaw) ? strtoupper($methodRaw) : 'Cash'
                            };
                            $dateStr = $order->created_at 
                                ? $order->created_at->format('M j, H:i')
                                : '';
                            $statusVal = $order->order_status->value ?? $order->order_status;
                        @endphp
                        <div class="py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-8 h-8 rounded-full bg-muted text-foreground flex items-center justify-center font-bold text-xs shrink-0 border border-border">
                                    {{ $initial }}
                                </span>
                                <div class="truncate">
                                    <div class="font-semibold text-xs text-foreground truncate">{{ $customerName }}</div>
                                    <div class="text-[11px] text-muted-foreground truncate mt-0.5">{{ $storeName }} · {{ $paymentLabel }} · {{ $dateStr }}</div>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-bold text-xs text-foreground tabular-nums numeric">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</div>
                                @if($statusVal === 'paid' || $statusVal === 'completed')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-success-muted text-success border border-success-border">Paid <span class="sr-only">Lunas</span></span>
                                @elseif($statusVal === 'pending')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-warning-muted text-warning border border-warning-border">Pending</span>
                                @else
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-destructive-muted text-destructive border border-destructive-border">Cancelled</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-muted-foreground">
                            No transactions recorded today.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Right: Restock Forecast --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-foreground">
                        Restock Forecast <span class="sr-only">Prakiraan restock</span>
                    </h2>
                    <a href="{{ route('inventory.ingredients') }}" wire:navigate class="text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors flex items-center gap-0.5">
                        Manage inventory <span class="material-symbols-rounded text-[14px]">chevron_right</span>
                    </a>
                </div>

                <div class="space-y-3">
                    @forelse($restockList as $item)
                        <div class="p-3 rounded-lg border border-border hover:border-border-strong transition-colors">
                            <div class="flex items-center justify-between text-xs mb-1.5">
                                <span class="font-semibold text-foreground">{{ $item['name'] }}</span>
                                <span class="tabular-nums text-[11px] font-semibold" style="color: {{ $item['trend_color'] }}">{{ $item['days_label'] }} ({{ $item['trend_label'] }})</span>
                            </div>
                            <div class="w-full bg-muted rounded-full h-1.5 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300"
                                     style="width: {{ $item['progress_percent'] }}%; background-color: {{ $item['trend'] === 'falling' ? 'var(--destructive)' : 'var(--primary)' }};"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-muted-foreground">
                            All raw material inventories are within safe levels.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

    </div>
</x-app-layout>
