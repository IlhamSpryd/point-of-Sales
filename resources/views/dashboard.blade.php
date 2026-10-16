<x-app-layout :noPadding="true">
    <div class="w-full min-h-full bg-white flex flex-col flex-1">
        {{-- TOP BAR --}}
        <header class="h-14 border-b border-[#E9E9E7] flex items-center justify-between px-6 sm:px-8 lg:px-12 shrink-0 bg-white sticky top-0 z-20">
            <div class="flex items-center gap-3">
                {{-- Mobile Drawer Trigger (disembunyikan di desktop) --}}
                <button type="button" @click="sidebarMobileOpen = true" aria-label="Buka menu"
                    class="lg:hidden w-9 h-9 flex flex-col justify-center gap-1 items-center rounded-lg hover:bg-[#F1F1EF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]">
                    <i class="block w-4 h-0.5 bg-[#37352F] rounded-full"></i>
                    <i class="block w-4 h-0.5 bg-[#37352F] rounded-full"></i>
                    <i class="block w-4 h-0.5 bg-[#37352F] rounded-full"></i>
                </button>
                <span class="font-semibold text-sm text-[#202020]">Dashboard</span>
            </div>

            <div class="flex items-center gap-3">
                {{-- Date Range Pill --}}
                <span class="inline-flex items-center font-medium text-xs text-[#6B6A66] border border-[#E9E9E7] rounded-full px-3 py-1.5 bg-white shadow-2xs">
                    {{ $dateRangeLabel ?? '7 hari terakhir' }}
                </span>
            </div>
        </header>

        {{-- MAIN CONTENT CONTAINER (FULL WIDTH) --}}
        <div class="w-full py-6 px-6 sm:px-8 lg:px-12 flex flex-col gap-8 lg:gap-11 bg-white flex-1">
        
        {{-- GREETING & SUMMARY --}}
        <header>
            <h1 class="text-2xl sm:text-[30px] font-semibold text-[#202020] tracking-tight leading-tight">
                {{ $greeting ?? 'Selamat datang' }}, {{ $firstName ?? ($userName ?? 'Owner') }}
            </h1>
            <p class="text-sm sm:text-base text-[#55544E] mt-2.5 max-w-[720px] leading-relaxed">
                {!! $summaryText !!}
            </p>
        </header>

        {{-- PRIMARY KPI: TOTAL PENDAPATAN + 7-DAY REVENUE SVG CHART --}}
        <section class="flex flex-col">
            <div class="text-[13px] text-[#6B6A66] font-medium">
                Total pendapatan · {{ $isAllStores ? 'seluruh cabang' : ($activeStoreName ?? 'Cabang') }}
            </div>
            <div class="text-[32px] sm:text-[42px] font-semibold text-[#37352F] tracking-tight leading-none my-2 font-mono">
                Rp {{ number_format($totalEarnings, 0, ',', '.') }}
            </div>
            <div class="flex items-center gap-2 mb-4">
                @if($earningsDeltaPercent !== null)
                    <span class="delta-badge {{ $earningsDeltaPercent < 0 ? 'negative' : '' }}">
                        {{ $earningsDeltaPercent >= 0 ? '+' : '' }}{{ number_format($earningsDeltaPercent, 1, ',', '.') }}%
                    </span>
                    <span class="text-xs text-[#6B6A66]">bulan ini vs bulan lalu</span>
                @else
                    <span class="text-xs text-[#6B6A66]">Akumulasi seluruh waktu</span>
                @endif
            </div>

            {{-- 7-DAY REVENUE CHART (SVG) --}}
            <div class="mt-4 w-full relative" x-data="{
                activeIdx: {{ count($chartSvg['desktop_points']) - 1 }},
                points: {{ json_encode($chartSvg['desktop_points']) }},
                setActive(idx) { this.activeIdx = idx; }
            }">
                {{-- Desktop SVG Chart --}}
                <div class="hidden sm:block w-full">
                    <svg viewBox="0 0 880 230" class="w-full h-auto overflow-visible select-none" role="img" aria-label="Grafik pendapatan harian 7 hari terakhir">
                        <defs>
                            <linearGradient id="revenueGradientDesktop" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#37352F" stop-opacity="0.12"></stop>
                                <stop offset="1" stop-color="#37352F" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>

                        {{-- Grid Lines --}}
                        @foreach($chartSvg['desktop_grid'] as $g)
                            <line x1="30" x2="870" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}" stroke="#ECECEA" stroke-dasharray="{{ $loop->first ? '0' : '3 4' }}"></line>
                            <text x="0" y="{{ $g['y'] + 4 }}" font-size="11" fill="#6B6A66" font-family="'Plus Jakarta Sans', sans-serif">{{ $g['label'] }}</text>
                        @endforeach

                        {{-- Gradient Area Fill --}}
                        @if(!empty($chartSvg['desktop_fill']))
                            <path d="{{ $chartSvg['desktop_fill'] }}" fill="url(#revenueGradientDesktop)"></path>
                        @endif

                        {{-- Line Stroke --}}
                        @if(!empty($chartSvg['desktop_path']))
                            <path d="{{ $chartSvg['desktop_path'] }}" fill="none" stroke="#37352F" stroke-width="2.25" stroke-linecap="round"></path>
                        @endif

                        {{-- Interactive Hovered Point & Tooltip --}}
                        <template x-if="points[activeIdx]">
                            <g>
                                <circle :cx="points[activeIdx].x" :cy="points[activeIdx].y" r="8" fill="#37352F" fill-opacity=".1" class="transition-all duration-150"></circle>
                                <circle :cx="points[activeIdx].x" :cy="points[activeIdx].y" r="4" fill="#ffffff" stroke="#37352F" stroke-width="2" class="transition-all duration-150"></circle>
                                <rect :x="Math.min(766, Math.max(10, points[activeIdx].x - 56))" :y="Math.max(8, points[activeIdx].y - 30)" width="112" height="22" rx="6" fill="#37352F" class="transition-all duration-150"></rect>
                                <text :x="Math.min(766, Math.max(10, points[activeIdx].x - 56)) + 56" :y="Math.max(8, points[activeIdx].y - 30) + 15" font-size="11" font-weight="600" fill="#ffffff" text-anchor="middle" font-family="'Plus Jakarta Sans', sans-serif" x-text="points[activeIdx].date + ' · ' + points[activeIdx].formatted" class="transition-all duration-150"></text>
                            </g>
                        </template>

                        {{-- X-Axis Date Labels & Hover Hit-boxes --}}
                        @foreach($chartSvg['desktop_points'] as $idx => $p)
                            <text x="{{ $p['x'] }}" y="224" font-size="11" fill="#6B6A66" text-anchor="middle" font-family="'Plus Jakarta Sans', sans-serif">{{ $p['date'] }}</text>
                            <rect x="{{ $p['x'] - 70 }}" y="0" width="140" height="230" fill="transparent" class="cursor-pointer" @mouseenter="setActive({{ $idx }})"></rect>
                        @endforeach
                    </svg>
                </div>

                {{-- Mobile SVG Chart (< 640px) --}}
                <div class="sm:hidden w-full">
                    <svg viewBox="0 0 358 150" class="w-full h-auto overflow-visible select-none" role="img" aria-label="Grafik pendapatan harian mobile">
                        {{-- Mobile Grid Lines --}}
                        @foreach($chartSvg['mobile_grid'] as $g)
                            <line x1="34" x2="350" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}" stroke="#E9E9E7" stroke-width="1"></line>
                            <text x="0" y="{{ $g['y'] + 4 }}" font-size="11" fill="#6B6A66" font-family="'Plus Jakarta Sans', sans-serif">{{ $g['label'] }}</text>
                        @endforeach

                        {{-- Mobile Polygon Fill --}}
                        @if(!empty($chartSvg['mobile_polygon']))
                            <polygon points="{{ $chartSvg['mobile_polygon'] }}" fill="#F1F1EF"></polygon>
                        @endif

                        {{-- Mobile Polyline Stroke --}}
                        @if(!empty($chartSvg['mobile_polyline']))
                            <polyline points="{{ $chartSvg['mobile_polyline'] }}" fill="none" stroke="#37352F" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                        @endif

                        {{-- Active Point Marker --}}
                        @if(!empty($chartSvg['mobile_points']))
                            @php $lastMobile = end($chartSvg['mobile_points']); @endphp
                            <circle cx="{{ $lastMobile['x'] }}" cy="{{ $lastMobile['y'] }}" r="3.5" fill="#37352F"></circle>
                        @endif

                        {{-- Mobile X-Axis Labels --}}
                        @foreach($chartSvg['mobile_points'] as $p)
                            <text x="{{ $p['x'] }}" y="145" font-size="11" fill="#6B6A66" text-anchor="middle" font-family="'Plus Jakarta Sans', sans-serif">{{ $p['date'] }}</text>
                        @endforeach
                    </svg>
                </div>
            </div>
        </section>

        {{-- 3 SECONDARY KPI CARDS --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-6 sm:gap-10 border-t border-[#ECECEA] pt-4">
            {{-- 1. Total Transaksi --}}
            <div>
                <div class="text-[13px] text-[#6B6A66]">Total transaksi</div>
                <div class="text-2xl sm:text-[24px] font-semibold text-[#37352F] tracking-tight my-1">
                    {{ number_format($totalOrders, 0, ',', '.') }}
                </div>
                <div>
                    @if($ordersDeltaPercent !== null)
                        <span class="delta-badge {{ $ordersDeltaPercent < 0 ? 'negative' : '' }}">
                            {{ $ordersDeltaPercent >= 0 ? '+' : '' }}{{ number_format($ordersDeltaPercent, 1, ',', '.') }}%
                        </span>
                        <span class="text-xs text-[#6B6A66]">vs bulan lalu</span>
                    @else
                        <span class="text-xs text-[#6B6A66]">Akumulasi seluruh waktu</span>
                    @endif
                </div>
            </div>

            {{-- 2. Terjual Bulan Ini --}}
            <div>
                <div class="text-[13px] text-[#6B6A66]">Terjual bulan ini</div>
                <div class="text-2xl sm:text-[24px] font-semibold text-[#37352F] tracking-tight my-1">
                    {{ number_format($soldThisMonth, 0, ',', '.') }}
                </div>
                <div class="text-[13px] text-[#6B6A66]">item, status lunas</div>
            </div>

            {{-- 3. Stok Tipis --}}
            <div class="col-span-2 sm:col-span-1">
                <div class="text-[13px] text-[#6B6A66]">Stok tipis</div>
                <div class="text-2xl sm:text-[24px] font-semibold text-[#37352F] tracking-tight my-1 flex items-center gap-2">
                    <span class="dot-warning"></span>
                    {{ number_format($lowStockCount, 0, ',', '.') }}
                </div>
                <div class="text-[13px] text-[#6B6A66]">dari {{ number_format($productsCount, 0, ',', '.') }} produk</div>
            </div>
        </div>

        {{-- TWO-COLUMN SECTION: TRANSAKSI TERBARU & METODE PEMBAYARAN / RESTOCK --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1.25fr_1fr] gap-8 lg:gap-14">
            
            {{-- LEFT COLUMN: TRANSAKSI TERBARU --}}
            <section class="flex flex-col">
                <h2 class="text-sm font-semibold text-[#202020] mb-3">Transaksi terbaru</h2>

                <div class="flex flex-col">
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
                        <div class="dash-list-item">
                            <span class="w-8 h-8 rounded-full bg-[#F1F1EF] text-[#202020] flex items-center justify-center font-semibold text-xs shrink-0">
                                {{ $initial }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-[#202020] truncate">{{ $customerName }}</div>
                                <div class="text-xs text-[#6B6A66] truncate mt-0.5">{{ $storeName }} · {{ $paymentLabel }} · {{ $dateStr }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-semibold text-sm text-[#202020]">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</div>
                                @if($statusVal === 'paid' || $statusVal === 'completed')
                                    <span class="chip ok mt-0.5">Lunas</span>
                                @elseif($statusVal === 'pending')
                                    <span class="chip wt mt-0.5">Menunggu</span>
                                @else
                                    <span class="chip danger mt-0.5">Batal</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center bg-[#F9F9F9] rounded-2xl border border-[#E9E9E7]/60">
                            <p class="font-semibold text-sm text-[#202020]">Belum ada transaksi</p>
                            <p class="text-xs text-[#6B6A66] mt-1">Transaksi lunas akan muncul di sini setelah kasir menyelesaikan pesanan.</p>
                        </div>
                    @endforelse
                </div>

                <div class="mt-2">
                    <a href="{{ route('orders.index') }}" wire:navigate class="inline-block font-semibold text-[13px] text-[#202020] hover:underline">
                        Lihat semua pesanan →
                    </a>
                </div>
            </section>

            {{-- RIGHT COLUMN: METODE PEMBAYARAN & PRAKIRAAN RESTOCK --}}
            <div class="flex flex-col gap-8">
                {{-- METODE PEMBAYARAN --}}
                <section>
                    <h2 class="text-sm font-semibold text-[#202020] mb-3">Metode pembayaran</h2>

                    {{-- Stacked Horizontal Bar --}}
                    <div class="flex h-2.5 rounded-full overflow-hidden gap-0.5 bg-[#F1F1EF]">
                        @if($paymentBreakdown['total'] > 0)
                            <span style="width: {{ $paymentBreakdown['cash']['percent'] }}%; background-color: #C4C3C0;" title="Tunai: {{ $paymentBreakdown['cash']['percent'] }}%"></span>
                            <span style="width: {{ $paymentBreakdown['qris']['percent'] }}%; background-color: #37352F;" title="QRIS: {{ $paymentBreakdown['qris']['percent'] }}%"></span>
                            <span style="width: {{ $paymentBreakdown['ewallet']['percent'] }}%; background-color: #787774;" title="E-Wallet: {{ $paymentBreakdown['ewallet']['percent'] }}%"></span>
                            <span style="width: {{ $paymentBreakdown['card']['percent'] }}%; background-color: #E3E2E0;" title="Kartu: {{ $paymentBreakdown['card']['percent'] }}%"></span>
                        @else
                            <span style="width: 100%; background-color: #E9E9E7;"></span>
                        @endif
                    </div>

                    {{-- Legend List --}}
                    <div class="mt-2.5 space-y-1">
                        @foreach(['cash' => $paymentBreakdown['cash'], 'qris' => $paymentBreakdown['qris'], 'ewallet' => $paymentBreakdown['ewallet'], 'card' => $paymentBreakdown['card']] as $key => $pm)
                            <div class="flex items-center gap-2 py-1 text-[13px]">
                                <span class="w-2.5 h-2.5 rounded-xs shrink-0 border border-black/10" style="background-color: {{ $pm['color'] }};"></span>
                                <span class="flex-1 text-[#37352F]">{{ $pm['label'] }}</span>
                                <span class="text-[#6B6A66]">{{ number_format($pm['count'], 0, ',', '.') }} · {{ $pm['percent'] }}%</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- PRAKIRAAN RESTOCK --}}
                <section>
                    <h2 class="text-sm font-semibold text-[#202020] mb-3">
                        Prakiraan restock <span class="text-[#6B6A66] font-normal">· sisa hari</span>
                    </h2>

                    <div class="flex flex-col divide-y divide-[#F1F1EF]">
                        @forelse($restockList as $item)
                            <div class="flex items-center gap-3 py-2.5 text-[13px]">
                                <span class="flex-1 text-[#37352F] truncate font-medium">{{ $item['name'] }}</span>
                                <div class="restock-bar">
                                    <i style="width: {{ $item['progress_percent'] }}%;"></i>
                                </div>
                                <span class="text-[#6B6A66] w-14 text-right shrink-0">{{ $item['days_label'] }}</span>
                                <span style="color: {{ $item['trend_color'] }};" class="w-11 text-right font-semibold text-xs shrink-0">
                                    {{ $item['trend_label'] }}
                                </span>
                            </div>
                        @empty
                            <div class="py-4 text-center">
                                <p class="font-semibold text-xs text-[#202020]">Prakiraan restock tidak tersedia</p>
                                <p class="text-xs text-[#6B6A66] mt-0.5">Semua stok bahan baku saat ini berada di level aman.</p>
                            </div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

    </div>
    </div>
</x-app-layout>
