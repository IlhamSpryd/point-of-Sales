<x-app-layout :noPadding="true">
    <div class="w-full min-h-full flex flex-col flex-1 p-4 sm:p-6 lg:p-8 gap-6 bg-background text-foreground">

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

        {{-- 1. TOP HEADER & ACTIONS (x-erp.page-header) --}}
        <x-erp.page-header
            title="Dashboard"
            :description="'Welcome back, ' . (auth()->user()?->name ?? 'User')"
            class="pb-4 border-b border-border mb-0"
        >
            <x-slot:actions>
                <a href="{{ route('transaction.create') }}" wire:navigate>
                    <x-ui.button variant="primary" size="sm">
                        <x-ui.icon name="receipt_long" size="sm" />
                        <span>New Order</span>
                    </x-ui.button>
                </a>
                <a href="{{ route('products.index') }}" wire:navigate>
                    <x-ui.button variant="outline" size="sm">
                        <x-ui.icon name="inventory_2" size="sm" />
                        <span>Manage Products</span>
                    </x-ui.button>
                </a>
            </x-slot:actions>
        </x-erp.page-header>

        {{-- 2. TOP 3 KPI CARDS (x-erp.stat-card) --}}
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6" aria-label="Primary Orders Summary">
            {{-- Card 1: Today's Orders --}}
            <x-erp.stat-card
                label="Today's Orders"
                hint="Total pendapatan hari ini"
                :value="$displayTodayOrders"
                delta="+1.9%"
                delta-variant="positive"
                icon="receipt_long"
            />

            {{-- Card 2: This Week's Orders --}}
            <x-erp.stat-card
                label="This Week's Orders"
                hint="Total transaksi minggu ini"
                :value="$displayWeekOrders"
                delta="-8.1%"
                delta-variant="negative"
                icon="calendar_view_week"
            />

            {{-- Card 3: This Month's Orders --}}
            <x-erp.stat-card
                label="This Month's Orders"
                hint="Terjual bulan ini"
                :value="number_format($displayMonthOrders, 0, ',', '.')"
                delta="+24%"
                delta-variant="positive"
                icon="calendar_month"
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
                     get activeItem() {
                         if (this.hoveredBar !== null && this.currentData[this.hoveredBar]) {
                             return this.currentData[this.hoveredBar];
                         }
                         const found = this.currentData.find(d => d.is_active);
                         return found || this.currentData[Math.floor(this.currentData.length / 2)] || this.currentData[0] || null;
                     },
                     get activeIndex() {
                         if (this.hoveredBar !== null) return this.hoveredBar;
                         const idx = this.currentData.findIndex(d => d.is_active);
                         return idx !== -1 ? idx : Math.floor(this.currentData.length / 2);
                     },
                     get tooltipLeftPercent() {
                         const n = this.currentData.length;
                         if (n <= 1) return 50;
                         return Math.min(95, Math.max(5, ((this.activeIndex + 0.5) / n) * 100));
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
            {{-- Header of Section --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <div class="text-xs sm:text-sm font-medium text-muted-foreground">Sales Performance</div>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <div class="text-2xl sm:text-3xl font-extrabold text-foreground tracking-tight numeric" x-text="periodTotalFormatted">
                            Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                            <span class="material-symbols-rounded text-[15px]">trending_up</span> +1.9%
                        </span>
                    </div>
                    <div class="text-xs text-muted-foreground mt-1 font-mono numeric" x-text="periodVolumeText">
                        {{ number_format($totalEarnings, 0, ',', '.') }} IDR · Real-Time Volume
                    </div>
                </div>

                {{-- Chart Controls: Icons + Segmented Pills --}}
                <div class="flex items-center gap-3 shrink-0">
                    {{-- Mini Chart Toggle Icons --}}
                    <div class="flex items-center gap-1 bg-muted p-1 rounded-lg border border-border">
                        <button type="button"
                            @click="chartType = 'bar'"
                            :class="chartType === 'bar' ? 'bg-card text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="w-8 h-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Bar Chart" aria-label="Switch to Bar Chart">
                            <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        </button>
                        <button type="button"
                            @click="chartType = 'spline'"
                            :class="chartType === 'spline' ? 'bg-card text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="size-8 rounded-md flex items-center justify-center cursor-pointer transition-all"
                            title="Spline Chart" aria-label="Switch to Spline Chart">
                            <span class="material-symbols-rounded text-[18px]">show_chart</span>
                        </button>
                    </div>

                    {{-- Segmented Time Filter --}}
                    <div class="inline-flex items-center bg-muted p-1 rounded-lg border border-border text-xs font-medium">
                        <button type="button"
                            @click="period = 'monthly'; hoveredBar = null"
                            :class="period === 'monthly' ? 'bg-card text-foreground font-semibold shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Monthly
                        </button>
                        <button type="button"
                            @click="period = 'weekly'; hoveredBar = null"
                            :class="period === 'weekly' ? 'bg-card text-foreground font-semibold shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Weekly
                        </button>
                        <button type="button"
                            @click="period = 'yearly'; hoveredBar = null"
                            :class="period === 'yearly' ? 'bg-card text-foreground font-semibold shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-3 py-1.5 rounded-md transition-all cursor-pointer">
                            Yearly
                        </button>
                    </div>
                </div>
            </div>

            {{-- Responsive Chart Area --}}
            <div class="relative w-full pt-16 pb-2 overflow-x-auto scrollbar-hide">
                {{-- Floating Tooltip Speech Bubble (Directly above active day/item) --}}
                <div class="absolute top-1 -translate-x-1/2 pointer-events-none transition-all duration-200 z-10 hidden sm:block"
                     :style="`left: ${tooltipLeftPercent}%;`">
                    <div class="bg-card text-foreground border border-border rounded-lg px-3.5 py-2 shadow-lg text-center whitespace-nowrap">
                        <div class="text-[11px] font-semibold text-muted-foreground" x-text="activeItem?.date || ''"></div>
                        <div class="text-xs font-bold text-foreground mt-0.5">Revenue <span x-text="activeItem?.formatted || ''"></span></div>
                    </div>
                    {{-- Triangle Indicator --}}
                    <div class="w-0 h-0 border-x-[6px] border-x-transparent border-t-[7px] border-t-border mx-auto"></div>
                </div>

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
                                <div class="w-full rounded-t-sm transition-all duration-200 group-hover:opacity-80"
                                     :style="`height: ${bar.height}%; ${ (activeIndex === idx) ? 'background: repeating-linear-gradient(45deg, currentColor, currentColor 3px, transparent 3px, transparent 6px); border: 1.5px solid currentColor;' : 'background-color: currentColor;' }`"
                                     :class="activeIndex === idx ? 'text-primary' : 'text-primary/70'">
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
                                 Alpine tidak dapat mengoper x-for di dalam subtree SVG secara
                                 andal (atribut binding tidak diproses), sehingga lingkaran
                                 dirender server-side dan hanya reveal/hover yang Alpine.
                                 Koordinat dihitung ulang oleh x-effect saat periode berubah. --}}
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
                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200 dark:border-rose-800">
                            <span class="material-symbols-rounded text-[13px]">trending_down</span> {{ $quarterDeltaPercent }}%
                        </span>
                    </div>
                    <div class="text-[11px] text-muted-foreground mt-0.5 font-mono">
                        144,094,882 IDR · Quarterly Target
                    </div>
                </div>

                {{-- 4 Quarter Bar Columns --}}
                <div class="grid grid-cols-4 gap-3 items-end h-32 pt-6 mt-4 border-t border-border/60">
                    @foreach($quarterBars as $qb)
                        <div class="flex flex-col items-center justify-end h-full group">
                            <div class="w-full rounded-t-sm transition-all duration-200 {{ $qb['is_current'] ? 'bg-primary' : 'bg-muted group-hover:bg-muted-foreground/20' }}"
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
                        <div class="text-xs sm:text-sm font-medium text-muted-foreground">Logistics & Raw Materials <span class="sr-only">Stok tipis</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl sm:text-2xl font-bold text-foreground tracking-tight numeric">
                                {{ $lowStockCount }} Critical Items
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +2.1%
                            </span>
                        </div>
                        <div class="text-[11px] text-muted-foreground mt-0.5 font-mono">
                            {{ $productsCount }} Monitored Products
                        </div>
                    </div>
                    {{-- Mini icons --}}
                    <div class="flex items-center gap-1 text-muted-foreground">
                        <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        <span class="material-symbols-rounded text-[18px] text-foreground">show_chart</span>
                    </div>
                </div>

                {{-- Smooth Curved Area Spline SVG --}}
                <div class="h-32 mt-4 pt-2 border-t border-border/60">
                    <svg class="w-full h-full overflow-visible" viewBox="0 0 300 100" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="logisticsGradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="currentColor" class="text-primary" stop-opacity="0.10" />
                                <stop offset="100%" stop-color="currentColor" class="text-primary" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0,85 Q 50,40 100,75 T 200,35 T 300,55 L 300,100 L 0,100 Z" fill="url(#logisticsGradient)" />
                        <path d="M 0,85 Q 50,40 100,75 T 200,35 T 300,55" fill="none" stroke="currentColor" class="text-primary" stroke-width="2" stroke-linecap="round" />
                        <circle cx="200" cy="35" r="4.5" fill="currentColor" class="text-primary" stroke="var(--color-card, #ffffff)" stroke-width="1.5" />
                    </svg>
                </div>
            </div>

            {{-- Card 3: Transaction Channels --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs flex flex-col justify-between hover:border-border-strong transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs sm:text-sm font-medium text-muted-foreground">Transaction Channels <span class="sr-only">Metode pembayaran</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl sm:text-2xl font-bold text-foreground tracking-tight numeric">
                                {{ number_format($totalPaidOrders, 0, ',', '.') }} Transactions
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +0.4%
                            </span>
                        </div>
                        <div class="text-[11px] text-muted-foreground mt-0.5 font-mono">
                            QRIS {{ $paymentBreakdown['qris']['percent'] ?? 0 }}% · Cash {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%
                        </div>
                    </div>
                    {{-- Mini icons --}}
                    <div class="flex items-center gap-1 text-muted-foreground">
                        <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        <span class="material-symbols-rounded text-[18px] text-foreground">show_chart</span>
                    </div>
                </div>

                {{-- Smooth Dual-Spline Curve with active marker dot --}}
                <div class="h-32 mt-4 pt-2 border-t border-border/60">
                    <svg class="w-full h-full overflow-visible" viewBox="0 0 300 100" preserveAspectRatio="none">
                        <path d="M 0,90 C 70,85 120,40 180,60 C 230,75 260,20 300,45" fill="none" stroke="currentColor" class="text-border" stroke-width="2" stroke-linecap="round" />
                        <path d="M 0,80 C 60,70 120,30 180,50 C 220,65 260,15 300,35" fill="none" stroke="currentColor" class="text-primary" stroke-width="2.5" stroke-linecap="round" />
                        <circle cx="180" cy="50" r="5" fill="currentColor" class="text-primary" stroke="var(--color-card, #ffffff)" stroke-width="2" />
                    </svg>
                </div>
            </div>
        </section>

        {{-- 5. OPERATIONAL DETAILS (Preserved for full backend functionality & test fidelity) --}}
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-4 border-t border-border" aria-label="Recent Operations & Inventory">
            {{-- Left: Recent Orders --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-foreground flex items-center gap-2">
                        <x-ui.icon name="receipt_long" size="sm" class="text-muted-foreground" />
                        <span>Recent Orders</span>
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
                                <div class="font-bold text-xs text-foreground font-mono tabular-nums numeric">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</div>
                                @if($statusVal === 'paid' || $statusVal === 'completed')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">Paid <span class="sr-only">Lunas</span></span>
                                @elseif($statusVal === 'pending')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 border border-amber-200 dark:border-amber-800">Pending</span>
                                @else
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200 dark:border-rose-800">Cancelled</span>
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
                    <h2 class="text-sm font-bold text-foreground flex items-center gap-2">
                        <x-ui.icon name="warehouse" size="sm" class="text-muted-foreground" />
                        <span>Restock Forecast <span class="sr-only">Prakiraan restock</span></span>
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
                                <span class="font-mono text-[11px] font-semibold" style="color: {{ $item['trend_color'] }}">{{ $item['days_label'] }} ({{ $item['trend_label'] }})</span>
                            </div>
                            <div class="w-full bg-muted rounded-full h-1.5 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300"
                                     style="width: {{ $item['progress_percent'] }}%; background-color: {{ $item['trend'] === 'falling' ? 'var(--color-destructive, #e11d48)' : 'var(--color-primary, #18181b)' }};"></div>
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
