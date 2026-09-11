<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Models\OrderDetail;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * DashboardController: Mengumpulkan ringkasan data statistik sistem 
 * untuk dirender pada Beranda Admin (Dashboard) secara real-time.
 */
class DashboardController extends Controller
{
    /**
     * Mengkalkulasi pendapatan dan statistik, lalu merendernya dalam View.
     */
    public function index(): \Illuminate\View\View
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

        $revenueData = [];
        $ordersData = [];

        foreach (range(6, 0) as $days) {
            $date = Carbon::now()->subDays($days)->format('Y-m-d');
            
            $revenueData[] = Order::where('order_status', OrderStatus::Paid->value)
                                  ->whereDate('created_at', $date)
                                  ->sum('order_amount');
                                  
            $ordersData[] = Order::where('order_status', OrderStatus::Paid->value)
                                 ->whereDate('created_at', $date)
                                 ->count();
        }

        // 5. Payment Methods Donut Chart
        $cashOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'cash')->count();
        $qrisOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'qris')->count();
        $ewalletOrders = Order::where('order_status', OrderStatus::Paid->value)->where('payment_method', 'ewallet')->count();
        
        $totalPaidOrders = $cashOrders + $qrisOrders + $ewalletOrders;
        $paymentStats = $totalPaidOrders > 0 ? [$cashOrders, $qrisOrders, $ewalletOrders] : [0, 0, 0];

        return view('dashboard', compact(
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
        ));
    }
}
