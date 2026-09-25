<x-app-layout>
    <!-- Page header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">{{ $title }}</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Ringkasan transaksi dan pendapatan</p>
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            @php
                $startDate = request('start', \Carbon\Carbon::now()->startOfMonth()->toDateString());
                $endDate = request('end', \Carbon\Carbon::now()->toDateString());
            @endphp
            <livewire:async-export-button :start-date="$startDate" :end-date="$endDate" />
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
        <!-- Harian -->
        <div class="card-surface p-6">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-semibold text-[#787774]">Pendapatan Hari Ini</span>
                <span class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-emerald-600">today</span>
                </span>
            </div>
            <h3 class="text-2xl font-extrabold text-[#37352F] tracking-tight">Rp {{ number_format($dailySales->total, 0, ',', '.') }}</h3>
            <p class="text-xs font-medium text-[#9B9A97] mt-1.5">{{ $dailySales->count }} Transaksi</p>
        </div>
        
        <!-- Mingguan -->
        <div class="card-surface p-6">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-semibold text-[#787774]">Pendapatan Minggu Ini</span>
                <span class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-blue-600">date_range</span>
                </span>
            </div>
            <h3 class="text-2xl font-extrabold text-[#37352F] tracking-tight">Rp {{ number_format($weeklySales->total, 0, ',', '.') }}</h3>
            <p class="text-xs font-medium text-[#9B9A97] mt-1.5">{{ $weeklySales->count }} Transaksi</p>
        </div>
        
        <!-- Bulanan -->
        <div class="card-surface p-6">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-semibold text-[#787774]">Pendapatan Bulan Ini</span>
                <span class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-amber-600">calendar_month</span>
                </span>
            </div>
            <h3 class="text-2xl font-extrabold text-[#37352F] tracking-tight">Rp {{ number_format($monthlySales->total, 0, ',', '.') }}</h3>
            <p class="text-xs font-medium text-[#9B9A97] mt-1.5">{{ $monthlySales->count }} Transaksi</p>
        </div>
    </div>

    <!-- Transaction Table -->
    <div class="card-surface overflow-hidden shrink-0">
        <header class="px-6 py-4 border-b border-[#E9E9E7]">
            <h2 class="text-lg font-bold text-[#37352F]">Daftar Transaksi Terbaru</h2>
        </header>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kode Pesanan</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Tanggal</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kasir</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Nilai Transaksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse ($recentOrders as $order)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4">
                            <span class="text-sm font-medium text-[#37352F]">{{ $order->order_code }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ \Carbon\Carbon::parse($order->created_at)->format('d-m-Y H:i') }}</td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ $order->user->name ?? 'Kasir' }}</td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-sm font-bold text-[#37352F]">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</span>
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
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-yovel-border shrink-0">
            {{ $recentOrders->links() }}
        </div>
    </div>
</x-app-layout>
