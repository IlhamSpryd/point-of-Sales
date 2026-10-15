<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    /**
     * Mengumpulkan ringkasan metrics untuk dashboard.
     * OPTIMASI: di-cache 90 detik. Dashboard dibuka berulang kali oleh
     * Owner/Manager, padahal berisi agregasi SUM/COUNT atas seluruh tabel
     * orders & order_items -- data real-time detik-ke-detik tidak krusial
     * untuk dashboard ringkasan, sehingga TTL singkat ini aman dan
     * signifikan mengurangi beban DB.
     */
    public function getDashboardMetrics(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $user = auth()->user();
        $isOwner = $user?->role?->name === 'Owner' || (bool) ($user?->role?->is_admin ?? false);
        $sessionStore = session('active_store_id');
        $isAllStores = $isOwner && ($sessionStore === 'all' || $sessionStore === null);
        $activeStoreId = app(TenantContext::class)->getStoreId();

        $cacheScope = $isAllStores ? 'all' : ($activeStoreId ?? 'default');

        // D1-DB-008: fail-closed -- tanpa tenant, jangan pernah baca/tulis cache
        // global 'pos:dashboard:metrics' yang bisa berisi agregasi lintas-tenant.
        if ($tenantId === null) {
            throw new \RuntimeException('DashboardService requires an active tenant context.');
        }

        $cacheKey = "pos:dashboard:{$tenantId}:{$cacheScope}:metrics";

        $metrics = Cache::remember($cacheKey, 90, function () use ($isAllStores) {
            $orderQuery = $isAllStores ? Order::allStores() : Order::query();
            $orderItemQuery = $isAllStores ? OrderItem::allStores() : OrderItem::query();

            // 1. Core KPIs
            $now = Carbon::now();
            $thisMonth = $now->month;
            $thisYear = $now->year;
            $lastMonthDate = $now->copy()->subMonth();
            $lastMonth = $lastMonthDate->month;
            $lastYear = $lastMonthDate->year;

            $kpi = (clone $orderQuery)->where('order_status', OrderStatus::Paid->value)
                ->selectRaw("
                    COALESCE(SUM(order_amount), 0) as total_earnings,
                    COUNT(*) as total_orders,
                    COALESCE(SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN order_amount ELSE 0 END), 0) as this_month_earnings,
                    COALESCE(SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN order_amount ELSE 0 END), 0) as last_month_earnings,
                    SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as this_month_orders,
                    SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) as last_month_orders,
                    SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_orders,
                    SUM(CASE WHEN payment_method = 'qris' THEN 1 ELSE 0 END) as qris_orders,
                    SUM(CASE WHEN payment_method = 'ewallet' THEN 1 ELSE 0 END) as ewallet_orders,
                    SUM(CASE WHEN payment_method = 'card' THEN 1 ELSE 0 END) as card_orders
                ", [$thisMonth, $thisYear, $lastMonth, $lastYear, $thisMonth, $thisYear, $lastMonth, $lastYear])
                ->first();

            $totalEarnings = (float) $kpi->total_earnings;
            $totalOrders = (int) $kpi->total_orders;
            $productsCount = Product::count();

            $earningsDeltaPercent = $kpi->last_month_earnings > 0
                ? round((($kpi->this_month_earnings - $kpi->last_month_earnings) / $kpi->last_month_earnings) * 100, 1)
                : null;
            $ordersDeltaPercent = $kpi->last_month_orders > 0
                ? round((($kpi->this_month_orders - $kpi->last_month_orders) / $kpi->last_month_orders) * 100, 1)
                : null;

            $lowStockCount = Product::where('is_active', true)->where('stock', '<=', 10)->count();
            $lowStockPercent = $productsCount > 0 ? min(100, round(($lowStockCount / $productsCount) * 100)) : 0;

            $soldThisMonth = (clone $orderItemQuery)->whereHas('order', function ($query) {
                $query->where('order_status', OrderStatus::Paid->value)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
            })->sum('qty');

            $returnedProducts = 0;

            $chartDates = collect(range(6, 0))->map(function ($days) {
                return Carbon::now()->subDays($days)->format('M d');
            })->toArray();

            $startDate = Carbon::now()->subDays(6)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            $dailyStatsQuery = (clone $orderQuery)->whereBetween('created_at', [$startDate, $endDate])
                ->where('order_status', OrderStatus::Paid->value)
                ->selectRaw('DATE(created_at) as tanggal, COALESCE(SUM(order_amount), 0) as total, COUNT(*) as jumlah')
                ->groupBy('tanggal')
                ->get()
                ->keyBy('tanggal');

            $revenueData = [];
            $ordersData = [];

            foreach (range(6, 0) as $days) {
                $dateStr = Carbon::now()->subDays($days)->format('Y-m-d');
                $stat = $dailyStatsQuery->get($dateStr);

                $revenueData[] = $stat ? (float) $stat->total : 0;
                $ordersData[] = $stat ? (int) $stat->jumlah : 0;
            }

            $cashOrders = (int) $kpi->cash_orders;
            $qrisOrders = (int) $kpi->qris_orders;
            $ewalletOrders = (int) $kpi->ewallet_orders;
            $cardOrders = (int) $kpi->card_orders;

            $totalPaidOrders = $cashOrders + $qrisOrders + $ewalletOrders + $cardOrders;
            $paymentStats = $totalPaidOrders > 0 ? [$cashOrders, $qrisOrders, $ewalletOrders, $cardOrders] : [0, 0, 0, 0];

            return compact(
                'totalEarnings',
                'totalOrders',
                'productsCount',
                'lowStockCount',
                'lowStockPercent',
                'soldThisMonth',
                'returnedProducts',
                'chartDates',
                'revenueData',
                'ordersData',
                'paymentStats',
                'totalPaidOrders',
                'earningsDeltaPercent',
                'ordersDeltaPercent'
            );
        });

        // Ambil data non-kalkulasi yang rawan serialization error jika di-cache
        $recentOrderQuery = $isAllStores ? Order::allStores() : Order::query();
        $metrics['recentOrders'] = (clone $recentOrderQuery)->with(['user', 'store'])->orderBy('created_at', 'desc')->take(4)->get();

        return $metrics;
    }
}
