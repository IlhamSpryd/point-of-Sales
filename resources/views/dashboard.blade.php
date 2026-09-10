<x-app-layout>
    <!-- PAGE HEADER -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-[28px] font-bold text-zinc-900 dark:text-white tracking-tight">Dashboard</h1>
            <p class="text-[15px] font-medium text-zinc-500 mt-1">An easy way to manage sales with care and precision.</p>
        </div>
        <button class="hidden sm:flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-semibold rounded-[14px] hover:bg-zinc-50 dark:hover:bg-zinc-800 animate-transition shadow-sm focus:ring-2 focus:ring-zinc-1000">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-500"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="px-1">{{ now()->subDays(11)->format('F d, Y') }} - {{ now()->format('F d, Y') }}</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

    <!-- STAT CARDS ROW (3 Columns) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <!-- 1. Promo Card -->
        <div class="bg-[#09090b] rounded-2xl p-6 text-white relative overflow-hidden flex flex-col shadow-sm min-h-40 group">
            <div class="relative z-10 flex-1">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-[10px] bg-white/10 border border-white/20 text-[11px] font-bold tracking-wider uppercase text-white mb-4 backdrop-blur-sm">
                    <span class="w-2 h-2 rounded-full bg-gray-50"></span> Update
                </span>
                <p class="text-white/60 text-xs font-semibold mb-1">{{ now()->format('M dS Y') }}</p>
                <h3 class="text-xl font-bold leading-[1.3] pr-8 text-white/95">Sales revenue increased 40% in 1 week</h3>
            </div>
            <div class="relative z-10 mt-6">
                <a href="#" class="text-gray-50 text-[15px] font-bold hover:text-[#c4fc19] transition-colors flex items-center gap-1.5 group w-max">
                    See Statistics 
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="group-hover:translate-x-1 transition-transform"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
            <!-- Geometric Decoration -->
            <div class="absolute -right-2 -bottom-2 opacity-90 translate-x-6.25 translate-y-6.25">
                <svg class="transition-transform duration-2000 ease-in-out group-hover:rotate-180" width="130" height="130" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <g transform="translate(50,50)">
                        <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" />
                        <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" transform="rotate(60)" />
                        <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" transform="rotate(120)" />
                    </g>
                </svg>
            </div>
        </div>

        <!-- 2. Net Income -->
        <div class="bg-white dark:bg-zinc-900 rounded-2xl p-6 border border-zinc-100 dark:border-zinc-800 shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] flex flex-col relative min-h-40 overflow-hidden">
            <div class="flex justify-between items-start mb-3 relative z-10">
                <span class="text-[15px] font-bold text-zinc-500">Net Income</span>
                <button class="text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-300 transition-colors">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </button>
            </div>
            <h3 class="text-[32px] font-extrabold text-zinc-900 dark:text-white mb-2 tracking-tight relative z-10">Rp {{ number_format($totalEarnings, 0, ',', '.') }}</h3>
            <span class="inline-flex items-center text-[13px] font-bold text-emerald-500 gap-1 relative z-10">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg> +35% from last month
            </span>
            <!-- Sparkline Chart Placeholder -->
            <div class="absolute bottom-0 left-0 w-full h-15 opacity-100">
                <div id="income-sparkline"></div>
            </div>
        </div>

        <!-- 3. Total Return / Orders -->
        <div class="bg-white dark:bg-zinc-900 rounded-2xl p-6 border border-zinc-100 dark:border-zinc-800 shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] flex flex-col relative min-h-40 overflow-hidden">
            <div class="flex justify-between items-start mb-3 relative z-10">
                <span class="text-[15px] font-bold text-zinc-500">Total Return</span>
                <button class="text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-300 transition-colors">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </button>
            </div>
            <h3 class="text-[32px] font-extrabold text-zinc-900 dark:text-white mb-2 tracking-tight relative z-10">{{ number_format($totalOrders) }}</h3>
            <span class="inline-flex items-center text-[13px] font-bold text-rose-500 gap-1 relative z-10">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="17" y1="7" x2="7" y2="17"/><polyline points="17 17 7 17 7 7"/></svg> -24% from last month
            </span>
            <!-- Sparkline Chart Placeholder -->
            <div class="absolute bottom-0 left-0 w-full h-15 opacity-100">
                <div id="return-sparkline"></div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID (8 + 4 columns) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        
        <!-- LEFT AREA (col-span-8) -->
        <div class="lg:col-span-8 flex flex-col gap-6">
            
            <!-- Revenue Chart Area -->
            <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] border border-zinc-100 dark:border-zinc-800 px-6 pt-6 pb-2 relative overflow-hidden">
                <div class="flex flex-wrap justify-between items-start mb-2 gap-4">
                    <h2 class="text-[18px] font-bold text-zinc-900 dark:text-white">Revenue</h2>
                    <div class="flex items-center gap-4 text-sm font-semibold">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#09090b]"></span> <span class="text-zinc-500 dark:text-zinc-400">Income</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-gray-50"></span> <span class="text-zinc-500 dark:text-zinc-400">Expenses</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-baseline gap-2 mb-4 relative z-10">
                    <span class="text-[28px] font-extrabold text-zinc-900 dark:text-white tracking-tight">Rp {{ number_format($totalEarnings, 0, ',', '.') }}</span>
                    <span class="text-xs font-bold text-emerald-500">+35% from last month</span>
                </div>
                <div id="revenue-chart" class="w-full h-70 -mx-2"></div>
                <!-- Top gradient border effect (subtle) -->
                <div class="absolute inset-x-0 top-0 h-1 bg-linear-to-r from-transparent via-zinc-100 dark:via-zinc-800 to-transparent"></div>
            </div>

            <!-- Lower Left Split Grid (Transaction & Progress) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Transaction List -->
                <div class="lg:col-span-12 xl:col-span-7 bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] border border-zinc-100 dark:border-zinc-800 p-6 flex flex-col h-full">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-[18px] font-bold text-zinc-900 dark:text-white">Transaction</h2>
                        <button class="text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-300">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex-1 flex flex-col gap-5">
                        @forelse($recentOrders->take(4) as $order)
                        <div class="flex items-center justify-between group">
                            <div class="flex items-center gap-4">
                                <div class="w-10.5 h-10.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-[#09090b] dark:text-emerald-400 flex items-center justify-center shrink-0">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="22"/><line x1="17" y1="5" x2="12" y2="2"/><line x1="7" y1="5" x2="12" y2="2"/><line x1="17" y1="19" x2="12" y2="22"/><line x1="7" y1="19" x2="12" y2="22"/></svg>
                                </div>
                                <div>
                                    <h4 class="text-[15px] font-bold text-zinc-900 dark:text-white">{{ $order->user->name ?? 'Retail Customer' }}</h4>
                                    <p class="text-[13px] font-semibold text-zinc-500 mt-0.5">{{ $order->created_at?->format('F d, Y • h:i A') ?? now()->format('F d, Y') }}</p>
                                </div>
                            </div>
                            <span class="text-[15px] font-extrabold {{ $order->order_status == 'completed' ? 'text-emerald-500' : 'text-amber-500' }}">
                                {{ $order->order_status == 'completed' ? '+' : '' }}Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                            </span>
                        </div>
                        @empty
                        <p class="text-sm text-zinc-400 text-center py-4">No recent transactions.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Product Overview Progress -->
                <div class="lg:col-span-12 xl:col-span-5 bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] border border-zinc-100 dark:border-zinc-800 p-6 flex flex-col h-full">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-[18px] font-bold text-zinc-900 dark:text-white">Product Overview</h2>
                        <button class="text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-300">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                        </button>
                    </div>

                    <div class="flex-1 flex flex-col gap-4">
                        <!-- Progress Items -->
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-[13px] font-bold text-zinc-500 dark:text-zinc-400">Total Products</span>
                                <span class="text-[13px] font-extrabold text-zinc-900 dark:text-white">{{ $productsCount }}</span>
                            </div>
                            <div class="w-full h-1.5 bg-zinc-50/50 dark:bg-zinc-800 rounded-full overflow-hidden">
                                <div class="h-full bg-gray-50 rounded-full" style="width: 85%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-[13px] font-bold text-zinc-500 dark:text-zinc-400">Low Stock Warning</span>
                                <span class="text-[13px] font-extrabold text-zinc-900 dark:text-white">12</span>
                            </div>
                            <div class="w-full h-1.5 bg-zinc-50/50 dark:bg-zinc-800 rounded-full overflow-hidden">
                                <div class="h-full bg-gray-50 opacity-50 rounded-full" style="width: 25%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-[13px] font-bold text-zinc-500 dark:text-zinc-400">Sold This Month</span>
                                <span class="text-[13px] font-extrabold text-zinc-900 dark:text-white">482</span>
                            </div>
                            <div class="w-full h-1.5 bg-zinc-50/50 dark:bg-zinc-800 rounded-full overflow-hidden">
                                <div class="h-full bg-gray-50 rounded-full" style="width: 65%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-[13px] font-bold text-zinc-500 dark:text-zinc-400">Product Returned</span>
                                <span class="text-[13px] font-extrabold text-zinc-900 dark:text-white">8</span>
                            </div>
                            <div class="w-full h-1.5 bg-zinc-50/50 dark:bg-zinc-800 rounded-full overflow-hidden">
                                <div class="h-full bg-[#FF6A3D] rounded-full" style="width: 5%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT AREA (col-span-4) -->
        <div class="lg:col-span-4 flex flex-col gap-6">
            <!-- Total View/Orders Chart -->
            <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] border border-zinc-100 dark:border-zinc-800 p-6 flex flex-col flex-1">
                <h2 class="text-[18px] font-bold text-zinc-900 dark:text-white mb-2">Total View Performance</h2>
                
                <div class="flex-1 flex items-center justify-center my-4 min-h-55">
                    <div id="performance-chart" class="w-full max-h-60 flex justify-center"></div>
                </div>

                <div class="flex justify-between items-center gap-2 mt-auto">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#94a3b8]"></span>
                        <span class="text-xs font-bold text-zinc-500">View Count</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#0f172a]"></span>
                        <span class="text-xs font-bold text-zinc-500">Percentage</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#f97316]"></span>
                        <span class="text-xs font-bold text-zinc-500">Sales</span>
                    </div>
                </div>
            </div>

            <!-- Promo Banner Box -->
            <div class="bg-[#09090b] rounded-2xl p-8 text-white relative overflow-hidden shadow-sm flex flex-col justify-center min-h-45 group">
                <!-- Geometric Decoration -->
                <div class="absolute -right-6 -bottom-6 opacity-80">
                    <svg class="transition-transform duration-2000 ease-in-out group-hover:rotate-180" width="160" height="160" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <g transform="translate(50,50)">
                            <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" />
                            <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" transform="rotate(60)" />
                            <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#fafafa" transform="rotate(120)" />
                        </g>
                    </svg>
                </div>

                <div class="relative z-10 w-4/5">
                    <h3 class="text-[22px] font-extrabold leading-tight text-white mb-3 tracking-tight">Level up your sales managing to the next level.</h3>
                    <p class="text-white/60 text-[13px] font-medium leading-relaxed mb-6">An easy way to manage sales with care and precision.</p>
                    <button class="bg-gray-50 hover:bg-[#c4fc19] text-[#09090b] px-5 py-2.5 rounded-xl text-[13px] font-bold transition-transform hover:-translate-y-0.5 whitespace-nowrap">
                        Check the updates now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts for ApexCharts inside Tailwind Dashboard -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Sparkline: Net Income
            var incomeSparklineOptions = {
                series: [{ data: [15, 25, 20, 35, 28, 48, 45, 60] }],
                chart: { type: 'area', height: 60, sparkline: { enabled: true } },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0, stops: [0, 100] }
                },
                colors: ['#10b981'], // Emerald 500
                tooltip: { fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: function () { return '' } } }, marker: { show: false } }
            };
            new ApexCharts(document.querySelector("#income-sparkline"), incomeSparklineOptions).render();

            // 2. Sparkline: Total Return
            var returnSparklineOptions = {
                series: [{ data: [65, 50, 45, 30, 35, 20, 15, 5] }],
                chart: { type: 'area', height: 60, sparkline: { enabled: true } },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0, stops: [0, 100] }
                },
                colors: ['#f43f5e'], // Rose 500
                tooltip: { fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: function () { return '' } } }, marker: { show: false } }
            };
            new ApexCharts(document.querySelector("#return-sparkline"), returnSparklineOptions).render();

            // 3. Main Area Chart: Revenue vs Expenses
            var revenueOptions = {
                series: [{
                    name: "Income",
                    data: [85, 110, 95, 140, 130, 200, 185]
                }, {
                    name: "Expenses",
                    data: [45, 60, 50, 80, 75, 120, 110]
                }],
                chart: {
                    height: 280,
                    type: 'area',
                    fontFamily: 'inherit',
                    toolbar: { show: false },
                    sparkline: { enabled: false }
                },
                colors: ['#09090b', '#fafafa'], 
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 3 },
                xaxis: {
                    categories: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul"],
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: { style: { colors: '#94a3b8', fontSize: '13px', fontWeight: 600 } }
                },
                yaxis: {
                    labels: { style: { colors: '#94a3b8', fontSize: '13px', fontWeight: 600 } }
                },
                grid: {
                    borderColor: '#f1f5f9',
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } }
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.2, // very transparent
                        opacityTo: 0.0,
                        stops: [0, 100]
                    }
                },
                legend: { show: false }
            };
            new ApexCharts(document.querySelector("#revenue-chart"), revenueOptions).render();

            // 4. Donut Chart: Total View Performance
            var performanceOptions = {
                series: [55, 30, 15],
                labels: ['View Count', 'Percentage', 'Sales'],
                chart: {
                    type: 'donut',
                    height: 260,
                    fontFamily: 'inherit'
                },
                colors: ['#94a3b8', '#0f172a', '#f97316'],
                plotOptions: {
                    pie: {
                        donut: {
                            size: '75%',
                            labels: {
                                show: true,
                                name: { show: true, fontSize: '12px', fontWeight: 600, color: '#64748b', offsetY: -5 },
                                value: { show: true, fontSize: '28px', fontWeight: 800, color: '#0f172a', offsetY: 5, formatter: function (val) { return val + "K" } },
                                total: { show: true, showAlways: true, label: 'Total Count', fontSize: '12px', fontWeight: 600, color: '#64748b', formatter: function (w) { return "565K" } }
                            }
                        },
                        customScale: 0.8,
                        dataLabels: {
                            offset: 40
                        }
                    }
                },
                dataLabels: { 
                    enabled: true,
                    style: { fontSize: '12px', colors: ['#0f172a'], fontWeight: 800 },
                    dropShadow: { enabled: false }
                },
                stroke: { show: true, width: 4, colors: ['transparent'] },
                legend: { show: false }
            };
            new ApexCharts(document.querySelector("#performance-chart"), performanceOptions).render();
        });
    </script>
</x-app-layout>
