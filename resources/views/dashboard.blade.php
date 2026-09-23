<x-app-layout>
    <!-- PAGE HEADER -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-[#37352F] tracking-tight">Dashboard</h1>
            <p class="text-sm font-medium text-[#787774] mt-1">Cara mudah mengelola penjualan dengan teliti dan presisi.</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 px-4 py-2.5 bg-white border border-[#E9E9E7] text-[#787774] text-sm font-medium rounded-xl shadow-sm">
            <span class="material-symbols-rounded text-[18px]">calendar_today</span>
            <span>{{ now()->subDays(6)->format('F d, Y') }} — {{ now()->format('F d, Y') }}</span>
        </div>
    </div>

    <!-- STAT CARDS ROW -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
        <!-- 1. Promo Card -->
        <div class="bg-[#37352F] rounded-2xl p-6 text-white relative overflow-hidden flex flex-col shadow-md min-h-40 group">
            <div class="relative z-10 flex-1">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/10 border border-white/20 text-[11px] font-bold tracking-wider uppercase text-white/90 mb-4 backdrop-blur-sm">
                    <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> Pembaruan
                </span>
                <p class="text-white/50 text-xs font-medium mb-1">{{ now()->format('M dS Y') }}</p>
                <h3 class="text-xl font-bold leading-snug pr-8 text-white/95">Pendapatan penjualan meningkat dalam 1 minggu</h3>
            </div>
            <div class="relative z-10 mt-6">
                <a href="#" class="text-white/80 text-sm font-bold hover:text-white transition-colors flex items-center gap-1.5 group w-max">
                    Lihat Statistik
                    <span class="material-symbols-rounded text-[16px] group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </a>
            </div>
            <!-- Geometric Decoration -->
            <div class="absolute -right-4 -bottom-4 opacity-[0.08]">
                <span class="material-symbols-rounded text-[140px] text-white group-hover:rotate-12 transition-transform duration-700">coffee</span>
            </div>
        </div>

        <!-- 2. Net Income -->
        <div class="card-surface p-6 flex flex-col relative min-h-40 overflow-hidden">
            <div class="flex justify-between items-start mb-3 relative z-10">
                <h3 class="text-xs font-semibold text-[#787774] mb-1 tracking-tight">Total Pendapatan</h3>
                <span class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-emerald-600">trending_up</span>
                </span>
            </div>
            <h3 class="text-[28px] font-extrabold text-[#37352F] mb-2 tracking-tight relative z-10">Rp {{ number_format($totalEarnings, 0, ',', '.') }}</h3>
            <span class="inline-flex items-center text-xs font-bold text-emerald-600 gap-1 relative z-10">
                <span class="material-symbols-rounded text-[14px]">north_east</span> Akumulasi seluruh waktu
            </span>
            <div class="absolute bottom-0 left-0 w-full h-15 opacity-100">
                <div id="income-sparkline"></div>
            </div>
        </div>

        <!-- 3. Total Orders -->
        <div class="card-surface p-6 flex flex-col relative min-h-40 overflow-hidden">
            <div class="flex justify-between items-start mb-3 relative z-10">
                <span class="text-sm font-semibold text-[#787774]">Total Transaksi</span>
                <span class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-blue-600">receipt_long</span>
                </span>
            </div>
            <h3 class="text-[28px] font-extrabold text-[#37352F] mb-2 tracking-tight relative z-10">{{ number_format($totalOrders) }}</h3>
            <span class="inline-flex items-center text-xs font-bold text-emerald-600 gap-1 relative z-10">
                <span class="material-symbols-rounded text-[14px]">north_east</span> Akumulasi seluruh waktu
            </span>
            <div class="absolute bottom-0 left-0 w-full h-15 opacity-100">
                <div id="return-sparkline"></div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        
        <!-- LEFT AREA -->
        <div class="lg:col-span-8 flex flex-col gap-6">
            
            <!-- Revenue Chart Area -->
            <div class="card-surface px-6 pt-6 pb-2 relative overflow-hidden">
                <div class="flex flex-wrap justify-between items-start mb-2 gap-4">
                    <h2 class="text-lg font-bold text-[#37352F]">Pendapatan</h2>
                    <div class="flex items-center gap-4 text-sm font-medium">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#37352F]"></span> <span class="text-[#787774]">Pendapatan</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#E9E9E7]"></span> <span class="text-[#787774]">Jumlah Transaksi</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-baseline gap-2 mb-4 relative z-10">
                    <span class="text-[28px] font-extrabold text-[#37352F] tracking-tight">Rp {{ number_format($totalEarnings, 0, ',', '.') }}</span>
                    <div class="text-[11px] font-medium text-[#787774]">Akumulasi seluruh waktu</div>
                </div>
                <div id="revenue-chart" class="w-full h-70 -mx-2"></div>
            </div>

            <!-- Lower Left Split Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Transaction List -->
                <div class="lg:col-span-12 xl:col-span-7 card-surface p-6 flex flex-col h-full">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="text-lg font-bold text-[#37352F]">Transaksi</h2>
                    </div>
                    
                    <div class="flex-1 flex flex-col gap-4">
                        @forelse($recentOrders->take(4) as $order)
                        <div class="flex items-center justify-between group p-3 rounded-xl hover:bg-[#F7F7F5] transition-all duration-200">
                            <div class="flex items-center gap-3.5">
                                <div class="w-10 h-10 rounded-xl {{ $order->order_status->value === 'paid' ? 'bg-yovel-ink text-white' : 'bg-yovel-surface text-yovel-ink' }} flex items-center justify-center shrink-0">
                                    <span class="material-symbols-rounded text-[18px]">
                                        {{ $order->order_status->value === 'paid' ? 'check_circle' : 'schedule' }}
                                    </span>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-[#37352F]">{{ $order->user->name ?? 'Pelanggan Ritel' }}</h4>
                                    <p class="text-xs font-medium text-[#9B9A97] mt-0.5">{{ $order->created_at?->format('F d, Y • h:i A') ?? now()->format('F d, Y') }}</p>
                                </div>
                            </div>
                            <span class="text-sm font-extrabold {{ $order->order_status->value === 'paid' ? 'text-yovel-ink' : 'text-yovel-muted' }}">
                                {{ $order->order_status->value === 'paid' ? '+' : '' }}Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                            </span>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-8">
                            <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">receipt_long</span>
                            </div>
                            <p class="text-sm text-[#9B9A97] font-medium">Belum ada transaksi terbaru.</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                <!-- Product Overview Progress -->
                <div class="lg:col-span-12 xl:col-span-5 card-surface p-6 flex flex-col h-full">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="text-lg font-bold text-[#37352F]">Ringkasan Produk</h2>
                    </div>

                    <div class="flex-1 flex flex-col gap-5">
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-semibold text-[#787774]">Total Produk</span>
                                <span class="text-xs font-bold text-[#37352F]">{{ $productsCount }}</span>
                            </div>
                            <div class="w-full h-1.5 bg-[#F1F1EF] rounded-full overflow-hidden">
                                <div class="h-full bg-[#37352F] rounded-full transition-all duration-700" style="width: {{ $productsCount > 0 ? 100 : 0 }}%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-semibold text-[#787774]">Peringatan Stok Tipis</span>
                                <span class="text-xs font-bold text-amber-600">{{ $lowStockCount }}</span>
                            </div>
                            <div class="w-full h-1.5 bg-[#F1F1EF] rounded-full overflow-hidden">
                                <div class="h-full bg-amber-500 rounded-full transition-all duration-700" style="width: {{ $lowStockPercent }}%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-semibold text-[#787774]">Terjual Bulan Ini</span>
                                <span class="text-xs font-bold text-emerald-600">{{ number_format($soldThisMonth) }}</span>
                            </div>
                            <div class="w-full h-1.5 bg-[#F1F1EF] rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full transition-all duration-700" style="width: 100%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-semibold text-[#787774]">Produk Dikembalikan</span>
                                <span class="text-xs font-bold text-[#37352F]">{{ $returnedProducts }}</span>
                            </div>
                            <div class="w-full h-1.5 bg-[#F1F1EF] rounded-full overflow-hidden">
                                <div class="h-full bg-rose-400 rounded-full transition-all duration-700" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT AREA -->
        <div class="lg:col-span-4 flex flex-col gap-6">
            <!-- Payment Methods Chart -->
            <div class="card-surface p-6 flex flex-col flex-1">
                <h2 class="text-lg font-bold text-[#37352F] mb-2">Ringkasan Metode Pembayaran</h2>
                
                <div class="flex-1 flex items-center justify-center my-4 min-h-55">
                    <div id="performance-chart" class="w-full max-h-60 flex justify-center"></div>
                </div>

                <div class="flex justify-between items-center gap-2 mt-auto">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#9B9A97]"></span>
                        <span class="text-xs font-semibold text-[#787774]">Cash</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#37352F]"></span>
                        <span class="text-xs font-semibold text-[#787774]">QRIS</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <span class="text-xs font-semibold text-[#787774]">E-Wallet</span>
                    </div>
                </div>
            </div>

            <!-- Promo Banner -->
            <div class="bg-[#37352F] rounded-2xl p-8 text-white relative overflow-hidden shadow-md flex flex-col justify-center min-h-45 group">
                <div class="absolute -right-6 -bottom-6 opacity-[0.06]">
                    <span class="material-symbols-rounded text-[180px] text-white group-hover:rotate-12 transition-transform duration-700">storefront</span>
                </div>

                <div class="relative z-10 w-4/5">
                    <h3 class="text-xl font-extrabold leading-tight text-white mb-3 tracking-tight">Tingkatkan pengelolaan penjualan Anda ke level berikutnya.</h3>
                    <p class="text-white/50 text-xs font-medium leading-relaxed mb-6">Cara mudah mengelola penjualan dengan teliti dan presisi.</p>
                    <button class="bg-white text-[#37352F] px-5 py-2.5 rounded-xl text-xs font-bold transition-all duration-200 hover:bg-[#F7F7F5] hover:-translate-y-0.5 active:scale-95 shadow-sm whitespace-nowrap">
                        Cek pembaruan sekarang
                    </button>
                </div>
            </div>

            <!-- AI Predictive Restocking Widget -->
            <div class="card-surface p-6 flex flex-col h-full" x-data="{
                forecasts: [],
                loading: true,
                init() {
                    fetch('/api/analytics/restock-forecasts')
                        .then(res => res.json())
                        .then(data => {
                            this.forecasts = data.data.slice(0, 5); // top 5
                            this.loading = false;
                        })
                        .catch(err => {
                            console.error(err);
                            this.loading = false;
                        });
                }
            }">
                <div class="flex justify-between items-center mb-5">
                    <h2 class="text-lg font-bold text-[#37352F]">Prakiraan Restock AI</h2>
                    <span class="material-symbols-rounded text-[18px] text-[#9B9A97]">auto_awesome</span>
                </div>
                
                <div x-show="loading" class="flex justify-center items-center py-6">
                    <svg class="animate-spin h-5 w-5 text-[#9B9A97]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                
                <div x-show="!loading && forecasts.length === 0" class="text-xs text-[#787774] text-center py-4">
                    Belum ada data prediksi stok.
                </div>
                
                <div x-show="!loading && forecasts.length > 0" class="flex flex-col gap-4">
                    <template x-for="item in forecasts" :key="item.ingredient_id">
                        <div class="flex items-center justify-between border-b border-[#F1F1EF] pb-3 last:border-0 last:pb-0">
                            <div>
                                <h4 class="text-sm font-bold text-[#37352F]" x-text="item.name"></h4>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-[11px] text-[#787774]">Sisa: <strong class="text-[#37352F]" x-text="item.projected_days_remaining ? item.projected_days_remaining + ' hari' : '-'"></strong></span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <span x-show="item.trend === 'falling'" class="bg-rose-50 text-rose-700 border border-rose-200 rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wide uppercase leading-none">Turun</span>
                                <span x-show="item.trend === 'rising'" class="bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wide uppercase leading-none">Naik</span>
                                <span x-show="item.trend === 'stable'" class="bg-[#F1F1EF] text-[#787774] border border-[#E9E9E7] rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wide uppercase leading-none">Stabil</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts for ApexCharts -->
    <script>
        (function() {
            const renderCharts = () => {
            // 1. Sparkline: Net Income
            var incomeSparklineOptions = {
                series: [{ data: @json(array_slice($revenueData, -8, 8)) }],
                chart: { type: 'area', height: 60, sparkline: { enabled: true } },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0, stops: [0, 100] }
                },
                colors: ['#10b981'],
                tooltip: { fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: function () { return '' } } }, marker: { show: false } }
            };
            new ApexCharts(document.querySelector("#income-sparkline"), incomeSparklineOptions).render();

            // 2. Sparkline: Total Orders
            var returnSparklineOptions = {
                series: [{ data: @json(array_slice($ordersData, -8, 8)) }],
                chart: { type: 'area', height: 60, sparkline: { enabled: true } },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0, stops: [0, 100] }
                },
                colors: ['#3b82f6'],
                tooltip: { fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: function () { return '' } } }, marker: { show: false } }
            };
            new ApexCharts(document.querySelector("#return-sparkline"), returnSparklineOptions).render();

            // 3. Main Area Chart: Revenue
            var revenueOptions = {
                series: [{
                    name: "Income",
                    data: @json($revenueData)
                }, {
                    name: "Orders Count",
                    data: @json($ordersData)
                }],
                chart: {
                    height: 280,
                    type: 'area',
                    fontFamily: 'Plus Jakarta Sans, Inter, sans-serif',
                    toolbar: { show: false },
                    sparkline: { enabled: false }
                },
                colors: ['#37352F', '#E9E9E7'], 
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 2.5 },
                xaxis: {
                    categories: @json($chartDates),
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: { style: { colors: '#9B9A97', fontSize: '12px', fontWeight: 500, fontFamily: 'Plus Jakarta Sans, Inter, sans-serif' } }
                },
                yaxis: {
                    labels: { style: { colors: '#9B9A97', fontSize: '12px', fontWeight: 500, fontFamily: 'Plus Jakarta Sans, Inter, sans-serif' } }
                },
                grid: {
                    borderColor: '#F1F1EF',
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } }
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.15,
                        opacityTo: 0.0,
                        stops: [0, 100]
                    }
                },
                legend: { show: false }
            };
            new ApexCharts(document.querySelector("#revenue-chart"), revenueOptions).render();

            // 4. Donut Chart: Payment Methods
            var performanceOptions = {
                series: @json($paymentStats),
                labels: ['Cash', 'QRIS', 'E-Wallet'],
                chart: {
                    type: 'donut',
                    height: 260,
                    fontFamily: 'Plus Jakarta Sans, Inter, sans-serif'
                },
                colors: ['#9B9A97', '#37352F', '#f59e0b'],
                plotOptions: {
                    pie: {
                        donut: {
                            size: '75%',
                            labels: {
                                show: true,
                                name: { show: true, fontSize: '12px', fontWeight: 600, color: '#787774', offsetY: -5 },
                                value: { show: true, fontSize: '28px', fontWeight: 800, color: '#37352F', offsetY: 5, formatter: function (val) { return val } },
                                total: { show: true, showAlways: true, label: 'Total Lunas', fontSize: '12px', fontWeight: 600, color: '#787774', formatter: function (w) { return "{{ $totalPaidOrders }}" } }
                            }
                        },
                        customScale: 0.8,
                        dataLabels: { offset: 40 }
                    }
                },
                dataLabels: { 
                    enabled: true,
                    style: { fontSize: '12px', colors: ['#37352F'], fontWeight: 800 },
                    dropShadow: { enabled: false }
                },
                stroke: { show: true, width: 4, colors: ['transparent'] },
                legend: { show: false }
            };
            new ApexCharts(document.querySelector("#performance-chart"), performanceOptions).render();
            };

            // Support both standard load and SPA (wire:navigate)
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', renderCharts);
            } else {
                renderCharts();
            }
        })();
    </script>
</x-app-layout>
