<x-app-layout>
    <div x-data="{ openEodModal: false }">
        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">{{ $title }}</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Ringkasan transaksi, paritas pendapatan, dan penutupan buku kas harian</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                <form method="GET" action="{{ route('reports.sales') }}" class="flex items-center gap-2">
                    <input type="date" name="start" value="{{ $customStart->toDateString() }}"
                        class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-apeiron-ink">
                    <span class="text-xs text-slate-400">s/d</span>
                    <input type="date" name="end" value="{{ $customEnd->toDateString() }}"
                        class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-apeiron-ink">
                    <button type="submit"
                        class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700">
                        Filter
                    </button>
                </form>
                @php
                    $startDate = request('start', $customStart->toDateString());
                    $endDate = request('end', $customEnd->toDateString());
                @endphp
                <livewire:async-export-button :start-date="$startDate" :end-date="$endDate" />
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-[20px] text-emerald-600">check_circle</span>
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-[20px] text-rose-600">error</span>
                {{ session('error') }}
            </div>
        @endif

        {{-- EOD / Z-Report Closing Section --}}
        @if ($eodSettlement && $eodSettlement->status === 'closed')
            <div class="card-surface p-6 mb-6 border-l-4 border-l-emerald-500">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#E9E9E7] pb-4 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-1 rounded-full text-xs font-extrabold bg-emerald-100 text-emerald-800 tracking-wider">
                                CLOSED (LOCKED)
                            </span>
                            <h3 class="text-base font-bold text-apeiron-ink">End-of-Day (Z-Report) — {{ $eodSettlement->business_date->format('d M Y') }}</h3>
                        </div>
                        <p class="text-xs text-[#787774] mt-1">
                            Buku kas telah dikunci pada {{ $eodSettlement->closed_at?->format('d M Y, H:i') }} WIB oleh <strong>{{ $eodSettlement->closedByUser?->name ?? 'Manager' }}</strong>
                        </p>
                    </div>
                    @if ($eodSettlement->notes)
                        <div class="text-xs text-[#787774] italic bg-slate-50 p-2.5 rounded-lg border border-slate-200 max-w-xs">
                            "{{ $eodSettlement->notes }}"
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                        <span class="text-xs text-[#787774] font-semibold uppercase block mb-1">Kas Laci Diharapkan</span>
                        <span class="text-lg font-bold text-apeiron-ink tabular-nums">Rp {{ number_format($eodSettlement->expected_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                        <span class="text-xs text-[#787774] font-semibold uppercase block mb-1">Fisik Kas Dihitung</span>
                        <span class="text-lg font-bold text-apeiron-ink tabular-nums">Rp {{ number_format($eodSettlement->counted_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                        <span class="text-xs text-[#787774] font-semibold uppercase block mb-1">Selisih Kas Total</span>
                        <span class="text-lg font-bold tabular-nums {{ (float)$eodSettlement->cash_discrepancy_total == 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            Rp {{ number_format($eodSettlement->cash_discrepancy_total, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
            </div>
        @else
            <div class="card-surface p-6 mb-6 border-l-4 border-l-amber-500 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 tracking-wider">
                            BUKU KAS AKTIF (OPEN)
                        </span>
                        <h3 class="text-base font-bold text-apeiron-ink">Penutupan Buku Akhir Hari (EOD / Z-Report)</h3>
                    </div>
                    <p class="text-xs text-[#787774] mt-1">
                        Lakukan penutupan buku harian untuk tanggal bisnis <strong>{{ $businessDate }}</strong> setelah seluruh shift kasir selesai.
                    </p>
                </div>
                <button type="button" @click="openEodModal = true"
                    class="min-h-[44px] px-5 rounded-xl bg-apeiron-ink text-white text-sm font-bold hover:bg-black transition-all flex items-center justify-center gap-2 shadow-sm shrink-0">
                    <span class="material-symbols-rounded text-[18px]">lock</span> Tutup EOD / Z-Report
                </button>
            </div>
        @endif

        {{-- Detailed Financial Summary Cards --}}
        <div class="mb-4">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Ringkasan Keuangan Terinci (Audit Paritas Penjualan)</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-[#787774] uppercase block mb-1">Gross Sales</span>
                <h4 class="text-xl font-extrabold text-apeiron-ink tabular-nums">Rp {{ number_format($detailedSummary['gross_sales'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">{{ $detailedSummary['paid_orders_count'] }} Order Lunas</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-rose-600 uppercase block mb-1">Total Diskon</span>
                <h4 class="text-xl font-extrabold text-rose-600 tabular-nums">- Rp {{ number_format($detailedSummary['total_discounts'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">Item + Voucher</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-amber-700 uppercase block mb-1">Total Retur &amp; Refund</span>
                <h4 class="text-xl font-extrabold text-amber-700 tabular-nums">- Rp {{ number_format($detailedSummary['total_returns_refunds'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">Kompensasi Pengembalian</span>
            </div>

            <div class="card-surface p-5 bg-emerald-50/40 border-emerald-200">
                <span class="text-xs font-bold text-emerald-800 uppercase block mb-1">Net Sales (Pendapatan Bersih)</span>
                <h4 class="text-2xl font-black text-emerald-700 tabular-nums">Rp {{ number_format($detailedSummary['net_sales'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-emerald-600 mt-1 block">Gross - Diskon - Retur</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-[#787774] uppercase block mb-1">Total Pajak (PB1)</span>
                <h4 class="text-xl font-extrabold text-apeiron-ink tabular-nums">Rp {{ number_format($detailedSummary['total_tax'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">Kewajiban Perpajakan</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-[#787774] uppercase block mb-1">Total Service Charge</span>
                <h4 class="text-xl font-extrabold text-apeiron-ink tabular-nums">Rp {{ number_format($detailedSummary['total_service_charge'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">Biaya Layanan Resto</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-rose-500 uppercase block mb-1">Total Void</span>
                <h4 class="text-xl font-extrabold text-rose-500 tabular-nums">Rp {{ number_format($detailedSummary['total_voids'], 0, ',', '.') }}</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">{{ $detailedSummary['voided_orders_count'] }} Order Dibatalkan</span>
            </div>

            <div class="card-surface p-5">
                <span class="text-xs font-semibold text-[#787774] uppercase block mb-1">Volume Transaksi</span>
                <h4 class="text-xl font-extrabold text-apeiron-ink tabular-nums">{{ $detailedSummary['paid_orders_count'] }} Transaksi</h4>
                <span class="text-[11px] text-slate-400 mt-1 block">Dalam periode terpilih</span>
            </div>
        </div>

        {{-- Payment Method Breakdown --}}
        @if ($paymentBreakdown && $paymentBreakdown->isNotEmpty())
            <div class="mb-8">
                <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider mb-4">Rincian Pembayaran Per Metode</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($paymentBreakdown as $pay)
                        <div class="card-surface p-4 flex justify-between items-center">
                            <div>
                                <span class="text-xs text-slate-500 uppercase font-bold block">{{ is_string($pay->payment_method) ? $pay->payment_method : ($pay->payment_method->label() ?? $pay->payment_method->value) }}</span>
                                <span class="text-xs text-slate-400">{{ $pay->count }} Transaksi</span>
                            </div>
                            <span class="text-base font-bold text-apeiron-ink tabular-nums">Rp {{ number_format($pay->total, 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Transaction Table -->
        <div class="card-surface overflow-hidden shrink-0">
            <header class="px-6 py-4 border-b border-[#E9E9E7]">
                <h2 class="text-lg font-bold text-apeiron-ink">Daftar Transaksi Terbaru</h2>
            </header>
            <div class="overflow-x-auto">
                <x-data-table>
                    <x-slot:head>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kode Pesanan</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Tanggal</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kasir</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Nilai Transaksi</th>
                    </x-slot:head>
                    
                    @forelse ($recentOrders as $order)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4">
                            <span class="text-sm font-medium text-apeiron-ink font-mono">{{ $order->order_code }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ \Carbon\Carbon::parse($order->created_at)->format('d-m-Y H:i') }}</td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ $order->user->name ?? 'Kasir' }}</td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-sm font-bold text-apeiron-ink tabular-nums">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                    <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">receipt_long</span>
                                </div>
                                <p class="font-medium">Belum ada transaksi di periode ini.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </x-data-table>
            </div>
            <div class="p-4 border-t border-apeiron-border shrink-0">
                {{ $recentOrders->links() }}
            </div>
        </div>

        {{-- Modal Close EOD --}}
        <div x-show="openEodModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-apeiron-ink/40 backdrop-blur-sm p-4"
            role="dialog" aria-modal="true" aria-labelledby="eod-modal-title">
            <div class="bg-white rounded-2xl w-full max-w-md p-6 shadow-2xl border border-[#E9E9E7]">
                <h3 id="eod-modal-title" class="text-lg font-bold text-apeiron-ink mb-1">Tutup Buku End-of-Day (Z-Report)</h3>
                <p class="text-xs text-[#787774] mb-4">Aksi ini akan mengunci seluruh transaksi untuk tanggal bisnis dan menghasilkan ringkasan settlement.</p>

                <form action="{{ route('reports.eod-close') }}" method="POST">
                    @csrf
                    <div class="space-y-4 mb-5">
                        <div>
                            <label for="business_date_input" class="block text-xs font-semibold text-slate-700 uppercase mb-1">Tanggal Bisnis</label>
                            <input type="date" id="business_date_input" name="business_date" value="{{ $businessDate }}" required
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm shadow-sm bg-slate-50">
                        </div>

                        <div>
                            <label for="eod_pin_input" class="block text-xs font-semibold text-slate-700 uppercase mb-1">PIN Manager / Supervisor *</label>
                            <input type="password" id="eod_pin_input" name="pin" required maxlength="6" placeholder="4-6 digit PIN"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-apeiron-ink">
                        </div>

                        <div>
                            <label for="eod_notes_input" class="block text-xs font-semibold text-slate-700 uppercase mb-1">Catatan Penutupan (Opsional)</label>
                            <textarea id="eod_notes_input" name="notes" rows="2" placeholder="Catatan operasional atau selisih..."
                                class="w-full rounded-xl border border-slate-200 p-2.5 text-sm shadow-sm"></textarea>
                        </div>

                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-[11px] text-amber-900 leading-relaxed">
                            <strong>Perhatian:</strong> Pastikan seluruh shift pada tanggal bisnis telah berstatus Closed dan tidak ada transaksi pembayaran dalam status pending/timeout.
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" @click="openEodModal = false"
                            class="min-h-[44px] px-4 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                            Batal
                        </button>
                        <button type="submit"
                            class="min-h-[44px] px-5 rounded-xl bg-apeiron-ink text-white text-sm font-bold hover:bg-black shadow-sm">
                            Kunci &amp; Selesaikan EOD
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>

