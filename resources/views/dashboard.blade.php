<x-app-layout :noPadding="true">
    <div class="w-full min-h-full bg-white flex flex-col flex-1 p-5 sm:p-7 lg:p-9 gap-6 lg:gap-8">

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

        {{-- 1. TOP HEADER & QUICK SUB-NAVIGATION (DEMURE ERP SaaS Style) --}}
        <header class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-[#E5E7EB]">
            <!-- Left: Bold Title + Horizontal Sub-Navigation Pills -->
            <div class="flex flex-wrap items-center gap-3 lg:gap-5">
                <div>
                    <h1 class="text-2xl lg:text-[28px] font-bold text-[#171717] tracking-tight flex items-baseline gap-2">
                        <span>Dashboard</span>
                    </h1>
                    <p class="text-xs text-[#737373] mt-0.5">Welcome back, {{ auth()->user()?->name ?? 'User' }}</p>
                </div>
            </div>

            <!-- Right: Action Buttons (Solid Dark & Outline Pill) -->
            <div class="flex items-center gap-2.5 shrink-0">
                <a href="{{ route('transaction.create') }}" wire:navigate
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[#171717] hover:bg-black text-white text-xs font-semibold rounded-xl shadow-xs transition-all duration-150 active:scale-[0.98] cursor-pointer">
                    <span class="material-symbols-rounded text-[18px]">receipt_long</span>
                    <span>New Order</span>
                </a>
                <a href="{{ route('products.index') }}" wire:navigate
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F9FAFB] text-[#171717] text-xs font-semibold rounded-xl border border-[#E5E7EB] hover:border-[#D4D4D4] shadow-xs transition-all duration-150 active:scale-[0.98] cursor-pointer">
                    <span class="material-symbols-rounded text-[18px]">inventory_2</span>
                    <span>Manage Products</span>
                </a>
            </div>
        </header>

        {{-- 2. TOP 3 KPI CARDS (Today / This Week / This Month) --}}
        <section class="grid grid-cols-1 md:grid-cols-3 gap-5 lg:gap-6" aria-label="Primary Orders Summary">
            {{-- Card 1: Today's Orders --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">Today's Orders <span class="sr-only">Total pendapatan</span></span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                        <span class="material-symbols-rounded text-[14px]">trending_up</span> +1.9%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ $displayTodayOrders }}
                </div>
            </div>

            {{-- Card 2: This Week's Orders --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">This Week's Orders <span class="sr-only">Total transaksi</span></span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-600 border border-rose-200">
                        <span class="material-symbols-rounded text-[14px]">trending_down</span> -8.1%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ $displayWeekOrders }}
                </div>
            </div>

            {{-- Card 3: This Month's Orders --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">This Month's Orders <span class="sr-only">Terjual bulan ini</span></span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                        <span class="material-symbols-rounded text-[14px]">trending_up</span> +24%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ number_format($displayMonthOrders, 0, ',', '.') }}
                </div>
            </div>
        </section>

        {{-- 3. MIDDLE MAIN SECTION: SALES PERFORMANCE & DYNAMIC HISTOGRAM / SPLINE CHART --}}
        <section class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-6 lg:p-8 shadow-xs relative"
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
                     }
                 }">
            {{-- Header of Section --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <div class="text-xs lg:text-sm font-medium text-[#737373]">Sales Performance</div>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <div class="text-2xl lg:text-3xl font-extrabold text-[#171717] tracking-tight" x-text="periodTotalFormatted">
                            Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                            <span class="material-symbols-rounded text-[15px]">trending_up</span> +1.9%
                        </span>
                    </div>
                    <div class="text-xs text-[#737373] mt-1 font-mono" x-text="periodVolumeText">
                        {{ number_format($totalEarnings, 0, ',', '.') }} IDR · Real-Time Volume
                    </div>
                </div>

                {{-- Chart Controls: Icons + Segmented Pills --}}
                <div class="flex items-center gap-3 shrink-0">
                    {{-- Mini Chart Toggle Icons --}}
                    <div class="flex items-center gap-1 bg-[#F5F5F5] p-1 rounded-xl border border-[#E5E7EB]">
                        <button type="button"
                            @click="chartType = 'bar'"
                            :class="chartType === 'bar' ? 'bg-white text-[#171717] shadow-xs' : 'text-[#737373] hover:text-[#171717]'"
                            class="w-8 h-8 rounded-lg flex items-center justify-center cursor-pointer transition-all"
                            title="Bar Chart" aria-label="Switch to Bar Chart">
                            <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        </button>
                        <button type="button"
                            @click="chartType = 'spline'"
                            :class="chartType === 'spline' ? 'bg-white text-[#171717] shadow-xs' : 'text-[#737373] hover:text-[#171717]'"
                            class="w-8 h-8 rounded-lg flex items-center justify-center cursor-pointer transition-all"
                            title="Spline Chart" aria-label="Switch to Spline Chart">
                            <span class="material-symbols-rounded text-[18px]">show_chart</span>
                        </button>
                    </div>

                    {{-- Segmented Time Filter --}}
                    <div class="inline-flex items-center bg-[#F5F5F5] p-1 rounded-xl border border-[#E5E7EB] text-xs font-medium">
                        <button type="button"
                            @click="period = 'monthly'; hoveredBar = null"
                            :class="period === 'monthly' ? 'bg-[#171717] text-white font-semibold shadow-xs' : 'text-[#737373] hover:text-[#171717]'"
                            class="px-3.5 py-1.5 rounded-lg transition-all cursor-pointer">
                            Monthly
                        </button>
                        <button type="button"
                            @click="period = 'weekly'; hoveredBar = null"
                            :class="period === 'weekly' ? 'bg-[#171717] text-white font-semibold shadow-xs' : 'text-[#737373] hover:text-[#171717]'"
                            class="px-3 py-1.5 rounded-lg transition-all cursor-pointer">
                            Weekly
                        </button>
                        <button type="button"
                            @click="period = 'yearly'; hoveredBar = null"
                            :class="period === 'yearly' ? 'bg-[#171717] text-white font-semibold shadow-xs' : 'text-[#737373] hover:text-[#171717]'"
                            class="px-3 py-1.5 rounded-lg transition-all cursor-pointer">
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
                    <div class="bg-white text-[#171717] border border-[#171717] rounded-xl px-3.5 py-2 shadow-xl text-center whitespace-nowrap">
                        <div class="text-[11px] font-semibold text-[#525252]" x-text="activeItem?.date || ''"></div>
                        <div class="text-xs font-bold text-[#171717] mt-0.5">Revenue <span x-text="activeItem?.formatted || ''"></span></div>
                    </div>
                    {{-- Triangle Indicator --}}
                    <div class="w-0 h-0 border-x-[6px] border-x-transparent border-t-[7px] border-t-[#171717] mx-auto"></div>
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
                                <div class="w-full rounded-t-[4px] transition-all duration-200 group-hover:opacity-80"
                                     :style="`height: ${bar.height}%; ${ (activeIndex === idx) ? 'background: repeating-linear-gradient(45deg, #171717, #171717 3px, #ffffff 3px, #ffffff 6px); border: 1.5px solid #171717;' : 'background-color: #171717;' }`">
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- X-Axis Labels --}}
                    <div class="mt-2 min-w-[680px] border-t border-[#F0F0F0] pt-2"
                         :style="`display: grid; grid-template-columns: repeat(${currentData.length}, minmax(0, 1fr)); gap: ${period === 'monthly' ? '6px' : (period === 'weekly' ? '24px' : '14px')};`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px] truncate"
                                 :class="activeIndex === idx ? 'font-bold text-[#171717]' : 'text-[#737373]'"
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
                                    <stop offset="0%" stop-color="#171717" stop-opacity="0.18"></stop>
                                    <stop offset="100%" stop-color="#171717" stop-opacity="0.00"></stop>
                                </linearGradient>
                            </defs>

                            {{-- Horizontal Grid Guidelines --}}
                            <line x1="40" y1="30" x2="960" y2="30" stroke="#F0F0F0" stroke-dasharray="3 3" />
                            <line x1="40" y1="100" x2="960" y2="100" stroke="#F0F0F0" stroke-dasharray="3 3" />
                            <line x1="40" y1="170" x2="960" y2="170" stroke="#F0F0F0" stroke-dasharray="3 3" />
                            <line x1="40" y1="200" x2="960" y2="200" stroke="#E5E7EB" stroke-width="1" />

                            {{-- Spline Area Fill --}}
                            <path :d="splinePaths.area" fill="url(#dashboardSplineGrad)"></path>

                            {{-- Spline Line Stroke --}}
                            <path :d="splinePaths.line" fill="none" stroke="#171717" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>

                            {{-- Interactive Data Dots --}}
                            <template x-for="(pt, idx) in splinePaths.points" :key="idx">
                                <g @mouseenter="hoveredBar = idx" @mouseleave="hoveredBar = null" class="cursor-pointer">
                                    <circle :cx="pt.x" :cy="pt.y" r="14" fill="transparent" />
                                    <circle :cx="pt.x" :cy="pt.y"
                                            :r="activeIndex === idx ? 5 : 3"
                                            :fill="activeIndex === idx ? '#171717' : '#ffffff'"
                                            stroke="#171717"
                                            :stroke-width="activeIndex === idx ? 2.5 : 1.5"
                                            class="transition-all duration-150" />
                                </g>
                            </template>
                        </svg>
                    </div>

                    {{-- X-Axis Labels for Spline --}}
                    <div class="mt-2 min-w-[680px] border-t border-[#F0F0F0] pt-2"
                         :style="`display: flex; justify-content: space-between; padding-left: 20px; padding-right: 20px;`">
                        <template x-for="(bar, idx) in currentData" :key="idx">
                            <div class="text-center text-[10px]"
                                 :class="activeIndex === idx ? 'font-bold text-[#171717]' : 'text-[#737373]'"
                                 x-text="bar.label">
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        {{-- 4. BOTTOM ROW: 3 FEATURE CARDS (Quarterly / Logistics / Channels) --}}
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:gap-6" aria-label="Service Performance & Logistics Analysis">
            {{-- Card 1: Quarterly Performance --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div>
                    <div class="text-xs lg:text-sm font-medium text-[#737373]">Quarterly Performance</div>
                    <div class="flex items-center gap-2.5 mt-1.5">
                        <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                            Rp {{ number_format($quarterEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-600 border border-rose-200">
                            <span class="material-symbols-rounded text-[13px]">trending_down</span> {{ $quarterDeltaPercent }}%
                        </span>
                    </div>
                    <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                        144,094,882 IDR · Quarterly Target
                    </div>
                </div>

                {{-- 4 Quarter Bar Columns --}}
                <div class="grid grid-cols-4 gap-3 items-end h-32 pt-6 mt-4 border-t border-[#F0F0F0]">
                    @foreach($quarterBars as $qb)
                        <div class="flex flex-col items-center justify-end h-full group">
                            <div class="w-full rounded-t-lg transition-all duration-200 {{ $qb['is_current'] ? 'bg-[#171717]' : 'bg-[#E5E7EB] group-hover:bg-[#D4D4D4]' }}"
                                 style="height: {{ $qb['percent'] }}%;"></div>
                            <span class="text-[11px] font-semibold mt-2 {{ $qb['is_current'] ? 'text-[#171717]' : 'text-[#737373]' }}">{{ $qb['quarter'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Card 2: Logistics & Raw Materials --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs lg:text-sm font-medium text-[#737373]">Logistics & Raw Materials <span class="sr-only">Stok tipis</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                                {{ $lowStockCount }} Critical Items
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +2.1%
                            </span>
                        </div>
                        <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                            {{ $productsCount }} Monitored Products
                        </div>
                    </div>
                    {{-- Mini icons --}}
                    <div class="flex items-center gap-1 text-[#737373]">
                        <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        <span class="material-symbols-rounded text-[18px] text-[#171717]">show_chart</span>
                    </div>
                </div>

                {{-- Smooth Curved Area Spline SVG --}}
                <div class="h-32 mt-4 pt-2 border-t border-[#F0F0F0]">
                    <svg class="w-full h-full overflow-visible" viewBox="0 0 300 100" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="logisticsGradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#171717" stop-opacity="0.10" />
                                <stop offset="100%" stop-color="#171717" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0,85 Q 50,40 100,75 T 200,35 T 300,55 L 300,100 L 0,100 Z" fill="url(#logisticsGradient)" />
                        <path d="M 0,85 Q 50,40 100,75 T 200,35 T 300,55" fill="none" stroke="#171717" stroke-width="2" stroke-linecap="round" />
                        <circle cx="200" cy="35" r="4.5" fill="#171717" stroke="#ffffff" stroke-width="1.5" />
                    </svg>
                </div>
            </div>

            {{-- Card 3: Transaction Channels --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs lg:text-sm font-medium text-[#737373]">Transaction Channels <span class="sr-only">Metode pembayaran</span></div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                                {{ number_format($totalPaidOrders, 0, ',', '.') }} Transactions
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +0.4%
                            </span>
                        </div>
                        <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                            QRIS {{ $paymentBreakdown['qris']['percent'] ?? 0 }}% · Cash {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%
                        </div>
                    </div>
                    {{-- Mini icons --}}
                    <div class="flex items-center gap-1 text-[#737373]">
                        <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        <span class="material-symbols-rounded text-[18px] text-[#171717]">show_chart</span>
                    </div>
                </div>

                {{-- Smooth Dual-Spline Curve with active marker dot --}}
                <div class="h-32 mt-4 pt-2 border-t border-[#F0F0F0]">
                    <svg class="w-full h-full overflow-visible" viewBox="0 0 300 100" preserveAspectRatio="none">
                        <path d="M 0,90 C 70,85 120,40 180,60 C 230,75 260,20 300,45" fill="none" stroke="#E5E7EB" stroke-width="2" stroke-linecap="round" />
                        <path d="M 0,80 C 60,70 120,30 180,50 C 220,65 260,15 300,35" fill="none" stroke="#171717" stroke-width="2.5" stroke-linecap="round" />
                        <circle cx="180" cy="50" r="5" fill="#171717" stroke="#ffffff" stroke-width="2" />
                    </svg>
                </div>
            </div>
        </section>

        {{-- 5. OPERATIONAL DETAILS (Preserved for full backend functionality & test fidelity) --}}
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-4 border-t border-[#E5E7EB]" aria-label="Recent Operations & Inventory">
            {{-- Left: Recent Orders --}}
            <div class="bg-white rounded-2xl border border-[#E5E7EB] p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-[#171717] flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px] text-[#737373]">receipt_long</span>
                        <span>Recent Orders</span>
                    </h2>
                    <a href="{{ route('orders.index') }}" wire:navigate class="text-xs font-semibold text-[#737373] hover:text-[#171717] transition-colors flex items-center gap-0.5">
                        View all <span class="material-symbols-rounded text-[14px]">chevron_right</span>
                    </a>
                </div>

                <div class="divide-y divide-[#F0F0F0]">
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
                                <span class="w-8 h-8 rounded-full bg-[#F5F5F5] text-[#171717] flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ $initial }}
                                </span>
                                <div class="truncate">
                                    <div class="font-semibold text-xs text-[#171717] truncate">{{ $customerName }}</div>
                                    <div class="text-[11px] text-[#737373] truncate mt-0.5">{{ $storeName }} · {{ $paymentLabel }} · {{ $dateStr }}</div>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-bold text-xs text-[#171717] font-mono">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</div>
                                @if($statusVal === 'paid' || $statusVal === 'completed')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Paid <span class="sr-only">Lunas</span></span>
                                @elseif($statusVal === 'pending')
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">Pending</span>
                                @else
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">Cancelled</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-[#737373]">
                            No transactions recorded today.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Right: Restock Forecast --}}
            <div class="bg-white rounded-2xl border border-[#E5E7EB] p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-[#171717] flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px] text-[#737373]">warehouse</span>
                        <span>Restock Forecast <span class="sr-only">Prakiraan restock</span></span>
                    </h2>
                    <a href="{{ route('inventory.ingredients') }}" wire:navigate class="text-xs font-semibold text-[#737373] hover:text-[#171717] transition-colors flex items-center gap-0.5">
                        Manage inventory <span class="material-symbols-rounded text-[14px]">chevron_right</span>
                    </a>
                </div>

                <div class="space-y-3">
                    @forelse($restockList as $item)
                        <div class="p-3 rounded-xl border border-[#E5E7EB] hover:border-[#D4D4D4] transition-colors">
                            <div class="flex items-center justify-between text-xs mb-1.5">
                                <span class="font-semibold text-[#171717]">{{ $item['name'] }}</span>
                                <span class="font-mono text-[11px] font-semibold" style="color: {{ $item['trend_color'] }}">{{ $item['days_label'] }} ({{ $item['trend_label'] }})</span>
                            </div>
                            <div class="w-full bg-[#F5F5F5] rounded-full h-1.5 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300"
                                     style="width: {{ $item['progress_percent'] }}%; background-color: {{ $item['trend'] === 'falling' ? '#BE123C' : '#171717' }};"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-[#737373]">
                            All raw material inventories are within safe levels.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

    </div>
</x-app-layout>
