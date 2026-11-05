<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services\Analytics;

use App\Enums\OrderStatus;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Reports\Services\MetricRegistry;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    protected MetricRegistry $metricRegistry;

    public function __construct(
        protected TenantContext $tenantContext,
        ?MetricRegistry $metricRegistry = null,
    ) {
        $this->metricRegistry = $metricRegistry ?? app(MetricRegistry::class);
    }

    /**
     * Mengambil tren penjualan per interval (day, week, month) dalam rentang tanggal.
     * Net Sales direkonsiliasi terhadap General Ledger akun pendapatan (4-10000 / REVENUE)
     * apabila jurnal buku besar tersedia pada periode tersebut, atau dihitung melalui
     * formula kanonikal MetricRegistry (Gross Sales - Discounts - Returns).
     *
     * @return array<int, array{
     *     period_key: string,
     *     period_label: string,
     *     gross_sales: string,
     *     net_sales: string,
     *     gl_net_sales: string,
     *     discount_amount: string,
     *     return_amount: string,
     *     tax_amount: string,
     *     order_count: int,
     *     average_order_value: string
     * }>
     */
    public function getSalesTrend(Carbon $start, Carbon $end, string $interval = 'day', ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $groupByFormat = match ($interval) {
            'month', 'monthly' => '%Y-%m',
            'week', 'weekly' => '%x-W%v',
            default => '%Y-%m-%d',
        };

        // 1. Agregasi operasional dari tabel orders
        $orderQuery = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$startStr, $endStr])
            ->where('order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $orderQuery->where('store_id', $storeId);
        }

        $orderRecords = $orderQuery->selectRaw("
                DATE_FORMAT(order_date, '{$groupByFormat}') as period_key,
                MIN(order_date) as sample_date,
                COALESCE(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE order_amount END), 0) as gross_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COUNT(id) as total_orders
            ")
            ->groupBy('period_key')
            ->orderBy('period_key', 'asc')
            ->get()
            ->keyBy('period_key');

        // 2. Agregasi retur penjualan yang telah selesai (completed)
        $returnQuery = DB::table('order_returns')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween(DB::raw('DATE(COALESCE(completed_at, created_at))'), [$startStr, $endStr]);

        if ($storeId !== null) {
            $returnQuery->where('store_id', $storeId);
        }

        $returnRecords = $returnQuery->selectRaw("
                DATE_FORMAT(DATE(COALESCE(completed_at, created_at)), '{$groupByFormat}') as period_key,
                MIN(DATE(COALESCE(completed_at, created_at))) as sample_date,
                COALESCE(SUM(subtotal_returned), 0) as total_returned
            ")
            ->groupBy('period_key')
            ->get()
            ->keyBy('period_key');

        // 3. Agregasi otoritatif General Ledger (akun 4-10000 / REVENUE) per interval
        $glQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', function ($join): void {
                $join->on('jl.journal_entry_id', '=', 'je.id')
                    ->on('jl.tenant_id', '=', 'je.tenant_id');
            })
            ->join('chart_of_accounts as coa', function ($join): void {
                $join->on('jl.account_id', '=', 'coa.id')
                    ->on('coa.tenant_id', '=', 'je.tenant_id');
            })
            ->leftJoin('accounting_periods as ap', function ($join): void {
                $join->on('je.accounting_period_id', '=', 'ap.id')
                    ->on('ap.tenant_id', '=', 'je.tenant_id');
            })
            ->where('je.tenant_id', $tenantId)
            ->whereBetween('je.posting_date', [$startStr, $endStr])
            ->where(function ($q): void {
                $q->whereNull('ap.id')
                    ->orWhereRaw('je.posting_date BETWEEN ap.start_date AND ap.end_date');
            })
            ->where(function ($q): void {
                $q->where('je.status', JournalStatusEnum::POSTED->value)
                    ->orWhere(function ($sub): void {
                        $sub->where('je.status', JournalStatusEnum::REVERSED->value)
                            ->where(function ($revCheck): void {
                                $revCheck->whereNotNull('je.reversed_by_journal_id')
                                    ->orWhereExists(function ($exists): void {
                                        $exists->select(DB::raw(1))
                                            ->from('journal_entries as rev_je')
                                            ->whereColumn('rev_je.tenant_id', 'je.tenant_id')
                                            ->whereColumn('rev_je.reversal_of_journal_id', 'je.id')
                                            ->where('rev_je.status', JournalStatusEnum::POSTED->value);
                                    });
                            });
                    });
            })
            ->where(function ($q): void {
                $q->where('coa.account_type', AccountTypeEnum::REVENUE->value)
                    ->orWhere('coa.account_code', '4-10000')
                    ->orWhere('coa.account_code', 'like', '4-1%');
            });

        if ($storeId !== null) {
            $glQuery->whereRaw('COALESCE(jl.store_id, je.store_id) = ?', [$storeId]);
        }

        $glRecords = $glQuery->selectRaw("
                DATE_FORMAT(je.posting_date, '{$groupByFormat}') as period_key,
                MIN(je.posting_date) as sample_date,
                COALESCE(SUM(jl.credit_amount - jl.debit_amount), 0) as gl_net_sales,
                COUNT(DISTINCT je.id) as journal_count
            ")
            ->groupBy('period_key')
            ->get()
            ->keyBy('period_key');

        $allPeriodKeys = collect($orderRecords->keys())
            ->merge($returnRecords->keys())
            ->merge($glRecords->keys())
            ->unique()
            ->sort()
            ->values();

        $result = [];
        foreach ($allPeriodKeys as $periodKey) {
            $keyStr = (string) $periodKey;
            $ordRow = $orderRecords->get($keyStr);
            $retRow = $returnRecords->get($keyStr);
            $glRow = $glRecords->get($keyStr);

            $sampleDate = (string) ($ordRow->sample_date ?? $glRow->sample_date ?? $retRow->sample_date ?? $startStr);
            $gross = $this->metricRegistry->toNumericString($ordRow->gross_sales ?? '0.00', 2);
            $disc = $this->metricRegistry->toNumericString($ordRow->total_discount ?? '0.00', 2);
            $ret = $this->metricRegistry->toNumericString($retRow->total_returned ?? '0.00', 2);
            $tax = $this->metricRegistry->toNumericString($ordRow->total_tax ?? '0.00', 2);
            $count = (int) ($ordRow->total_orders ?? 0);

            $operationalNet = $this->metricRegistry->calculateNetSales($gross, $disc, $ret);
            $hasGlJournals = $glRow !== null && ((int) $glRow->journal_count) > 0;
            $glNet = $hasGlJournals
                ? $this->metricRegistry->toNumericString($glRow->gl_net_sales, 2)
                : $operationalNet;

            // Jika jurnal GL terposting untuk periode ini, Net Sales menggunakan angka otoritatif GL
            $net = $hasGlJournals ? $glNet : $operationalNet;

            // Jika hanya jurnal GL yang ada tanpa baris orders operasional, sinkronkan gross_sales
            if ($ordRow === null && $hasGlJournals) {
                $gross = $glNet;
            }

            $aov = $this->metricRegistry->calculateAverageOrderValue($net, $count);

            $result[] = [
                'period_key' => $keyStr,
                'period_label' => $this->formatPeriodLabel($keyStr, $interval, $sampleDate),
                'gross_sales' => $gross,
                'net_sales' => $net,
                'gl_net_sales' => $glNet,
                'discount_amount' => $disc,
                'return_amount' => $ret,
                'tax_amount' => $tax,
                'order_count' => $count,
                'average_order_value' => $aov,
            ];
        }

        return $result;
    }

    /**
     * Mengambil angka Net Sales otoritatif dari General Ledger (akun 4-10000 / REVENUE).
     */
    public function getGlNetSales(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?string $accountCode = null
    ): string {
        $tenantId = $this->tenantContext->requireTenantId();

        return $this->metricRegistry->computeGlNetSales($start, $end, $storeId, $tenantId, $accountCode);
    }

    /**
     * Mengambil rincian komponen pendapatan penjualan pada General Ledger.
     *
     * @return array{
     *     gross_revenue: string,
     *     sales_discounts: string,
     *     sales_returns: string,
     *     net_sales: string,
     *     journal_count: int
     * }
     */
    public function getGlSalesBreakdown(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?string $accountCode = null
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();

        return $this->metricRegistry->computeGlRevenueBreakdown($start, $end, $storeId, $tenantId, $accountCode);
    }

    /**
     * Merekonsiliasi Net Sales operasional (orders & order_returns) dengan General Ledger (akun 4-10000 / REVENUE).
     *
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     store_id: int|null,
     *     operational_gross_sales: string,
     *     operational_discounts: string,
     *     operational_returns: string,
     *     operational_net_sales: string,
     *     gl_gross_revenue: string,
     *     gl_discounts: string,
     *     gl_returns: string,
     *     gl_net_sales: string,
     *     variance: string,
     *     is_reconciled: bool
     * }
     */
    public function reconcileNetSalesWithGl(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?string $accountCode = null
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $orderQuery = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$startStr, $endStr])
            ->where('order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $orderQuery->where('store_id', $storeId);
        }

        $ordTotals = $orderQuery->selectRaw('
            COALESCE(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE order_amount END), 0) as gross_sales,
            COALESCE(SUM(discount_amount), 0) as total_discounts
        ')->first();

        $returnQuery = DB::table('order_returns')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween(DB::raw('DATE(COALESCE(completed_at, created_at))'), [$startStr, $endStr]);

        if ($storeId !== null) {
            $returnQuery->where('store_id', $storeId);
        }

        $rawReturns = $returnQuery->sum('subtotal_returned');

        $opGross = $this->metricRegistry->toNumericString($ordTotals->gross_sales ?? '0.00', 2);
        $opDiscounts = $this->metricRegistry->toNumericString($ordTotals->total_discounts ?? '0.00', 2);
        $opReturns = $this->metricRegistry->toNumericString($rawReturns, 2);
        $opNet = $this->metricRegistry->calculateNetSales($opGross, $opDiscounts, $opReturns);

        $glBreakdown = $this->metricRegistry->computeGlRevenueBreakdown($start, $end, $storeId, $tenantId, $accountCode);
        $glNet = $glBreakdown['net_sales'];

        $variance = bcsub(bc_num($opNet), bc_num($glNet), 2);

        return [
            'start_date' => $startStr,
            'end_date' => $endStr,
            'store_id' => $storeId,
            'operational_gross_sales' => $opGross,
            'operational_discounts' => $opDiscounts,
            'operational_returns' => $opReturns,
            'operational_net_sales' => $opNet,
            'gl_gross_revenue' => $glBreakdown['gross_revenue'],
            'gl_discounts' => $glBreakdown['sales_discounts'],
            'gl_returns' => $glBreakdown['sales_returns'],
            'gl_net_sales' => $glNet,
            'variance' => $variance,
            'is_reconciled' => bccomp($variance, '0.00', 2) === 0,
        ];
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
        $totalRevenueAllStr = $this->metricRegistry->toNumericString($totalRevenueAll, 2);

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
            $revenue = $this->metricRegistry->toNumericString($row->total_revenue, 2);
            $pct = $this->metricRegistry->calculateContributionPct($revenue, $totalRevenueAllStr);

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
        $totalRevenueAllStr = $this->metricRegistry->toNumericString($totalRevenueAll, 2);

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
            $revenue = $this->metricRegistry->toNumericString($row->total_revenue, 2);
            $pct = $this->metricRegistry->calculateContributionPct($revenue, $totalRevenueAllStr);

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
            ->join('order_items as b', function ($join): void {
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

            $supportPct = $this->metricRegistry->calculateContributionPct($pairCount, $totalOrders);
            $confidencePct = $countA > 0
                ? $this->metricRegistry->calculateContributionPct($pairCount, $countA)
                : '0.00';

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
                'sales' => $this->metricRegistry->toNumericString($row->total_sales, 2),
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

            $row = $q->selectRaw('
                COALESCE(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE order_amount END), 0) as gross,
                COALESCE(SUM(discount_amount), 0) as disc,
                COUNT(id) as cnt
            ')->first();

            $returnQuery = DB::table('order_returns')
                ->where('tenant_id', $tenantId)
                ->where('status', 'completed')
                ->whereBetween(DB::raw('DATE(COALESCE(completed_at, created_at))'), [$start->toDateString(), $end->toDateString()]);

            if ($storeId !== null) {
                $returnQuery->where('store_id', $storeId);
            }

            $returned = $returnQuery->sum('subtotal_returned');

            $opNet = $this->metricRegistry->calculateNetSales(
                $row->gross ?? '0.00',
                $row->disc ?? '0.00',
                $returned
            );

            $glBreakdown = $this->metricRegistry->computeGlRevenueBreakdown($start, $end, $storeId, $tenantId);
            $rev = $glBreakdown['journal_count'] > 0 ? $glBreakdown['net_sales'] : $opNet;

            $cnt = (int) ($row->cnt ?? 0);
            $aov = $this->metricRegistry->calculateAverageOrderValue($rev, $cnt);

            return ['revenue' => $rev, 'orders' => $cnt, 'aov' => $aov];
        };

        $current = $fetchSummary($curStart, $curEnd);
        $previous = $fetchSummary($prevStart, $prevEnd);

        return [
            'current_period' => $curLabel,
            'previous_period' => $prevLabel,
            'current_revenue' => $current['revenue'],
            'previous_revenue' => $previous['revenue'],
            'revenue_growth_pct' => $this->metricRegistry->calculateGrowthPct($current['revenue'], $previous['revenue']),
            'current_orders' => $current['orders'],
            'previous_orders' => $previous['orders'],
            'orders_growth_pct' => $this->metricRegistry->calculateGrowthPct($current['orders'], $previous['orders']),
            'current_aov' => $current['aov'],
            'previous_aov' => $previous['aov'],
            'aov_growth_pct' => $this->metricRegistry->calculateGrowthPct($current['aov'], $previous['aov']),
        ];
    }

    private function formatPeriodLabel(string $key, string $interval, string $sampleDate): string
    {
        return match ($interval) {
            'month', 'monthly' => Carbon::parse($sampleDate)->translatedFormat('F Y'),
            'week', 'weekly' => "Pekan {$key}",
            default => Carbon::parse($sampleDate)->translatedFormat('d M Y'),
        };
    }
}
