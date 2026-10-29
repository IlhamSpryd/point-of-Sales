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
                <h1 class="text-2xl lg:text-[28px] font-bold text-[#171717] tracking-tight flex items-baseline gap-2">
                    <span>대시보드</span>
                    <span class="text-sm lg:text-base font-semibold text-[#737373] tracking-normal font-sans">(Dashboard Operasional)</span>
                </h1>
                
                <nav class="hidden md:flex items-center gap-1 text-xs font-medium text-[#737373]" aria-label="Sub-navigasi Cepat">
                    <a href="{{ route('inventory.ingredients') }}" wire:navigate class="px-2.5 py-1.5 rounded-lg hover:text-[#171717] hover:bg-[#F5F5F5] transition-colors">
                        물류 입출고 관리 (Bahan Baku)
                    </a>
                    <span class="text-[#D4D4D4]">•</span>
                    <a href="{{ route('orders.index') }}" wire:navigate class="px-2.5 py-1.5 rounded-lg hover:text-[#171717] hover:bg-[#F5F5F5] transition-colors">
                        수출 서류 작성 (Riwayat Pesanan)
                    </a>
                    <span class="text-[#D4D4D4]">•</span>
                    <a href="{{ route('products.index') }}" wire:navigate class="px-2.5 py-1.5 rounded-lg hover:text-[#171717] hover:bg-[#F5F5F5] transition-colors">
                        브랜드 관리 (Katalog Produk)
                    </a>
                    @if(in_array(auth()->user()?->role?->name, ['Owner', 'Manager'], true))
                    <span class="text-[#D4D4D4]">•</span>
                    <a href="{{ route('investor.demo') }}" wire:navigate class="px-2.5 py-1.5 rounded-lg hover:text-[#171717] hover:bg-[#F5F5F5] transition-colors">
                        바이어 관리 (Demo Investor)
                    </a>
                    @endif
                </nav>
            </div>

            <!-- Right: Action Buttons (Solid Dark & Outline Pill) -->
            <div class="flex items-center gap-2.5 shrink-0">
                <a href="{{ route('transaction.create') }}" wire:navigate
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[#171717] hover:bg-black text-white text-xs font-semibold rounded-xl shadow-xs transition-all duration-150 active:scale-[0.98] cursor-pointer">
                    <span class="material-symbols-rounded text-[18px]">receipt_long</span>
                    <span>전체주문 (Transaksi Baru)</span>
                </a>
                <a href="{{ route('products.index') }}" wire:navigate
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F9FAFB] text-[#171717] text-xs font-semibold rounded-xl border border-[#E5E7EB] hover:border-[#D4D4D4] shadow-xs transition-all duration-150 active:scale-[0.98] cursor-pointer">
                    <span class="material-symbols-rounded text-[18px]">inventory_2</span>
                    <span>전체상품 (Kelola Produk)</span>
                </a>
            </div>
        </header>

        {{-- 2. TOP 3 KPI CARDS (오늘 주문 / 이번주 주문 / 이번달 주문) --}}
        <section class="grid grid-cols-1 md:grid-cols-3 gap-5 lg:gap-6" aria-label="Ringkasan Pesanan Utama">
            {{-- Card 1: 오늘 주문 (Pesanan Hari Ini) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">오늘 주문 (Pesanan Hari Ini)</span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                        <span class="material-symbols-rounded text-[14px]">trending_up</span> +1.9%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ $displayTodayOrders }}
                </div>
            </div>

            {{-- Card 2: 이번주 주문 (Pesanan Minggu Ini) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">이번주 주문 (Pesanan Minggu Ini)</span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-600 border border-rose-200">
                        <span class="material-symbols-rounded text-[14px]">trending_down</span> -8.1%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ $displayWeekOrders }}
                </div>
            </div>

            {{-- Card 3: 이번달 주문 (Pesanan Bulan Ini) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs lg:text-sm font-medium text-[#737373]">이번달 주문 (Pesanan Bulan Ini)</span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                        <span class="material-symbols-rounded text-[14px]">trending_up</span> +24%
                    </span>
                </div>
                <div class="mt-4 text-3xl lg:text-4xl font-extrabold text-[#171717] tracking-tight">
                    {{ number_format($displayMonthOrders, 0, ',', '.') }}
                </div>
            </div>
        </section>

        {{-- 3. MIDDLE MAIN SECTION: SALES PERFORMANCE & 31-DAY BAR HISTOGRAM (세일즈 실적) --}}
        <section class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-6 lg:p-8 shadow-xs relative" aria-label="Performa Penjualan Bulanan">
            {{-- Header of Section --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <div class="text-xs lg:text-sm font-medium text-[#737373]">세일즈 실적 (Performa Penjualan)</div>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <div class="text-2xl lg:text-3xl font-extrabold text-[#171717] tracking-tight">
                            Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                            <span class="material-symbols-rounded text-[15px]">trending_up</span> +1.9%
                        </span>
                    </div>
                    <div class="text-xs text-[#737373] mt-1 font-mono">
                        {{ number_format($totalEarnings, 0, ',', '.') }} IDR · Akumulasi Real-Time
                    </div>
                </div>

                {{-- Chart Controls: Icons + Segmented Pills --}}
                <div class="flex items-center gap-3 shrink-0">
                    {{-- Mini Chart Toggle Icons --}}
                    <div class="flex items-center gap-1 bg-[#F5F5F5] p-1 rounded-xl border border-[#E5E7EB]">
                        <button type="button" class="w-8 h-8 rounded-lg bg-white text-[#171717] shadow-xs flex items-center justify-center cursor-pointer" title="Histogram Bar">
                            <span class="material-symbols-rounded text-[18px]">bar_chart</span>
                        </button>
                        <button type="button" class="w-8 h-8 rounded-lg text-[#737373] hover:text-[#171717] flex items-center justify-center cursor-pointer" title="Kurva Spline">
                            <span class="material-symbols-rounded text-[18px]">show_chart</span>
                        </button>
                    </div>

                    {{-- Segmented Time Filter --}}
                    <div class="inline-flex items-center bg-[#F5F5F5] p-1 rounded-xl border border-[#E5E7EB] text-xs font-medium">
                        <button type="button" class="px-3.5 py-1.5 rounded-lg bg-[#171717] text-white font-semibold shadow-xs">
                            월간 (Bulanan)
                        </button>
                        <button type="button" class="px-3 py-1.5 rounded-lg text-[#737373] hover:text-[#171717] transition-colors">
                            주간 (Mingguan)
                        </button>
                        <button type="button" class="px-3 py-1.5 rounded-lg text-[#737373] hover:text-[#171717] transition-colors">
                            연간 (Tahunan)
                        </button>
                    </div>
                </div>
            </div>

            {{-- 31-Day Bar Histogram Chart --}}
            <div class="relative w-full pt-16 pb-2 overflow-x-auto scrollbar-hide">
                {{-- Floating Tooltip Speech Bubble (Directly above active day 17) --}}
                @php
                    $activeColIndex = array_search($activeDay, array_column($monthlyHistogram, 'day'));
                    if ($activeColIndex === false) { $activeColIndex = 16; }
                    $tooltipLeftPercent = (($activeColIndex + 0.5) / 31) * 100;
                @endphp
                <div class="absolute top-1 -translate-x-1/2 pointer-events-none transition-all duration-200 z-10 hidden sm:block"
                     style="left: {{ $tooltipLeftPercent }}%;">
                    <div class="bg-white text-[#171717] border border-[#171717] rounded-xl px-3.5 py-2 shadow-xl text-center whitespace-nowrap">
                        <div class="text-[11px] font-semibold text-[#525252]">{{ $activeDayDate }}</div>
                        <div class="text-xs font-bold text-[#171717] mt-0.5">수익 {{ $activeDayFormatted }}</div>
                    </div>
                    {{-- Triangle Indicator --}}
                    <div class="w-0 h-0 border-x-[6px] border-x-transparent border-t-[7px] border-t-[#171717] mx-auto"></div>
                </div>

                {{-- 31 Bars Grid --}}
                <div class="items-end h-56 lg:h-64 min-w-[680px]"
                     style="display: grid; grid-template-columns: repeat(31, minmax(0, 1fr)); gap: 6px;">
                    @foreach($monthlyHistogram as $bar)
                        <div class="flex flex-col items-center h-full justify-end group cursor-pointer relative"
                             title="Tgl {{ $bar['day'] }}: {{ $bar['formatted'] }} ({{ $bar['count'] }} transaksi)">
                            
                            {{-- Hover Tooltip for inactive bars --}}
                            @if(!$bar['is_active'])
                                <div class="absolute bottom-full mb-2 hidden group-hover:flex flex-col items-center -translate-x-1/2 left-1/2 pointer-events-none z-20 whitespace-nowrap bg-[#171717] text-white text-[10px] rounded-lg px-2 py-1 shadow-lg">
                                    <span>Tgl {{ $bar['day'] }}: {{ $bar['formatted'] }}</span>
                                </div>
                            @endif

                            {{-- The Bar --}}
                            <div class="w-full rounded-t-[4px] transition-all duration-200 group-hover:opacity-80"
                                 style="height: {{ $bar['height'] }}%; {{ $bar['is_active'] ? 'background: repeating-linear-gradient(45deg, #171717, #171717 3px, #ffffff 3px, #ffffff 6px); border: 1.5px solid #171717;' : 'background-color: #171717;' }}">
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- X-Axis Labels 1 to 31 --}}
                <div class="mt-2 min-w-[680px] border-t border-[#F0F0F0] pt-2"
                     style="display: grid; grid-template-columns: repeat(31, minmax(0, 1fr)); gap: 6px;">
                    @foreach($monthlyHistogram as $bar)
                        <div class="text-center text-[10px] {{ $bar['is_active'] ? 'font-bold text-[#171717]' : 'text-[#737373]' }}">
                            {{ $bar['day'] }}
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- 4. BOTTOM ROW: 3 FEATURE CARDS (분기 실적 / 물류 실적 / 서비스 실적) --}}
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:gap-6" aria-label="Analisis Kinerja Layanan & Logistik">
            {{-- Card 1: 분기 실적 (Performa Kuartal) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div>
                    <div class="text-xs lg:text-sm font-medium text-[#737373]">분기 실적 (Performa Kuartal)</div>
                    <div class="flex items-center gap-2.5 mt-1.5">
                        <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                            Rp {{ number_format($quarterEarnings, 0, ',', '.') }}
                        </div>
                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-600 border border-rose-200">
                            <span class="material-symbols-rounded text-[13px]">trending_down</span> {{ $quarterDeltaPercent }}%
                        </span>
                    </div>
                    <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                        144,094,882 IDR · Target Kuartal
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

            {{-- Card 2: 물류 실적 (Stok & Logistik Bahan Baku) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs lg:text-sm font-medium text-[#737373]">물류 실적 (Logistik & Bahan Baku)</div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                                {{ $lowStockCount }} Bahan Kritis
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +2.1%
                            </span>
                        </div>
                        <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                            {{ $productsCount }} Produk Aktif Terpantau
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

            {{-- Card 3: 서비스 실적 (Saluran Transaksi & Layanan) --}}
            <div class="bg-white rounded-2xl lg:rounded-3xl border border-[#E5E7EB] p-5 lg:p-6 shadow-xs flex flex-col justify-between hover:border-[#D4D4D4] transition-all">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs lg:text-sm font-medium text-[#737373]">서비스 실적 (Saluran Transaksi)</div>
                        <div class="flex items-center gap-2.5 mt-1.5">
                            <div class="text-xl lg:text-2xl font-bold text-[#171717] tracking-tight">
                                {{ number_format($totalPaidOrders, 0, ',', '.') }} Transaksi
                            </div>
                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600 border border-emerald-200">
                                <span class="material-symbols-rounded text-[13px]">trending_up</span> +0.4%
                            </span>
                        </div>
                        <div class="text-[11px] text-[#737373] mt-0.5 font-mono">
                            QRIS {{ $paymentBreakdown['qris']['percent'] ?? 0 }}% · Tunai {{ $paymentBreakdown['cash']['percent'] ?? 0 }}%
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
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-4 border-t border-[#E5E7EB]" aria-label="Operasional & Inventori Terkini">
            {{-- Left: Transaksi Terbaru --}}
            <div class="bg-white rounded-2xl border border-[#E5E7EB] p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-[#171717] flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px] text-[#737373]">receipt_long</span>
                        <span>Transaksi Terbaru</span>
                    </h2>
                    <a href="{{ route('orders.index') }}" wire:navigate class="text-xs font-semibold text-[#737373] hover:text-[#171717] transition-colors flex items-center gap-0.5">
                        Lihat semua <span class="material-symbols-rounded text-[14px]">chevron_right</span>
                    </a>
                </div>

                <div class="divide-y divide-[#F0F0F0]">
                    @forelse($recentOrders as $order)
                        @php
                            $customerName = $order->customer?->name ?? $order->user?->name ?? 'Pelanggan Ritel';
                            $initial = strtoupper(substr($customerName, 0, 1));
                            $storeName = $order->store?->name ?? ($activeStoreName ?? 'Cabang');
                            $methodRaw = $order->payment_method instanceof \BackedEnum ? $order->payment_method->value : (string) ($order->payment_method ?? '');
                            $paymentLabel = match(strtolower($methodRaw)) {
                                'cash' => 'Tunai',
                                'qris' => 'QRIS',
                                'ewallet' => 'E-Wallet',
                                'card' => 'Kartu',
                                default => !empty($methodRaw) ? strtoupper($methodRaw) : 'Tunai'
                            };
                            $dateStr = $order->created_at 
                                ? $order->created_at->format('j') . ' ' . (\App\Services\DashboardService::ID_MONTHS[$order->created_at->month - 1] ?? '') . ', ' . $order->created_at->format('H:i') 
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
                                    <span class="inline-block mt-0.5 px-2 py-0.2 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Lunas</span>
                                @elseif($statusVal === 'pending')
                                    <span class="inline-block mt-0.5 px-2 py-0.2 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">Menunggu</span>
                                @else
                                    <span class="inline-block mt-0.5 px-2 py-0.2 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">Batal</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-xs text-[#737373]">
                            Belum ada transaksi hari ini
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Right: Prakiraan Restock Bahan Baku --}}
            <div class="bg-white rounded-2xl border border-[#E5E7EB] p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-[#171717] flex items-center gap-2">
                        <span class="material-symbols-rounded text-[18px] text-[#737373]">warehouse</span>
                        <span>Prakiraan Restock Bahan Baku</span>
                    </h2>
                    <a href="{{ route('inventory.ingredients') }}" wire:navigate class="text-xs font-semibold text-[#737373] hover:text-[#171717] transition-colors flex items-center gap-0.5">
                        Kelola stok <span class="material-symbols-rounded text-[14px]">chevron_right</span>
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
                            Seluruh persediaan bahan baku dalam batas aman
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

    </div>
</x-app-layout>
