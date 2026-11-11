<?php

namespace App\Modules\Reports\Services;

use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\IngredientRestockForecast;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\RestockPredictionService;
use App\Services\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Indonesian month abbreviations for display.
     */
    public const ID_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Mengumpulkan ringkasan metrics untuk dashboard.
     * OPTIMASI: di-cache 90 detik per tenant & store scope.
     *
     * @return array<string, mixed>
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
        if ($tenantId === null) {
            throw new \RuntimeException('DashboardService requires an active tenant context.');
        }

        $cacheKey = "pos:dashboard:{$tenantId}:{$cacheScope}:metrics_v3";

        $metrics = Cache::remember($cacheKey, 90, function () use ($isAllStores, $user, $tenantId, $activeStoreId): array {
            $orderQuery = $isAllStores ? Order::allStores() : Order::query();
            $orderItemQuery = $isAllStores ? OrderItem::allStores() : OrderItem::query();

            // 1. Tentukan tanggal referensi (mendukung database demo/seeder maupun produksi real-time)
            $paidStatuses = [OrderStatus::Paid->value, OrderStatus::Completed->value];
            $now = Carbon::now();
            /** @var Order|null $latestOrder */
            $latestOrder = (clone $orderQuery)->whereIn('order_status', $paidStatuses)->latest('created_at')->first();
            $latestOrderDate = $latestOrder?->created_at;

            if ($latestOrderDate && $latestOrderDate->diffInDays($now) > 30) {
                $hasCurrentMonthOrders = (clone $orderQuery)->whereIn('order_status', $paidStatuses)
                    ->whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->exists();

                if (! $hasCurrentMonthOrders) {
                    $now = $latestOrderDate->copy();
                }
            }

            $thisMonth = $now->month;
            $thisYear = $now->year;
            $lastMonthDate = $now->copy()->subMonth();
            $lastMonth = $lastMonthDate->month;
            $lastYear = $lastMonthDate->year;

            // 2. Query agregasi KPI
            $kpi = (clone $orderQuery)->whereIn('order_status', $paidStatuses)
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

            /** @var object{total_earnings: float|int|string, total_orders: int, this_month_earnings: float|int|string, last_month_earnings: float|int|string, this_month_orders: int, last_month_orders: int, cash_orders: int, qris_orders: int, ewallet_orders: int, card_orders: int} $kpi */
            $kpi = $kpi ?? (object) [
                'total_earnings' => 0,
                'total_orders' => 0,
                'this_month_earnings' => 0,
                'last_month_earnings' => 0,
                'this_month_orders' => 0,
                'last_month_orders' => 0,
                'cash_orders' => 0,
                'qris_orders' => 0,
                'ewallet_orders' => 0,
                'card_orders' => 0,
            ];

            $totalEarnings = (float) $kpi->total_earnings;
            $totalOrders = (int) $kpi->total_orders;
            $productsCount = Product::where('is_active', true)->count();

            $earningsDeltaPercent = (float) $kpi->last_month_earnings > 0
                ? round((((float) $kpi->this_month_earnings - (float) $kpi->last_month_earnings) / (float) $kpi->last_month_earnings) * 100, 1)
                : null;
            $ordersDeltaPercent = (int) $kpi->last_month_orders > 0
                ? round((((int) $kpi->this_month_orders - (int) $kpi->last_month_orders) / (int) $kpi->last_month_orders) * 100, 1)
                : null;

            $lowStockCount = Product::where('is_active', true)->where('stock', '<=', 10)->count();
            $lowStockPercent = $productsCount > 0 ? min(100, round(($lowStockCount / $productsCount) * 100)) : 0;

            $soldThisMonth = (int) ((clone $orderItemQuery)->whereHas('order', function ($query) use ($thisMonth, $thisYear, $paidStatuses) {
                $query->whereIn('order_status', $paidStatuses)
                    ->whereMonth('created_at', $thisMonth)
                    ->whereYear('created_at', $thisYear);
            })->sum('qty'));

            $returnedProducts = 0;
            if (\Illuminate\Support\Facades\Schema::hasTable('order_returns') && \Illuminate\Support\Facades\Schema::hasTable('order_return_items')) {
                $returnedProducts = (int) DB::table('order_return_items as ori')
                    ->join('order_returns as orr', function ($join) {
                        $join->on('ori.order_return_id', '=', 'orr.id')
                            ->on('ori.tenant_id', '=', 'orr.tenant_id');
                    })
                    ->where('orr.tenant_id', $tenantId)
                    ->where('orr.status', 'completed')
                    ->whereMonth(DB::raw('COALESCE(orr.completed_at, orr.created_at)'), $thisMonth)
                    ->whereYear(DB::raw('COALESCE(orr.completed_at, orr.created_at)'), $thisYear)
                    ->when($activeStoreId !== null, fn ($q) => $q->where('orr.store_id', $activeStoreId))
                    ->sum('ori.quantity_returned');
            }

            // 3.0 Real-time Period KPI (DEMURE Top 3 Cards)
            $todayStart = $now->copy()->startOfDay();
            $todayEnd = $now->copy()->endOfDay();
            $todayOrders = (int) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$todayStart, $todayEnd])->count());

            $yesterdayStart = $now->copy()->subDay()->startOfDay();
            $yesterdayEnd = $now->copy()->subDay()->endOfDay();
            $yesterdayOrders = (int) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])->count());
            $todayDeltaPercent = $yesterdayOrders > 0
                ? round((($todayOrders - $yesterdayOrders) / $yesterdayOrders) * 100, 1)
                : ($todayOrders > 0 ? 100.0 : 0.0);
            $todayDeltaFormatted = ($todayDeltaPercent >= 0 ? '+' : '').$todayDeltaPercent.'%';
            $todayDeltaVariant = $todayDeltaPercent >= 0 ? 'positive' : 'negative';

            $weekStart = $now->copy()->startOfWeek();
            $weekOrders = (int) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$weekStart, $todayEnd])->count());

            $lastWeekStart = $now->copy()->subWeek()->startOfWeek();
            $lastWeekEnd = $now->copy()->subWeek()->endOfWeek();
            $lastWeekOrders = (int) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])->count());
            $weekDeltaPercent = $lastWeekOrders > 0
                ? round((($weekOrders - $lastWeekOrders) / $lastWeekOrders) * 100, 1)
                : ($weekOrders > 0 ? 100.0 : 0.0);
            $weekDeltaFormatted = ($weekDeltaPercent >= 0 ? '+' : '').$weekDeltaPercent.'%';
            $weekDeltaVariant = $weekDeltaPercent >= 0 ? 'positive' : 'negative';

            $displayTodayOrders = $todayOrders;
            $displayWeekOrders = $weekOrders;
            $displayMonthOrders = (int) $kpi->this_month_orders;

            $monthDeltaPercent = (int) $kpi->last_month_orders > 0
                ? round((((int) $kpi->this_month_orders - (int) $kpi->last_month_orders) / (int) $kpi->last_month_orders) * 100, 1)
                : ((int) $kpi->this_month_orders > 0 ? 100.0 : 0.0);
            $monthDeltaFormatted = ($monthDeltaPercent >= 0 ? '+' : '').$monthDeltaPercent.'%';
            $monthDeltaVariant = $monthDeltaPercent >= 0 ? 'positive' : 'negative';

            // 3.1 31-Day Histogram Data for DEMURE Sales Performance
            $monthStart = $now->copy()->startOfMonth();
            $monthEnd = $now->copy()->endOfMonth();
            $daysInMonth = $now->daysInMonth;
            $monthlySalesQuery = (clone $orderQuery)->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereIn('order_status', $paidStatuses)
                ->selectRaw('DAY(created_at) as hari, COALESCE(SUM(order_amount), 0) as total, COUNT(*) as jumlah')
                ->groupBy('hari')
                ->get()
                ->keyBy('hari');

            $hasRealMonthData = $monthlySalesQuery->isNotEmpty();
            $monthlyTotals = $monthlySalesQuery->pluck('total')->map(fn ($v) => (float) $v)->all();
            $maxDaily = $monthlyTotals !== [] ? max($monthlyTotals) : 0.0;
            if ($maxDaily <= 0) {
                $maxDaily = 1.0;
            }

            $monthlyHistogram = [];
            $activeDay = ($hasRealMonthData && $monthlySalesQuery->has($now->day)) ? $now->day : $now->day;

            for ($d = 1; $d <= 31; $d++) {
                $stat = $monthlySalesQuery->get($d);
                if ($stat && (float) $stat->total > 0) {
                    $val = (float) $stat->total;
                    $count = (int) $stat->jumlah;
                    $height = max(4, min(95, (int) round(($val / $maxDaily) * 90)));
                } else {
                    $val = 0.0;
                    $count = 0;
                    $height = 4;
                }

                $isHighlighted = ($d === $activeDay);
                $dClamped = min($daysInMonth, $d);
                $dayCarbon = Carbon::create($now->year, $now->month, $dClamped) ?? $now;
                $dayCarbon->locale('id');
                $dayDate = $dayCarbon->isoFormat('dddd, D MMMM Y');
                $monthlyHistogram[] = [
                    'day' => (string) $d,
                    'label' => (string) $d,
                    'date' => $dayDate,
                    'height' => $height,
                    'total' => $val,
                    'count' => $count,
                    'formatted' => 'Rp '.number_format($val, 0, ',', '.'),
                    'is_active' => $isHighlighted,
                ];
            }

            // 3.1b Weekly Histogram (7 hari terakhir)
            $startDate = $now->copy()->subDays(6)->startOfDay();
            $endDate = $now->copy()->endOfDay();

            $dailyStatsQuery = (clone $orderQuery)->whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('order_status', $paidStatuses)
                ->selectRaw('DATE(created_at) as tanggal, COALESCE(SUM(order_amount), 0) as total, COUNT(*) as jumlah')
                ->groupBy('tanggal')
                ->get()
                ->keyBy('tanggal');

            $weekDaysMap = [
                1 => 'Sen',
                2 => 'Sel',
                3 => 'Rab',
                4 => 'Kam',
                5 => 'Jum',
                6 => 'Sab',
                7 => 'Min',
            ];

            $weeklyHistogram = [];
            $realWeeklyMax = (float) ($dailyStatsQuery->pluck('total')->map(fn ($v) => (float) $v)->max() ?: 0);
            $maxWeekly = $realWeeklyMax > 0 ? $realWeeklyMax : 1.0;

            foreach (range(6, 0) as $daysAgo) {
                $d = $now->copy()->subDays($daysAgo);
                $dateStr = $d->format('Y-m-d');
                $stat = $dailyStatsQuery->get($dateStr);
                if ($stat && (float) $stat->total > 0) {
                    $val = (float) $stat->total;
                    $count = (int) $stat->jumlah;
                    $height = max(4, min(95, (int) round(($val / $maxWeekly) * 90)));
                } else {
                    $val = 0.0;
                    $count = 0;
                    $height = 4;
                }

                $dayName = $weekDaysMap[$d->dayOfWeekIso] ?? $d->format('D');
                $isHighlighted = ($daysAgo === 0);

                $d->locale('id');
                $weeklyHistogram[] = [
                    'day' => $dayName,
                    'label' => $dayName.' ('.$d->format('d/m').')',
                    'date' => $d->isoFormat('dddd, D MMMM Y'),
                    'height' => $height,
                    'total' => $val,
                    'count' => $count,
                    'formatted' => 'Rp '.number_format($val, 0, ',', '.'),
                    'is_active' => $isHighlighted,
                ];
            }

            // 3.1c Yearly Histogram (12 bulan tahun berjalan)
            $yearStart = $now->copy()->startOfYear();
            $yearEnd = $now->copy()->endOfYear();
            $yearlySalesQuery = (clone $orderQuery)->whereBetween('created_at', [$yearStart, $yearEnd])
                ->whereIn('order_status', $paidStatuses)
                ->selectRaw('MONTH(created_at) as bulan, COALESCE(SUM(order_amount), 0) as total, COUNT(*) as jumlah')
                ->groupBy('bulan')
                ->get()
                ->keyBy('bulan');

            $hasRealYearData = $yearlySalesQuery->isNotEmpty();
            $realYearlyMax = (float) ($yearlySalesQuery->pluck('total')->map(fn ($v) => (float) $v)->max() ?: 0);
            $maxYearly = $realYearlyMax > 0 ? $realYearlyMax : 1.0;

            $yearlyHistogram = [];
            for ($m = 1; $m <= 12; $m++) {
                $stat = $yearlySalesQuery->get($m);
                if ($stat && (float) $stat->total > 0) {
                    $val = (float) $stat->total;
                    $count = (int) $stat->jumlah;
                    $height = max(4, min(95, (int) round(($val / $maxYearly) * 90)));
                } else {
                    $val = 0.0;
                    $count = 0;
                    $height = 4;
                }

                $monthName = self::ID_MONTHS[$m - 1];
                $isHighlighted = ($m === $now->month);

                $yearlyHistogram[] = [
                    'day' => $monthName,
                    'label' => $monthName,
                    'date' => $monthName.' '.$now->year,
                    'height' => $height,
                    'total' => $val,
                    'count' => $count,
                    'formatted' => 'Rp '.number_format($val, 0, ',', '.'),
                    'is_active' => $isHighlighted,
                ];
            }

            $activeItem = collect($monthlyHistogram)->firstWhere('day', (string) $activeDay) ?? collect($monthlyHistogram)->first();
            $activeDayTotal = $activeItem ? (float) $activeItem['total'] : 0.0;
            $activeDayFormatted = 'Rp '.number_format($activeDayTotal, 0, ',', '.');
            $activeDayClamped = min($daysInMonth, (int) $activeDay);
            $activeCarbon = Carbon::create($now->year, $now->month, $activeDayClamped) ?? $now;
            $activeCarbon->locale('id');
            $activeDayDate = $activeCarbon->isoFormat('dddd, D MMMM Y');

            // 3.2 Real-time Quarterly Statistics (Eliminasi data fiktif & label Korea)
            $currentQuarter = $now->quarter;
            $quarterStart = $now->copy()->firstOfQuarter();
            $quarterEnd = $now->copy()->lastOfQuarter();
            $quarterEarnings = (float) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$quarterStart, $quarterEnd])->sum('order_amount'));

            $prevQuarterStart = $now->copy()->subQuarter()->firstOfQuarter();
            $prevQuarterEnd = $now->copy()->subQuarter()->lastOfQuarter();
            $prevQuarterEarnings = (float) ((clone $orderQuery)->whereIn('order_status', $paidStatuses)->whereBetween('created_at', [$prevQuarterStart, $prevQuarterEnd])->sum('order_amount'));

            $quarterDeltaPercent = $prevQuarterEarnings > 0
                ? round((($quarterEarnings - $prevQuarterEarnings) / $prevQuarterEarnings) * 100, 1)
                : ($quarterEarnings > 0 ? 100.0 : 0.0);

            // Agregasi pendapatan riil untuk 4 kuartal dalam tahun berjalan
            $yearStart = $now->copy()->startOfYear();
            $quarterTotals = [];
            for ($q = 1; $q <= 4; $q++) {
                $qStart = $yearStart->copy()->addMonths(($q - 1) * 3)->startOfDay();
                $qEnd = $qStart->copy()->addMonths(3)->subSecond()->endOfDay();
                $quarterTotals[$q] = (float) ((clone $orderQuery)
                    ->whereIn('order_status', $paidStatuses)
                    ->whereBetween('created_at', [$qStart, $qEnd])
                    ->sum('order_amount'));
            }

            $maxQuarter = max(1.0, ...array_values($quarterTotals));
            $quarterLabels = [
                1 => 'Q1 (Jan-Mar)',
                2 => 'Q2 (Apr-Jun)',
                3 => 'Q3 (Jul-Sep)',
                4 => 'Q4 (Okt-Des)',
            ];

            $quarterBars = [];
            for ($q = 1; $q <= 4; $q++) {
                $total = $quarterTotals[$q];
                $percent = (int) round(($total / $maxQuarter) * 100);
                $quarterBars[] = [
                    'label' => $quarterLabels[$q],
                    'quarter' => "Q{$q}",
                    'percent' => $percent,
                    'total' => $total,
                    'is_current' => $currentQuarter === $q,
                ];
            }

            // 3.3 7 Hari Terakhir & Statistik Harian (Existing Curve)
            $revenueData = [];
            $ordersData = [];
            $chartDates = [];

            foreach (range(6, 0) as $days) {
                $d = $now->copy()->subDays($days);
                $dateStr = $d->format('Y-m-d');
                $stat = $dailyStatsQuery->get($dateStr);

                $revenueData[] = $stat ? (float) $stat->total : 0.0;
                $ordersData[] = $stat ? (int) $stat->jumlah : 0;
                $chartDates[] = $d->format('j').' '.self::ID_MONTHS[$d->month - 1];
            }

            // 4. Rentang Tanggal & Label Header
            $startFormatted = $startDate->format('j');
            $endFormatted = $now->format('j').' '.self::ID_MONTHS[$now->month - 1].' '.$now->year;
            $dateRangeLabel = "{$startFormatted} – {$endFormatted} · 7 hari terakhir";

            // 5. Payment Distribution
            $cashOrders = (int) $kpi->cash_orders;
            $qrisOrders = (int) $kpi->qris_orders;
            $ewalletOrders = (int) $kpi->ewallet_orders;
            $cardOrders = (int) $kpi->card_orders;

            $totalPaidOrders = $cashOrders + $qrisOrders + $ewalletOrders + $cardOrders;
            $paymentStats = $totalPaidOrders > 0 ? [$cashOrders, $qrisOrders, $ewalletOrders, $cardOrders] : [0, 0, 0, 0];

            $cashPercent = $totalPaidOrders > 0 ? (int) round(($cashOrders / $totalPaidOrders) * 100) : 0;
            $qrisPercent = $totalPaidOrders > 0 ? (int) round(($qrisOrders / $totalPaidOrders) * 100) : 0;
            $ewalletPercent = $totalPaidOrders > 0 ? (int) round(($ewalletOrders / $totalPaidOrders) * 100) : 0;
            $cardPercent = $totalPaidOrders > 0 ? max(0, 100 - ($cashPercent + $qrisPercent + $ewalletPercent)) : 0;

            $paymentBreakdown = [
                'cash' => ['label' => 'Tunai', 'count' => $cashOrders, 'percent' => $cashPercent, 'color' => '#C4C3C0'],
                'qris' => ['label' => 'QRIS', 'count' => $qrisOrders, 'percent' => $qrisPercent, 'color' => '#37352F'],
                'ewallet' => ['label' => 'E-Wallet', 'count' => $ewalletOrders, 'percent' => $ewalletPercent, 'color' => '#787774'],
                'card' => ['label' => 'Kartu', 'count' => $cardOrders, 'percent' => $cardPercent, 'color' => '#E3E2E0'],
                'total' => $totalPaidOrders,
            ];

            // 6. Prakiraan Restock Bahan Baku
            $forecasts = IngredientRestockForecast::query()
                ->whereHas('ingredient', fn ($q) => $q->where('is_active', true))
                ->with('ingredient:id,name,unit,current_stock,reorder_level')
                ->orderByRaw('projected_days_remaining IS NULL, projected_days_remaining ASC')
                ->take(5)
                ->get();

            if ($forecasts->isEmpty()) {
                try {
                    app(RestockPredictionService::class)->recomputeAll();
                    $forecasts = IngredientRestockForecast::query()
                        ->whereHas('ingredient', fn ($q) => $q->where('is_active', true))
                        ->with('ingredient:id,name,unit,current_stock,reorder_level')
                        ->orderByRaw('projected_days_remaining IS NULL, projected_days_remaining ASC')
                        ->take(5)
                        ->get();
                } catch (\Throwable $e) {
                    // Silently ignore prediction compute errors
                }
            }

            $restockList = [];
            if ($forecasts->isNotEmpty()) {
                foreach ($forecasts as $f) {
                    $days = $f->projected_days_remaining !== null ? (float) $f->projected_days_remaining : null;
                    $trend = $f->trend ?? 'stable';
                    $trendLabel = match ($trend) {
                        'falling', 'down' => 'Declining',
                        'rising', 'up' => 'Rising',
                        default => 'Stable',
                    };
                    $trendColor = match ($trend) {
                        'falling', 'down' => '#BE123C',
                        'rising', 'up' => '#047857',
                        default => '#6B6A66',
                    };

                    $ingredient = $f->ingredient;
                    $daysLabel = $days !== null ? round($days).' days' : (($ingredient && $ingredient->current_stock <= 0) ? 'Out of stock' : 'Low stock');
                    $barWidth = $days !== null ? min(100, max(8, round(($days / 14) * 100))) : 12;

                    $restockList[] = [
                        'name' => $ingredient ? $ingredient->name : 'Raw Material',
                        'days' => $days,
                        'days_label' => $daysLabel,
                        'progress_percent' => $barWidth,
                        'trend' => $trend,
                        'trend_label' => $trendLabel,
                        'trend_color' => $trendColor,
                    ];
                }
            } else {
                $fallback = Ingredient::where('is_active', true)
                    ->orderBy('current_stock', 'asc')
                    ->take(5)
                    ->get();

                foreach ($fallback as $ing) {
                    $reorder = (float) $ing->reorder_level ?: 10;
                    $stock = (float) $ing->current_stock;
                    $ratio = $reorder > 0 ? ($stock / $reorder) : 0;
                    $days = max(1, round($ratio * 7));
                    $trend = $stock <= $reorder ? 'falling' : 'stable';

                    $restockList[] = [
                        'name' => $ing->name,
                        'days' => $days,
                        'days_label' => $stock <= 0 ? 'Out of stock' : $days.' days',
                        'progress_percent' => min(100, max(8, round(($days / 14) * 100))),
                        'trend' => $trend,
                        'trend_label' => $trend === 'falling' ? 'Declining' : 'Stable',
                        'trend_color' => $trend === 'falling' ? '#BE123C' : '#6B6A66',
                    ];
                }
            }

            // 7. Kalkulasi Koordinat SVG Curve
            $chartSvg = $this->buildChartSvg($revenueData, $chartDates);

            // 8. Greeting & Narrative Summary
            $hour = (int) Carbon::now('Asia/Jakarta')->format('G');
            $greeting = match (true) {
                $hour >= 4 && $hour < 12 => 'Good morning',
                $hour >= 12 && $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            };

            $userName = $user instanceof User ? $user->name : 'Owner';
            // First name
            $nameParts = explode(' ', trim($userName));
            $firstName = $nameParts[0] !== '' ? $nameParts[0] : 'Owner';

            $earningsDir = ($earningsDeltaPercent ?? 0) >= 0 ? 'increased' : 'decreased';
            $earningsAbs = abs($earningsDeltaPercent ?? 0);
            $ordersDir = ($ordersDeltaPercent ?? 0) >= 0 ? 'increased' : 'decreased';
            $ordersAbs = abs($ordersDeltaPercent ?? 0);

            if ($earningsDeltaPercent !== null && $ordersDeltaPercent !== null) {
                $summaryText = "This month revenue {$earningsDir} by <b>".number_format($earningsAbs, 1, '.', ',')."%</b> and transactions {$ordersDir} by <b>".number_format($ordersAbs, 1, '.', ',')."%</b> compared to last month. There are <b>{$lowStockCount} items</b> with low stock requiring review.";
            } else {
                $summaryText = 'Total sales volume recorded at <b>Rp '.number_format($totalEarnings, 0, ',', '.').'</b> across <b>'.number_format($totalOrders, 0, ',', '.')." transactions</b>. There are <b>{$lowStockCount} items</b> with low stock requiring review.";
            }

            return compact(
                'totalEarnings',
                'totalOrders',
                'productsCount',
                'lowStockCount',
                'lowStockPercent',
                'soldThisMonth',
                'returnedProducts',
                'chartDates',
                'dateRangeLabel',
                'revenueData',
                'ordersData',
                'paymentStats',
                'paymentBreakdown',
                'totalPaidOrders',
                'earningsDeltaPercent',
                'ordersDeltaPercent',
                'restockList',
                'chartSvg',
                'greeting',
                'userName',
                'firstName',
                'summaryText',
                'displayTodayOrders',
                'todayDeltaPercent',
                'todayDeltaFormatted',
                'todayDeltaVariant',
                'displayWeekOrders',
                'weekDeltaPercent',
                'weekDeltaFormatted',
                'weekDeltaVariant',
                'displayMonthOrders',
                'monthDeltaPercent',
                'monthDeltaFormatted',
                'monthDeltaVariant',
                'monthlyHistogram',
                'weeklyHistogram',
                'yearlyHistogram',
                'activeDay',
                'activeDayDate',
                'activeDayFormatted',
                'quarterEarnings',
                'quarterDeltaPercent',
                'quarterBars'
            );
        });

        // Dynamic non-cached data (recent orders & store context)
        $recentOrderQuery = $isAllStores ? Order::allStores() : Order::query();
        $metrics['recentOrders'] = (clone $recentOrderQuery)
            ->with(['user', 'customer', 'store'])
            ->orderBy('created_at', 'desc')
            ->take(4)
            ->get();

        $activeStore = ! $isAllStores ? Store::find($activeStoreId) : null;
        $activeStoreName = 'All Branches';
        $activeStoreBadge = 'Consolidated';
        if (! $isAllStores) {
            $activeStoreName = $activeStore ? $activeStore->name : 'Main Branch';
            $activeStoreBadge = $activeStore && $activeStore->code ? $activeStore->code : 'Active';
        }
        $metrics['activeStoreName'] = $activeStoreName;
        $metrics['activeStoreBadge'] = $activeStoreBadge;
        $metrics['isAllStores'] = $isAllStores;

        return $metrics;
    }

    /**
     * Generate path SVG Bezier Curve halus untuk grafik 7-hari.
     *
     * @param  array<int, float>  $revenueData
     * @param  array<int, string>  $chartDates
     * @return array<string, mixed>
     */
    private function buildChartSvg(array $revenueData, array $chartDates): array
    {
        $maxVal = ($revenueData !== [] ? max($revenueData) : 0.0) ?: 10000000.0;
        $ceil = $maxVal * 1.18;
        if ($ceil <= 0) {
            $ceil = 10000000.0;
        }

        // Tentukan unit display (juta atau miliar)
        $isBillions = $ceil >= 1000000000;
        $unitDivider = $isBillions ? 1000000000 : 1000000;
        $unitSuffix = $isBillions ? ' M' : ' jt';

        // 4 Level Grid Y
        $desktopGrid = [
            ['y' => 206.0, 'val' => 0, 'label' => '0'],
            ['y' => 146.7, 'val' => ($ceil / 3), 'label' => $this->formatAxisNumber(($ceil / 3) / $unitDivider)],
            ['y' => 87.3,  'val' => ($ceil * 2 / 3), 'label' => $this->formatAxisNumber(($ceil * 2 / 3) / $unitDivider)],
            ['y' => 28.0,  'val' => $ceil, 'label' => $this->formatAxisNumber($ceil / $unitDivider)],
        ];

        // Titik Desktop (880 x 230)
        // X: 30 sampai 870 (span = 840, 6 interval = 140)
        // Y: 206 (dasar) sampai 28 (puncak), span = 178
        $desktopPoints = [];
        $mobilePoints = [];

        // Mobile X: 34 sampai 350 (span = 316, 6 interval = 52.67)
        // Mobile Y: 128 (dasar) sampai 8 (puncak), span = 120
        for ($i = 0; $i < 7; $i++) {
            $val = $revenueData[$i] ?? 0.0;
            $norm = min(1.0, max(0.0, $val / $ceil));

            $dx = round(30 + ($i * 140), 1);
            $dy = round(206.0 - ($norm * 178.0), 1);

            $mx = round(34.0 + ($i * 52.667), 1);
            $my = round(128.0 - ($norm * 120.0), 1);

            $dateLabel = $chartDates[$i] ?? '';
            $compactVal = $this->formatCompactCurrency($val);

            $desktopPoints[] = [
                'x' => $dx,
                'y' => $dy,
                'val' => $val,
                'date' => $dateLabel,
                'formatted' => $compactVal,
            ];

            $mobilePoints[] = [
                'x' => $mx,
                'y' => $my,
                'val' => $val,
                'date' => $dateLabel,
                'formatted' => $compactVal,
            ];
        }

        // Hitung cubic bezier curve desktop
        $desktopPath = $this->calculateBezierPath($desktopPoints);
        $desktopFill = $desktopPath.' L 870,206 L 30,206 Z';

        // Hitung polyline & polygon mobile
        $mobilePolyline = implode(' ', array_map(fn ($p) => "{$p['x']},{$p['y']}", $mobilePoints));
        $mobilePolygon = "34.0,128.0 {$mobilePolyline} 350.0,128.0";

        // Titik aktif (titik terakhir atau nilai terbesar)
        $latestPoint = end($desktopPoints);
        $mobileGrid = [
            ['y' => 128.0, 'val' => 0, 'label' => '0'],
            ['y' => 88.0,  'val' => ($ceil / 3), 'label' => $this->formatAxisNumber(($ceil / 3) / $unitDivider)],
            ['y' => 48.0,  'val' => ($ceil * 2 / 3), 'label' => $this->formatAxisNumber(($ceil * 2 / 3) / $unitDivider)],
            ['y' => 8.0,   'val' => $ceil, 'label' => $this->formatAxisNumber($ceil / $unitDivider)],
        ];

        $latestBadgeText = "{$latestPoint['date']} · {$latestPoint['formatted']}";

        return [
            'max_val' => $maxVal,
            'unit_suffix' => $unitSuffix,
            'desktop_path' => $desktopPath,
            'desktop_fill' => $desktopFill,
            'desktop_points' => $desktopPoints,
            'desktop_grid' => $desktopGrid,
            'mobile_polyline' => $mobilePolyline,
            'mobile_polygon' => $mobilePolygon,
            'mobile_points' => $mobilePoints,
            'mobile_grid' => $mobileGrid,
            'latest_point' => $latestPoint,
            'latest_badge' => $latestBadgeText,
        ];
    }

    /**
     * Hitung cubic bezier Catmull-Rom path string.
     *
     * @param  array<int, array{x: float, y: float, val?: float, date?: string, formatted?: string}>  $points
     */
    private function calculateBezierPath(array $points): string
    {
        if (empty($points)) {
            return '';
        }

        $path = 'M '.$points[0]['x'].','.$points[0]['y'];
        $n = count($points);

        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $i > 0 ? $points[$i - 1] : $points[$i];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $i + 2 < $n ? $points[$i + 2] : $p2;

            $cp1x = round($p1['x'] + ($p2['x'] - $p0['x']) / 6, 1);
            $cp1y = round($p1['y'] + ($p2['y'] - $p0['y']) / 6, 1);
            $cp2x = round($p2['x'] - ($p3['x'] - $p1['x']) / 6, 1);
            $cp2y = round($p2['y'] - ($p3['y'] - $p1['y']) / 6, 1);

            $path .= " C {$cp1x},{$cp1y} {$cp2x},{$cp2y} {$p2['x']},{$p2['y']}";
        }

        return $path;
    }

    private function formatAxisNumber(float $num): string
    {
        if ($num >= 100) {
            return (string) round($num);
        }
        if ($num >= 10) {
            return round($num, 1) == round($num) ? (string) round($num) : str_replace('.', ',', (string) round($num, 1));
        }

        return round($num, 1) == round($num) ? (string) round($num) : str_replace('.', ',', (string) round($num, 1));
    }

    private function formatCompactCurrency(float $num): string
    {
        if ($num >= 1000000000) {
            $val = round($num / 1000000000, 2);

            return 'Rp '.str_replace('.', ',', (string) $val).' M';
        }
        if ($num >= 1000000) {
            $val = round($num / 1000000, 1);

            return 'Rp '.str_replace('.', ',', (string) $val).' jt';
        }
        if ($num >= 1000) {
            $val = round($num / 1000, 1);

            return 'Rp '.str_replace('.', ',', (string) $val).' rb';
        }

        return 'Rp '.number_format($num, 0, ',', '.');
    }

    /**
     * Menginvalidasi partisi cache dasbor untuk tenant dan cabang yang ditentukan.
     */
    public function invalidateCache(int $tenantId, ?int $storeId = null): void
    {
        Cache::forget("pos:dashboard:{$tenantId}:all:metrics_v3");
        Cache::forget("pos:dashboard:{$tenantId}:default:metrics_v3");
        if ($storeId !== null) {
            Cache::forget("pos:dashboard:{$tenantId}:{$storeId}:metrics_v3");
        }

        try {
            if (config('cache.default') === 'redis') {
                $redis = \Illuminate\Support\Facades\Redis::connection();
                $keys = $redis->keys("pos:dashboard:{$tenantId}:*");
                if (! empty($keys)) {
                    $redis->del($keys);
                }
                $dashKeys = $redis->keys("pos:dash:{$tenantId}:*");
                if (! empty($dashKeys)) {
                    $redis->del($dashKeys);
                }
            }
        } catch (\Throwable) {
            // Abaikan kegagalan flush redis saat testing / offline mode
        }
    }
}
