<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services\Analytics;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Mengambil tren penjualan per interval (day, week, month) dalam rentang tanggal.
     *
     * @return array<int, array{
     *     period_key: string,
     *     period_label: string,
     *     gross_sales: string,
     *     net_sales: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     order_count: int,
     *     average_order_value: string
     * }>
     */
    public function getSalesTrend(Carbon $start, Carbon $end, string $interval = 'day', ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $groupByFormat = match ($interval) {
            'month' => '%Y-%m',
            'week' => '%x-W%v',
            default => '%Y-%m-%d',
        };

        $query = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->where('order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        $records = $query->selectRaw("
                DATE_FORMAT(order_date, '{$groupByFormat}') as period_key,
                MIN(order_date) as sample_date,
                COALESCE(SUM(order_amount), 0) as gross_sales,
                COALESCE(SUM(subtotal_amount), 0) as net_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COUNT(id) as total_orders
            ")
            ->groupBy('period_key')
            ->orderBy('period_key', 'asc')
            ->get();

        $result = [];
        foreach ($records as $record) {
            $gross = bcadd($this->toNumericString($record->gross_sales), '0', 2);
            $net = bcadd($this->toNumericString($record->net_sales), '0', 2);
            $disc = bcadd($this->toNumericString($record->total_discount), '0', 2);
            $tax = bcadd($this->toNumericString($record->total_tax), '0', 2);
            $count = (int) $record->total_orders;

            $aov = $count > 0 ? bcdiv($gross, (string) $count, 2) : '0.00';

            $result[] = [
                'period_key' => (string) $record->period_key,
                'period_label' => $this->formatPeriodLabel((string) $record->period_key, $interval, (string) $record->sample_date),
                'gross_sales' => $gross,
                'net_sales' => $net,
                'discount_amount' => $disc,
                'tax_amount' => $tax,
                'order_count' => $count,
                'average_order_value' => $aov,
            ];
        }

        return $result;
    }

    /**
     * Mengambil performa produk terlaris (Top-N SKU) berdasarkan volume dan pendapatan.
     *
     * @return array<int, array{
     *     product_id: int,
     *     product_name: string,
     *     sku: string,
     *     category_name: string,
     *     quantity_sold: int,
     *     total_revenue: string,
     *     contribution_pct: string
     * }>
     */
    public function getTopProducts(Carbon $start, Carbon $end, ?int $storeId = null, int $limit = 10): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $baseQuery = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'oi.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('oi.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$start->toDateString(), $end->toDateString()])
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $baseQuery->where('o.store_id', $storeId);
        }

        // Ambil total pendapatan seluruh produk dalam rentang untuk hitung kontribusi %
        $totalRevenueAll = (clone $baseQuery)->sum('oi.order_subtotal');
        $totalRevenueAllStr = bcadd($this->toNumericString($totalRevenueAll), '0', 2);

        $records = $baseQuery->selectRaw('
                oi.product_id,
                p.product_name,
                COALESCE(p.product_code, "-") as sku,
                COALESCE(c.category_name, "Uncategorized") as category_name,
                SUM(oi.qty) as total_qty,
                SUM(oi.order_subtotal) as total_revenue
            ')
            ->groupBy('oi.product_id', 'p.product_name', 'p.product_code', 'c.category_name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($records as $row) {
            $revenue = bcadd($this->toNumericString($row->total_revenue), '0', 2);
            $pct = '0.00';
            if (bccomp($totalRevenueAllStr, '0.00', 2) > 0) {
                $pct = bcmul(bcdiv($revenue, $totalRevenueAllStr, 4), '100', 2);
            }

            $result[] = [
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'sku' => (string) $row->sku,
                'category_name' => (string) $row->category_name,
                'quantity_sold' => (int) $row->total_qty,
                'total_revenue' => $revenue,
                'contribution_pct' => $pct,
            ];
        }

        return $result;
    }

    /**
     * Mengambil performa penjualan berdasarkan kategori produk.
     *
     * @return array<int, array{
     *     category_id: int|null,
     *     category_name: string,
     *     total_items_sold: int,
     *     total_revenue: string,
     *     contribution_pct: string
     * }>
     */
    public function getCategoryPerformance(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'oi.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('oi.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$start->toDateString(), $end->toDateString()])
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $totalRevenueAll = (clone $query)->sum('oi.order_subtotal');
        $totalRevenueAllStr = bcadd($this->toNumericString($totalRevenueAll), '0', 2);

        $records = $query->selectRaw('
                p.category_id,
                COALESCE(c.category_name, "Uncategorized") as category_name,
                SUM(oi.qty) as total_items_sold,
                SUM(oi.order_subtotal) as total_revenue
            ')
            ->groupBy('p.category_id', 'c.category_name')
            ->orderByDesc('total_revenue')
            ->get();

        $result = [];
        foreach ($records as $row) {
            $revenue = bcadd($this->toNumericString($row->total_revenue), '0', 2);
            $pct = '0.00';
            if (bccomp($totalRevenueAllStr, '0.00', 2) > 0) {
                $pct = bcmul(bcdiv($revenue, $totalRevenueAllStr, 4), '100', 2);
            }

            $result[] = [
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'category_name' => (string) $row->category_name,
                'total_items_sold' => (int) $row->total_items_sold,
                'total_revenue' => $revenue,
                'contribution_pct' => $pct,
            ];
        }

        return $result;
    }

    /**
     * Analisis Keranjang Belanja (Market Basket Analysis):
     * Mengidentifikasi produk yang sering dibeli bersama dalam satu pesanan.
     *
     * @return array<int, array{
     *     product_a_id: int,
     *     product_a_name: string,
     *     product_b_id: int,
     *     product_b_name: string,
     *     co_occurrence_count: int,
     *     support_pct: string,
     *     confidence_pct: string
     * }>
     */
    public function getBasketAnalysis(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        int $limit = 10,
        int $minOccurrences = 2
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();

        $orderCountQuery = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->where('order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $orderCountQuery->where('store_id', $storeId);
        }

        $totalOrders = $orderCountQuery->count();
        if ($totalOrders === 0) {
            return [];
        }

        // Query co-occurrence pasangan item dalam 1 order
        $pairsQuery = DB::table('order_items as a')
            ->join('order_items as b', function ($join) {
                $join->on('a.order_id', '=', 'b.order_id')
                    ->whereColumn('a.product_id', '<', 'b.product_id');
            })
            ->join('orders as o', 'a.order_id', '=', 'o.id')
            ->join('products as pa', 'a.product_id', '=', 'pa.id')
            ->join('products as pb', 'b.product_id', '=', 'pb.id')
            ->where('a.tenant_id', $tenantId)
            ->where('b.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$start->toDateString(), $end->toDateString()])
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $pairsQuery->where('o.store_id', $storeId);
        }

        $pairs = $pairsQuery->selectRaw('
                a.product_id as product_a_id,
                pa.product_name as product_a_name,
                b.product_id as product_b_id,
                pb.product_name as product_b_name,
                COUNT(DISTINCT a.order_id) as pair_count
            ')
            ->groupBy('a.product_id', 'pa.product_name', 'b.product_id', 'pb.product_name')
            ->having('pair_count', '>=', $minOccurrences)
            ->orderByDesc('pair_count')
            ->limit($limit)
            ->get();

        if ($pairs->isEmpty()) {
            return [];
        }

        // Ambil total frekuensi masing-masing produk A untuk menghitung confidence
        $productAIds = $pairs->pluck('product_a_id')->unique()->all();
        $productAFrequencies = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('oi.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$start->toDateString(), $end->toDateString()])
            ->where('o.order_status', OrderStatus::Paid->value)
            ->whereIn('oi.product_id', $productAIds)
            ->selectRaw('oi.product_id, COUNT(DISTINCT oi.order_id) as order_count')
            ->groupBy('oi.product_id')
            ->pluck('order_count', 'product_id');

        $result = [];
        foreach ($pairs as $pair) {
            $pairCount = (int) $pair->pair_count;
            $countA = (int) ($productAFrequencies[$pair->product_a_id] ?? $pairCount);

            $supportPct = bcmul(bcdiv((string) $pairCount, (string) $totalOrders, 4), '100', 2);
            $confidencePct = $countA > 0 ? bcmul(bcdiv((string) $pairCount, (string) $countA, 4), '100', 2) : '0.00';

            $result[] = [
                'product_a_id' => (int) $pair->product_a_id,
                'product_a_name' => (string) $pair->product_a_name,
                'product_b_id' => (int) $pair->product_b_id,
                'product_b_name' => (string) $pair->product_b_name,
                'co_occurrence_count' => $pairCount,
                'support_pct' => $supportPct,
                'confidence_pct' => $confidencePct,
            ];
        }

        return $result;
    }

    /**
     * Peta Panas Jam Sibuk (Peak Hours Heatmap 24 Jam x 7 Hari):
     * Menghitung distribusi transaksi dan nilai penjualan berdasarkan jam dan hari dalam seminggu.
     *
     * @return array<int, array{
     *     day_of_week: int,
     *     day_name: string,
     *     hours: array<int, array{
     *         hour: int,
     *         order_count: int,
     *         total_sales: string
     *     }>
     * }>
     */
    public function getPeakHoursHeatmap(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $query = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->where('order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        // MySQL WEEKDAY(): 0 = Monday, 1 = Tuesday, ..., 6 = Sunday
        $records = $query->selectRaw('
                WEEKDAY(created_at) as day_index,
                HOUR(created_at) as hour_of_day,
                COUNT(id) as total_orders,
                SUM(order_amount) as total_sales
            ')
            ->groupBy('day_index', 'hour_of_day')
            ->get();

        $lookup = [];
        foreach ($records as $row) {
            $key = "{$row->day_index}_{$row->hour_of_day}";
            $lookup[$key] = [
                'count' => (int) $row->total_orders,
                'sales' => bcadd($this->toNumericString($row->total_sales), '0', 2),
            ];
        }

        $dayNames = [
            0 => 'Senin',
            1 => 'Selasa',
            2 => 'Rabu',
            3 => 'Kamis',
            4 => 'Jumat',
            5 => 'Sabtu',
            6 => 'Minggu',
        ];

        $heatmap = [];
        for ($d = 0; $d < 7; $d++) {
            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $cellKey = "{$d}_{$h}";
                $hours[] = [
                    'hour' => $h,
                    'order_count' => $lookup[$cellKey]['count'] ?? 0,
                    'total_sales' => $lookup[$cellKey]['sales'] ?? '0.00',
                ];
            }

            $heatmap[] = [
                'day_of_week' => $d + 1, // 1 = Senin, ..., 7 = Minggu
                'day_name' => $dayNames[$d],
                'hours' => $hours,
            ];
        }

        return $heatmap;
    }

    /**
     * Perbandingan Month-over-Month (MoM):
     * Membandingkan bulan acuan dengan satu bulan sebelumnya.
     *
     * @return array{
     *     current_period: string,
     *     previous_period: string,
     *     current_revenue: string,
     *     previous_revenue: string,
     *     revenue_growth_pct: string,
     *     current_orders: int,
     *     previous_orders: int,
     *     orders_growth_pct: string,
     *     current_aov: string,
     *     previous_aov: string,
     *     aov_growth_pct: string
     * }
     */
    public function getMomComparison(Carbon $targetMonth, ?int $storeId = null): array
    {
        $currentStart = $targetMonth->copy()->startOfMonth();
        $currentEnd = $targetMonth->copy()->endOfMonth();

        $previousStart = $currentStart->copy()->subMonth()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();

        return $this->computeComparison(
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            $currentStart->format('Y-m'),
            $previousStart->format('Y-m'),
            $storeId
        );
    }

    /**
     * Perbandingan Year-over-Year (YoY):
     * Membandingkan periode acuan dengan periode yang sama pada tahun sebelumnya.
     *
     * @return array{
     *     current_period: string,
     *     previous_period: string,
     *     current_revenue: string,
     *     previous_revenue: string,
     *     revenue_growth_pct: string,
     *     current_orders: int,
     *     previous_orders: int,
     *     orders_growth_pct: string,
     *     current_aov: string,
     *     previous_aov: string,
     *     aov_growth_pct: string
     * }
     */
    public function getYoyComparison(Carbon $targetDate, ?int $storeId = null): array
    {
        $currentStart = $targetDate->copy()->startOfMonth();
        $currentEnd = $targetDate->copy()->endOfMonth();

        $previousStart = $currentStart->copy()->subYear()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();

        return $this->computeComparison(
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            $currentStart->format('Y-m'),
            $previousStart->format('Y-m'),
            $storeId
        );
    }

    /**
     * @return array{
     *     current_period: string,
     *     previous_period: string,
     *     current_revenue: string,
     *     previous_revenue: string,
     *     revenue_growth_pct: string,
     *     current_orders: int,
     *     previous_orders: int,
     *     orders_growth_pct: string,
     *     current_aov: string,
     *     previous_aov: string,
     *     aov_growth_pct: string
     * }
     */
    private function computeComparison(
        Carbon $curStart,
        Carbon $curEnd,
        Carbon $prevStart,
        Carbon $prevEnd,
        string $curLabel,
        string $prevLabel,
        ?int $storeId
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();

        $fetchSummary = function (Carbon $start, Carbon $end) use ($tenantId, $storeId): array {
            $q = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
                ->where('order_status', OrderStatus::Paid->value);

            if ($storeId !== null) {
                $q->where('store_id', $storeId);
            }

            $row = $q->selectRaw('COALESCE(SUM(order_amount), 0) as rev, COUNT(id) as cnt')->first();

            $rev = bcadd($this->toNumericString($row->rev ?? '0'), '0', 2);
            $cnt = (int) ($row->cnt ?? 0);
            $aov = $cnt > 0 ? bcdiv($rev, (string) $cnt, 2) : '0.00';

            return ['revenue' => $rev, 'orders' => $cnt, 'aov' => $aov];
        };

        $current = $fetchSummary($curStart, $curEnd);
        $previous = $fetchSummary($prevStart, $prevEnd);

        $calcGrowth = function (string $cur, string $prev): string {
            $curNum = $this->toNumericString($cur);
            $prevNum = $this->toNumericString($prev);

            if (bccomp($prevNum, '0.00', 2) === 0) {
                return bccomp($curNum, '0.00', 2) > 0 ? '100.00' : '0.00';
            }
            $diff = bcsub($curNum, $prevNum, 2);

            return bcmul(bcdiv($diff, $prevNum, 4), '100', 2);
        };

        $ordersGrowth = '0.00';
        if ($previous['orders'] === 0) {
            $ordersGrowth = $current['orders'] > 0 ? '100.00' : '0.00';
        } else {
            $diffOrders = $current['orders'] - $previous['orders'];
            $ordersGrowth = bcmul(bcdiv((string) $diffOrders, (string) $previous['orders'], 4), '100', 2);
        }

        return [
            'current_period' => $curLabel,
            'previous_period' => $prevLabel,
            'current_revenue' => $current['revenue'],
            'previous_revenue' => $previous['revenue'],
            'revenue_growth_pct' => $calcGrowth($current['revenue'], $previous['revenue']),
            'current_orders' => $current['orders'],
            'previous_orders' => $previous['orders'],
            'orders_growth_pct' => $ordersGrowth,
            'current_aov' => $current['aov'],
            'previous_aov' => $previous['aov'],
            'aov_growth_pct' => $calcGrowth($current['aov'], $previous['aov']),
        ];
    }

    private function formatPeriodLabel(string $key, string $interval, string $sampleDate): string
    {
        return match ($interval) {
            'month' => Carbon::parse($sampleDate)->translatedFormat('F Y'),
            'week' => "Pekan {$key}",
            default => Carbon::parse($sampleDate)->translatedFormat('d M Y'),
        };
    }

    /**
     * Mengonversi nilai arbitrary menjadi numeric-string yang valid untuk fungsi bcmath.
     *
     * @return numeric-string
     */
    private function toNumericString(mixed $value, string $default = '0.00'): string
    {
        if ($value === null) {
            return '0.00';
        }

        $str = (string) $value;

        return is_numeric($str) ? $str : '0.00';
    }
}
