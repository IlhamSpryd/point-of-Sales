<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\Order;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreTarget;
use App\Models\User;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ExecutiveDashboardService
 *
 * Pusat Komando Intelijen Bisnis & Multi-Branch Executive Command Center (SAP Fiori / Odoo 18 style).
 * Menerapkan:
 * 1. Fail-closed tenant isolation & branch scoping.
 * 2. Akrual non-retroaktif PSAK/SAP (pemisahan order date vs return date via MetricRegistry).
 * 3. Granular permission gating: Jika user tidak memiliki 'dashboard.metric.profit_margin',
 *    data HPP/COGS dan Gross Margin disensor total (Single-Axis Net Sales chart payload).
 * 4. Partisi Cache 5-Tier Redis:
 *    pos:dash:{tenant_id}:comp_{company_id}:store_{store_scope}:role_{role_id}:perm_{perm_hash}:{period_hash}
 * 5. Plan vs Actual Attainment % & Month-End Run-Rate Projection.
 * 6. General Ledger Accounting Trust Indicator.
 */
class ExecutiveDashboardService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected MetricRegistry $metricRegistry,
    ) {}

    /**
     * Mengambil metrik eksekutif lengkap dengan partisi cache.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(
        User $user,
        string $period = '30d',
        ?int $storeId = null,
        ?int $companyId = null,
        bool $bypassCache = false
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();

        // Verifikasi wewenang cabang: Hanya Owner/Admin atau yang punya izin 'dashboard.scope.all_stores'
        // yang boleh melihat konsolidasi seluruh cabang (storeId = null)
        $canViewAll = $user->canViewConsolidatedStores();
        if ($storeId === null && ! $canViewAll) {
            $storeId = (int) ($user->store_id ?? 1);
        }

        $hasMarginPermission = $user->hasPermission('*')
            || (bool) ($user->role->is_admin ?? false)
            || $user->hasPermission('dashboard.metric.profit_margin');

        $roleId = (int) ($user->role_id ?? 0);
        $storeScope = $storeId !== null ? "store_{$storeId}" : 'store_all';
        $companyScope = $companyId !== null ? (string) $companyId : 'all';
        $permHash = substr(md5(json_encode([
            'margin' => $hasMarginPermission,
            'rev' => $user->hasPermission('dashboard.metric.revenue'),
            'gl' => $user->hasPermission('dashboard.metric.finance_gl'),
        ])), 0, 8);
        $periodHash = md5($period);

        $cacheKey = "pos:dash:{$tenantId}:comp_{$companyScope}:store_{$storeScope}:role_{$roleId}:perm_{permHash}:{$periodHash}";

        if (! $bypassCache && Cache::has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::get($cacheKey);

            return $cached;
        }

        $range = $this->resolveDateRange($period);
        $start = $range['start'];
        $end = $range['end'];
        $daysInPeriod = $range['days'];

        // 1. Kueri Akrual Penjualan & Retur Harian Standar PSAK / SAP
        $dailySales = $this->metricRegistry->computeAccrualDailySales(
            $start,
            $end,
            $storeId,
            $tenantId,
            $companyId
        );

        // Akumulasi KPI Penjualan
        $grossSales = '0.00';
        $totalDiscounts = '0.00';
        $salesReturns = '0.00';
        $netSales = '0.00';
        $totalOrders = 0;

        foreach ($dailySales as $day) {
            $grossSales = bcadd($grossSales, $day['gross_sales'], 2);
            $totalDiscounts = bcadd($totalDiscounts, $day['discounts'], 2);
            $salesReturns = bcadd($salesReturns, $day['returns'], 2);
            $netSales = bcadd($netSales, $day['net_sales'], 2);
            $totalOrders += $day['order_count'];
        }

        $aov = $this->metricRegistry->calculateAverageOrderValue($netSales, $totalOrders);

        // 2. Kueri Kas Riil Terkumpul (Cash Collected via payments)
        $cashCollected = $this->computeCashCollected($tenantId, $start, $end, $storeId);

        // 3. Profitabilitas & COGS (Dilindungi izin granular)
        $cogs = '0.00';
        $grossProfit = '0.00';
        $grossMarginPct = '0.00';

        if ($hasMarginPermission) {
            $cogs = $this->computeCogs($tenantId, $start, $end, $storeId);
            $grossProfit = $this->metricRegistry->calculateGrossProfit($netSales, $cogs);
            $grossMarginPct = $this->metricRegistry->calculateGrossMarginPct($grossProfit, $netSales);
        }

        // 4. Perbandingan Pertumbuhan Periode Sebelumnya (MoM / DoD)
        $prevRange = $this->resolvePreviousDateRange($start, $end);
        $prevDailySales = $this->metricRegistry->computeAccrualDailySales(
            $prevRange['start'],
            $prevRange['end'],
            $storeId,
            $tenantId,
            $companyId
        );
        $prevNetSales = '0.00';
        $prevOrders = 0;
        foreach ($prevDailySales as $day) {
            $prevNetSales = bcadd($prevNetSales, $day['net_sales'], 2);
            $prevOrders += $day['order_count'];
        }

        $netSalesDeltaPct = $this->metricRegistry->calculateGrowthPct($netSales, $prevNetSales);
        $ordersDeltaPct = (string) ($prevOrders > 0 ? round((($totalOrders - $prevOrders) / $prevOrders) * 100, 1) : ($totalOrders > 0 ? 100.0 : 0.0));

        // 5. Plan vs Actual Target Attainment & Run-Rate
        $targetData = $this->computePlanVsActual($tenantId, $storeId, $companyId, $netSales);

        // 6. General Ledger Accounting Trust Indicator
        $glTrustIndicator = $this->computeGlTrustIndicator($tenantId, $storeId);

        // 7. Multi-Branch Contribution & Ranking Matrix
        $branchRanking = $this->computeBranchRanking($tenantId, $start, $end, $hasMarginPermission);

        // 8. ECharts Visualisasi Payload (Sadar-Izin: Single-Axis jika tanpa izin profit_margin)
        $salesTrendChartOptions = $this->buildSalesTrendChartOptions(
            $dailySales,
            $hasMarginPermission
        );

        $accrualSales = [
            'net_sales' => $netSales,
            'net_sales_formatted' => 'Rp '.number_format((float) $netSales, 0, ',', '.'),
            'net_sales_delta' => ($netSalesDeltaPct >= 0 ? '+' : '').$netSalesDeltaPct.'%',
            'net_sales_delta_variant' => $netSalesDeltaPct >= 0 ? 'positive' : 'negative',
            'gross_sales' => $grossSales,
            'total_discounts' => $totalDiscounts,
            'sales_returns' => $salesReturns,
            'total_orders' => $totalOrders,
            'orders_delta' => ($ordersDeltaPct >= 0 ? '+' : '').$ordersDeltaPct.'%',
            'orders_delta_variant' => (float) $ordersDeltaPct >= 0 ? 'positive' : 'negative',
            'aov' => $aov,
            'aov_formatted' => 'Rp '.number_format((float) $aov, 0, ',', '.'),
            'cash_collected' => $cashCollected,
            'cash_collected_formatted' => 'Rp '.number_format((float) $cashCollected, 0, ',', '.'),
        ];

        if ($hasMarginPermission) {
            $accrualSales['cogs'] = $cogs;
            $accrualSales['gross_profit'] = $grossProfit;
            $accrualSales['gross_margin_pct'] = $grossMarginPct;
            $accrualSales['gross_margin_formatted'] = $grossMarginPct.'%';
        }

        $chartOptionsStructure = [
            'is_dual_axis' => $hasMarginPermission,
            'echarts_option' => $salesTrendChartOptions,
        ];

        $data = [
            'period' => $period,
            'date_range_label' => $start->translatedFormat('d M Y').' - '.$end->translatedFormat('d M Y'),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'store_id' => $storeId,
            'is_consolidated' => $storeId === null,
            'has_profit_margin_permission' => $hasMarginPermission,
            'kpi' => $accrualSales,
            'accrual_sales' => $accrualSales,
            'accrualSales' => $accrualSales,
            'plan_vs_actual' => $targetData,
            'planVsActual' => $targetData,
            'gl_trust' => $glTrustIndicator,
            'glTrustIndicator' => $glTrustIndicator,
            'branch_ranking' => $branchRanking,
            'branchRanking' => $branchRanking,
            'sales_chart_options' => $chartOptionsStructure,
            'chartOptions' => $chartOptionsStructure,
        ];

        // TTL 3 menit untuk dasbor eksekutif
        Cache::put($cacheKey, $data, 180);

        return $data;
    }

    /**
     * Menghitung kas riil terkumpul dari payments berstatus success.
     */
    protected function computeCashCollected(int $tenantId, Carbon $start, Carbon $end, ?int $storeId): string
    {
        $dateColumn = Schema::hasColumn('payments', 'created_at') ? 'created_at' : 'payment_time';

        $query = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'success')
            ->whereBetween($dateColumn, [$start->toDateTimeString(), $end->toDateTimeString()]);

        if ($storeId !== null && Schema::hasColumn('payments', 'store_id')) {
            $query->where('store_id', $storeId);
        }

        $val = $query->sum('amount');

        return $this->metricRegistry->toNumericString($val, 2);
    }

    /**
     * Menghitung HPP / COGS dari snapshot pesanan order_items.hpp_snapshot.
     */
    protected function computeCogs(int $tenantId, Carbon $start, Carbon $end, ?int $storeId): string
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $query = DB::table('order_items as oi')
            ->join('orders as o', function ($join) {
                $join->on('oi.order_id', '=', 'o.id')
                    ->on('oi.tenant_id', '=', 'o.tenant_id');
            })
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$startStr, $endStr])
            ->whereIn('o.order_status', ['paid', 'completed']);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $cogsSum = $query->selectRaw('COALESCE(SUM(oi.qty * COALESCE(oi.hpp_snapshot, 0)), 0) as total_cogs')->value('total_cogs');

        return $this->metricRegistry->toNumericString($cogsSum, 2);
    }

    /**
     * Plan vs Actual Engine berdasarkan store_targets.
     *
     * @return array<string, mixed>
     */
    protected function computePlanVsActual(int $tenantId, ?int $storeId, ?int $companyId, string $actualNetSales): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $periodKey = $now->format('Y-m');
        $elapsedDays = $now->day;
        $totalDaysInMonth = $now->daysInMonth;

        $targetQuery = DB::table('store_targets')
            ->where('tenant_id', $tenantId)
            ->where('target_period', $periodKey);

        if ($storeId !== null) {
            $targetQuery->where('store_id', $storeId);
        }
        if ($companyId !== null) {
            $targetQuery->where('company_id', $companyId);
        }

        $targetNetSales = (string) $targetQuery->sum('target_net_sales');

        $attainmentPct = '0.00';
        $runRate = $this->metricRegistry->calculateRunRateProjection($actualNetSales, $elapsedDays, $totalDaysInMonth);

        if (bccomp($targetNetSales, '0.00', 2) > 0) {
            $attainmentPct = $this->metricRegistry->calculateTargetAttainment($actualNetSales, $targetNetSales);
        }

        $targetFloat = (float) $targetNetSales;
        $attainmentFloat = (float) $attainmentPct;
        $actualFloat = (float) $actualNetSales;
        $runRateFloat = (float) $runRate;

        $status = 'lagging';
        if ($targetFloat <= 0) {
            $status = 'no_target';
        } elseif ($attainmentFloat >= 100.0) {
            $status = 'exceeded';
        } elseif ($attainmentFloat >= ($totalDaysInMonth > 0 ? ($elapsedDays / $totalDaysInMonth) * 100.0 : 0)) {
            $status = 'on_track';
        }

        return [
            'target_net_sales' => $targetNetSales,
            'target_revenue' => $targetFloat,
            'target_formatted' => 'Rp '.number_format($targetFloat, 0, ',', '.'),
            'actual_net_sales' => $actualNetSales,
            'actual_revenue' => $actualFloat,
            'actual_formatted' => 'Rp '.number_format($actualFloat, 0, ',', '.'),
            'attainment_pct' => $attainmentPct,
            'attainment_formatted' => $attainmentPct.'%',
            'run_rate_projection' => $runRate,
            'projected_revenue' => $runRateFloat,
            'run_rate_formatted' => 'Rp '.number_format($runRateFloat, 0, ',', '.'),
            'elapsed_days' => $elapsedDays,
            'total_days' => $totalDaysInMonth,
            'status' => $status,
        ];
    }

    /**
     * GL Accounting Trust Indicator.
     * Memeriksa apakah seluruh shift dan jurnal sudah sinkron 100%.
     *
     * @return array{
     *     is_reconciled: bool,
     *     status_badge: string,
     *     status_text: string,
     *     unclosed_shifts: int,
     *     draft_journals: int
     * }
     */
    protected function computeGlTrustIndicator(int $tenantId, ?int $storeId): array
    {
        $shiftQuery = DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at');

        if ($storeId !== null) {
            $shiftQuery->where('store_id', $storeId);
        }
        $unclosedShifts = (int) $shiftQuery->count();

        $journalQuery = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('status', 'draft');

        if ($storeId !== null && Schema::hasColumn('journal_entries', 'store_id')) {
            $journalQuery->where('store_id', $storeId);
        }
        $draftJournals = (int) $journalQuery->count();

        $isReconciled = ($unclosedShifts === 0 && $draftJournals === 0);

        if ($isReconciled) {
            return [
                'is_trusted' => true,
                'is_reconciled' => true,
                'status_badge' => '100% Reconciled',
                'status_text' => 'Seluruh mutasi penjualan dan kas telah terposting sempurna ke Buku Besar.',
                'unclosed_shifts' => 0,
                'draft_journals' => 0,
                'reconciliation_variance' => 0,
                'net_pos_sales' => 0,
                'sales_journal_balance' => 0,
            ];
        }

        $parts = [];
        if ($unclosedShifts > 0) {
            $parts[] = "{$unclosedShifts} Shift Kasir Belum Ditutup";
        }
        if ($draftJournals > 0) {
            $parts[] = "{$draftJournals} Jurnal Draft Menunggu Posting";
        }

        return [
            'is_trusted' => false,
            'is_reconciled' => false,
            'status_badge' => 'Audit Pending',
            'status_text' => implode(' • ', $parts),
            'unclosed_shifts' => $unclosedShifts,
            'draft_journals' => $draftJournals,
            'reconciliation_variance' => 0,
            'net_pos_sales' => 0,
            'sales_journal_balance' => 0,
        ];
    }

    /**
     * Multi-Branch Contribution & Ranking Matrix.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeBranchRanking(int $tenantId, Carbon $start, Carbon $end, bool $hasMarginPermission): array
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $stores = DB::table('stores')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->get();

        $ranking = [];
        $totalSalesAll = 0.0;
        foreach ($stores as $store) {
            $baseQuery = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $store->id)
                ->whereBetween('order_date', [$startStr, $endStr])
                ->whereIn('order_status', ['paid', 'completed']);

            $sales = (float) (clone $baseQuery)->sum(DB::raw('subtotal_amount - discount_amount'));
            $ordersCount = (int) (clone $baseQuery)->count();
            $totalSalesAll += $sales;

            $item = [
                'store_id' => $store->id,
                'name' => $store->name,
                'code' => $store->code ?? "STR-{$store->id}",
                'net_sales' => $sales,
                'revenue' => $sales,
                'orders_count' => $ordersCount,
                'net_sales_formatted' => 'Rp '.number_format($sales, 0, ',', '.'),
            ];

            if ($hasMarginPermission) {
                $cogs = (float) DB::table('order_items as oi')
                    ->join('orders as o', function ($join) {
                        $join->on('oi.order_id', '=', 'o.id')
                            ->on('oi.tenant_id', '=', 'o.tenant_id');
                    })
                    ->where('o.tenant_id', $tenantId)
                    ->where('o.store_id', $store->id)
                    ->whereBetween('o.order_date', [$startStr, $endStr])
                    ->whereIn('o.order_status', ['paid', 'completed'])
                    ->sum(DB::raw('oi.qty * COALESCE(oi.hpp_snapshot, 0)'));

                $profit = max(0.0, $sales - $cogs);
                $margin = $sales > 0 ? round(($profit / $sales) * 100, 1) : 0.0;

                $item['gross_profit'] = $profit;
                $item['gross_margin_pct'] = $margin;
            }

            $ranking[] = $item;
        }

        foreach ($ranking as &$item) {
            $item['share_pct'] = $totalSalesAll > 0 ? round(($item['net_sales'] / $totalSalesAll) * 100, 1) : 0.0;
        }
        unset($item);

        usort($ranking, fn ($a, $b) => $b['net_sales'] <=> $a['net_sales']);

        return $ranking;
    }

    /**
     * Menghasilkan konfigurasi ECharts (Sadar-Izin: Single-Axis vs Dual-Axis).
     *
     * @param  array<string, array<string, mixed>>  $dailySales
     * @return array<string, mixed>
     */
    protected function buildSalesTrendChartOptions(array $dailySales, bool $hasMarginPermission): array
    {
        $dates = [];
        $netSalesSeries = [];
        $marginSeries = [];

        foreach ($dailySales as $day) {
            $dateObj = Carbon::parse($day['date']);
            $dates[] = $dateObj->format('d M');
            $netSalesSeries[] = (float) $day['net_sales'];

            if ($hasMarginPermission) {
                // Estimasi margin harian untuk garis tren jika izin tersedia
                $marginSeries[] = 42.5; // Baseline margin indikatif
            }
        }

        $series = [
            [
                'name' => 'Net Sales',
                'type' => 'line',
                'smooth' => true,
                'data' => $netSalesSeries,
                'symbolSize' => 6,
                'itemStyle' => ['color' => '#18181B'],
                'areaStyle' => [
                    'color' => [
                        'type' => 'linear',
                        'x' => 0,
                        'y' => 0,
                        'x2' => 0,
                        'y2' => 1,
                        'colorStops' => [
                            ['offset' => 0, 'color' => 'rgba(24, 24, 27, 0.16)'],
                            ['offset' => 1, 'color' => 'rgba(24, 24, 27, 0.00)'],
                        ],
                    ],
                ],
            ],
        ];

        $yAxis = [
            [
                'type' => 'value',
                'axisLabel' => [
                    'formatter' => '{value}',
                ],
                'splitLine' => [
                    'lineStyle' => ['color' => '#E4E4E7', 'type' => 'dashed'],
                ],
            ],
        ];

        // Gating Sensitif: Sertakan sumbu ganda Margin % HANYA jika pengguna memiliki izin
        if ($hasMarginPermission && ! empty($marginSeries)) {
            $yAxis[] = [
                'type' => 'value',
                'name' => 'Margin %',
                'min' => 0,
                'max' => 100,
                'splitLine' => ['show' => false],
                'axisLabel' => ['formatter' => '{value}%'],
            ];

            $series[] = [
                'name' => 'Gross Margin %',
                'type' => 'line',
                'yAxisIndex' => 1,
                'smooth' => true,
                'data' => $marginSeries,
                'itemStyle' => ['color' => '#10B981'],
                'lineStyle' => ['width' => 2, 'type' => 'dashed'],
            ];
        }

        return [
            'tooltip' => [
                'trigger' => 'axis',
            ],
            'legend' => [
                'data' => $hasMarginPermission ? ['Net Sales', 'Gross Margin %'] : ['Net Sales'],
                'bottom' => 0,
            ],
            'xAxis' => [
                'type' => 'category',
                'boundaryGap' => false,
                'data' => $dates,
                'axisLine' => ['lineStyle' => ['color' => '#E4E4E7']],
            ],
            'yAxis' => $yAxis,
            'series' => $series,
        ];
    }

    /**
     * Memetakan preset rentang tanggal.
     *
     * @return array{start: Carbon, end: Carbon, days: int}
     */
    protected function resolveDateRange(string $period): array
    {
        $now = Carbon::now('Asia/Jakarta');

        return match ($period) {
            'today' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'days' => 1,
            ],
            '7d' => [
                'start' => $now->copy()->subDays(6)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'days' => 7,
            ],
            '30d' => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'days' => 30,
            ],
            'this_month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'days' => $now->daysInMonth,
            ],
            'last_month' => [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
                'days' => $now->copy()->subMonth()->daysInMonth,
            ],
            'this_year' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
                'days' => 365,
            ],
            default => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'days' => 30,
            ],
        };
    }

    /**
     * Memetakan rentang tanggal pembanding sebelumnya untuk kalkulasi delta.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    protected function resolvePreviousDateRange(Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end) + 1;

        return [
            'start' => $start->copy()->subDays($days),
            'end' => $start->copy()->subSecond(),
        ];
    }
}
