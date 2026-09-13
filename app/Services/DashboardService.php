<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\OrderDetail;
use App\Enums\OrderStatus;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * Mengumpulkan ringkasan metrics untuk dashboard.
     */
    public function getDashboardMetrics(): array
    {
        // 1. Core KPIs
        $totalEarnings = Order::where('order_status', OrderStatus::Paid->value)->sum('order_amount');
        $totalOrders = Order::where('order_status', OrderStatus::Paid->value)->count();
        $productsCount = Product::count();
        
        // 2. Real-time Progress Card Data
        $lowStockCount = Product::where('stock', '<=', 10)->count();
        $lowStockPercent = $productsCount > 0 ? min(100, round(($lowStockCount / $productsCount) * 100)) : 0;

        $soldThisMonth = OrderDetail::whereHas('order', function ($query) {
            $query->where('order_status', OrderStatus::Paid->value)
                  ->whereMonth('created_at', Carbon::now()->month)
                  ->whereYear('created_at', Carbon::now()->year);
        })->sum('qty');
        
        $returnedProducts = 0; // Karena fitur retur belum diaktifkan (selalu 0)

        // 3. Recent Transactions
        $recentOrders = Order::with('user')->orderBy('created_at', 'desc')->take(4)->get();

        // 4. Revenue Big Chart (7 Hari Terakhir)
        $chartDates = collect(range(6, 0))->map(function($days) {
            return Carbon::now()->subDays($days)->format('M d');
        })->toArray();

        // PERBAIKAN N+1 QUERY:
        // Kenapa ini lebih efisien? 
        // Daripada melakukan 14 query terpisah (2 query per hari selama 7 hari) di dalam loop,
        // kita menggunakan SATU query dengan DATE() dan GROUP BY 'tanggal'.
        // Ini meminimalisir beban database (I/O) dan mempercepat waktu muat (load time) dashboard secara signifikan.
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        
        $dailyStatsQuery = Order::whereBetween('created_at', [$startDate, $endDate])
            ->where('order_status', OrderStatus::Paid->value)
            ->selectRaw('DATE(created_at) as tanggal, COALESCE(SUM(order_amount), 0) as total, COUNT(*) as jumlah')
            ->groupBy('tanggal')
            ->get()
            ->keyBy('tanggal');

        $revenueData = [];
        $ordersData = [];

        foreach (range(6, 0) as $days) {
            $dateStr = Carbon::now()->subDays($days)->format('Y-m-d');
            
            // Map hasilnya ke 7 hari (isi 0 untuk hari tanpa transaksi)
            $stat = $dailyStatsQuery->get($dateStr);
            
            $revenueData[] = $stat ? (float) $stat->total : 0;
            $ordersData[] = $stat ? (int) $stat->jumlah : 0;
        }

        // 5. Payment Methods Donut Chart
        $cashOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'cash')->count();
        $qrisOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'qris')->count();
        $ewalletOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'ewallet')->count();
        
        $totalPaidOrders = $cashOrders + $qrisOrders + $ewalletOrders;
        $paymentStats = $totalPaidOrders > 0 ? [$cashOrders, $qrisOrders, $ewalletOrders] : [0, 0, 0];

        return compact(
            'totalEarnings',
            'totalOrders',
            'productsCount',
            'lowStockCount',
            'lowStockPercent',
            'soldThisMonth',
            'returnedProducts',
            'recentOrders',
            'chartDates',
            'revenueData',
            'ordersData',
            'paymentStats',
            'totalPaidOrders'
        );
    }
}
