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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * ManagerDashboardService
 *
 * Pusat Kendali Operasional Cabang & Shift Toko (Branch Operational Control Center).
 * Menerapkan:
 * 1. Isolasi cabang ketat (WHERE store_id = ?). Staf cabang dilarang melihat cabang lain.
 * 2. Pemantauan real-time status laci kasir & selisih kas (KPI-POS-01 & DEC-005).
 * 3. Analisis jam sibuk toko (Hourly sales traffic 07:00 - 23:00).
 * 4. Kartu Skor Kinerja Kasir (Cashier Performance Scorecard).
 * 5. Antrean Persetujuan Operasional (Operational Approval Queue: Void, Diskon, Selisih Kas).
 * 6. Alert stok kritis & bahan baku menipis.
 */
class ManagerDashboardService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected MetricRegistry $metricRegistry,
    ) {}

    /**
     * Mengumpulkan metrik operasional cabang untuk Store Manager / Supervisor.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(User $user, ?int $requestedStoreId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        // Resolusi cabang: Manajer terkunci pada cabangnya sendiri kecuali memiliki izin multi-cabang
        $canSwitch = $user->canSwitchStores() || $user->canViewConsolidatedStores();
        $storeId = $requestedStoreId;

        if (! $canSwitch || $storeId === null) {
            $storeId = (int) ($user->store_id ?? 1);
        }

        $now = Carbon::now('Asia/Jakarta');
        $todayDate = $now->toDateString();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        // 1. Kinerja Penjualan Hari Ini
        $todaySalesData = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('order_date', $todayDate)
            ->whereIn('order_status', ['paid', 'completed'])
            ->selectRaw('
                COUNT(id) as total_orders,
                COALESCE(SUM(subtotal_amount), 0) as gross_sales,
                COALESCE(SUM(discount_amount), 0) as discounts
            ')
            ->first();

        // Retur hari ini
        $driver = DB::connection()->getDriverName();
        $returnDateExpr = $driver === 'mysql'
            ? "DATE(CONVERT_TZ(COALESCE(completed_at, created_at), '+00:00', '+07:00'))"
            : "DATE(COALESCE(completed_at, created_at))";

        $todayReturns = (float) DB::table('order_returns')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->whereRaw("{$returnDateExpr} = ?", [$todayDate])
            ->sum('subtotal_returned');

        $gross = $this->metricRegistry->toNumericString($todaySalesData->gross_sales ?? '0', 2);
        $discounts = $this->metricRegistry->toNumericString($todaySalesData->discounts ?? '0', 2);
        $returns = $this->metricRegistry->toNumericString($todayReturns, 2);
        $netSales = $this->metricRegistry->calculateNetSales($gross, $discounts, $returns);
        $orderCount = (int) ($todaySalesData->total_orders ?? 0);
        $aov = $this->metricRegistry->calculateAverageOrderValue($netSales, $orderCount);

        // 2. Monitoring Shift & Laci Kasir Aktif
        $activeShifts = DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereNull('closed_at')
            ->get();

        $activeDrawerCash = '0.00';
        $shiftsSummary = [];
        foreach ($activeShifts as $shift) {
            $starting = $this->metricRegistry->toNumericString($shift->opening_balance ?? $shift->starting_cash ?? '0', 2);
            $expected = $this->metricRegistry->toNumericString($shift->expected_cash ?? '0', 2);
            $activeDrawerCash = bcadd($activeDrawerCash, $expected, 2);

            $cashierName = DB::table('users')->where('id', $shift->user_id)->value('name') ?? 'Kasir';
            $shiftsSummary[] = [
                'shift_id' => $shift->id,
                'cashier_name' => $cashierName,
                'opened_at' => Carbon::parse($shift->opened_at ?? $shift->created_at)->format('H:i'),
                'starting_cash' => $starting,
                'current_cash' => $expected,
                'cash_difference' => $shift->cash_difference ?? 0.00,
            ];
        }

        // 3. Hourly Sales Traffic (Jam Sibuk Toko 07:00 - 23:00)
        $hourlyData = $this->computeHourlyTraffic($tenantId, $storeId, $todayStart, $todayEnd);

        // 4. Cashier Performance Scorecard
        $cashierScorecard = $this->computeCashierScorecard($tenantId, $storeId, $todayDate);

        // 5. Operational Approval Queue (Antrean yang membutuhkan otorisasi)
        $approvalQueue = $this->computeApprovalQueue($tenantId, $storeId);

        // 6. Bahan Baku Kritis & Alert Stok Rendah
        $lowStockIngredients = $this->computeLowStockAlerts($tenantId, $storeId);

        // 7. Plan vs Actual Harian Cabang
        $targetPeriod = $now->format('Y-m');
        $storeTarget = DB::table('store_targets')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('target_period', $targetPeriod)
            ->first();

        $dailyTarget = '0.00';
        $attainmentPct = '0.00';
        if ($storeTarget && bccomp((string) $storeTarget->target_net_sales, '0.00', 2) > 0) {
            $monthlyTarget = (string) $storeTarget->target_net_sales;
            $dailyTarget = bcdiv($monthlyTarget, (string) $now->daysInMonth, 2);
            $attainmentPct = $this->metricRegistry->calculateTargetAttainment($netSales, $dailyTarget);
        }

        $storeObj = DB::table('stores')->where('id', $storeId)->first();

        return [
            'store_id' => $storeId,
            'store_name' => $storeObj->name ?? 'Cabang',
            'store' => [
                'id' => $storeId,
                'name' => $storeObj->name ?? 'Cabang',
            ],
            'todayStoreSales' => (float) $netSales,
            'todayTransactionCount' => $orderCount,
            'activeDrawerCash' => (float) $activeDrawerCash,
            'today_date_formatted' => $now->translatedFormat('l, d F Y'),
            'today_kpi' => [
                'net_sales' => $netSales,
                'net_sales_formatted' => 'Rp '.number_format((float) $netSales, 0, ',', '.'),
                'gross_sales' => $gross,
                'discounts' => $discounts,
                'returns' => $returns,
                'total_orders' => $orderCount,
                'aov' => $aov,
                'aov_formatted' => 'Rp '.number_format((float) $aov, 0, ',', '.'),
                'active_shifts_count' => count($activeShifts),
                'active_drawer_cash' => $activeDrawerCash,
                'active_drawer_cash_formatted' => 'Rp '.number_format((float) $activeDrawerCash, 0, ',', '.'),
                'daily_target' => $dailyTarget,
                'daily_target_formatted' => 'Rp '.number_format((float) $dailyTarget, 0, ',', '.'),
                'target_attainment_pct' => $attainmentPct,
                'target_attainment_formatted' => $attainmentPct.'%',
            ],
            'shifts' => $shiftsSummary,
            'hourly_traffic' => $hourlyData,
            'hourlyTraffic' => $hourlyData,
            'cashier_scorecard' => $cashierScorecard,
            'cashierScorecard' => $cashierScorecard,
            'approval_queue' => $approvalQueue,
            'approvalQueue' => $approvalQueue,
            'low_stock_alerts' => $lowStockIngredients,
        ];
    }

    /**
     * Kueri lalu lintas penjualan per jam.
     *
     * @return array<int, array{hour: string, label: string, orders: int, sales: float}>
     */
    protected function computeHourlyTraffic(int $tenantId, int $storeId, Carbon $todayStart, Carbon $todayEnd): array
    {
        $raw = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$todayStart->toDateTimeString(), $todayEnd->toDateTimeString()])
            ->whereIn('order_status', ['paid', 'completed'])
            ->selectRaw('HOUR(CONVERT_TZ(created_at, "+00:00", "+07:00")) as hr, COUNT(id) as total_tx, COALESCE(SUM(order_amount), 0) as total_sales')
            ->groupByRaw('HOUR(CONVERT_TZ(created_at, "+00:00", "+07:00"))')
            ->get();

        $rawSales = [];
        $rawTx = [];
        foreach ($raw as $r) {
            $rawSales[$r->hr] = (float) $r->total_sales;
            $rawTx[$r->hr] = (int) $r->total_tx;
        }

        $traffic = [];
        for ($h = 7; $h <= 23; $h++) {
            $formattedHour = sprintf('%02d:00', $h);
            $traffic[] = [
                'hour' => $formattedHour,
                'label' => $formattedHour,
                'orders' => (int) ($rawTx[$h] ?? 0),
                'count' => (int) ($rawTx[$h] ?? 0),
                'sales' => (float) ($rawSales[$h] ?? 0.0),
                'amount' => (float) ($rawSales[$h] ?? 0.0),
            ];
        }

        return $traffic;
    }

    /**
     * Cashier Performance Scorecard untuk shift hari ini.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeCashierScorecard(int $tenantId, int $storeId, string $todayDate): array
    {
        $cashiers = DB::table('orders as o')
            ->join('users as u', 'o.user_id', '=', 'u.id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.store_id', $storeId)
            ->where('o.order_date', $todayDate)
            ->whereIn('o.order_status', ['paid', 'completed'])
            ->selectRaw('
                u.id as cashier_id,
                u.name as cashier_name,
                COUNT(o.id) as tx_count,
                COALESCE(SUM(o.subtotal_amount - o.discount_amount), 0) as total_sales
            ')
            ->groupBy('u.id', 'u.name')
            ->orderBy('total_sales', 'desc')
            ->get();

        $scorecard = [];
        foreach ($cashiers as $c) {
            $sales = (float) $c->total_sales;
            $tx = (int) $c->tx_count;
            $aov = $tx > 0 ? round($sales / $tx, 0) : 0;

            $scorecard[] = [
                'cashier_id' => $c->cashier_id,
                'name' => $c->cashier_name,
                'transactions' => $tx,
                'transactions_count' => $tx,
                'sales' => $sales,
                'sales_total' => $sales,
                'avg_basket' => $aov,
                'sales_formatted' => 'Rp '.number_format($sales, 0, ',', '.'),
                'aov_formatted' => 'Rp '.number_format($aov, 0, ',', '.'),
            ];
        }

        return $scorecard;
    }

    /**
     * Antrean persetujuan operasional supervisor/manajer: Void, Diskon Manual, & Selisih Kas (DEC-005).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeApprovalQueue(int $tenantId, int $storeId): array
    {
        $queue = [];

        // 1. Pesanan yang dibatalkan / void tanpa PIN otorisasi terselesaikan
        $voidOrders = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereNull('voided_by')
            ->where(function ($q) {
                $q->where('order_status', 'void')
                    ->orWhere(function ($sub) {
                        $sub->where('order_status', 'paid')
                            ->whereNotNull('void_reason');
                    });
            })
            ->where(function ($q) {
                $q->whereNull('void_reason')
                    ->orWhere('void_reason', 'not like', 'Ditolak%');
            })
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        foreach ($voidOrders as $v) {
            $queue[] = [
                'id' => $v->id,
                'type' => 'VOID',
                'ticket_no' => $v->order_code ?? (string) $v->id,
                'cashier_name' => 'Kasir',
                'amount' => (float) ($v->order_amount ?? 0),
                'time' => Carbon::parse($v->created_at)->format('H:i'),
                'created_at' => Carbon::parse($v->created_at)->format('H:i'),
                'reason' => $v->void_reason ?? 'Cancellation request',
                'details' => $v->void_reason ?? 'Cancellation request',
            ];
        }

        // 2. Selisih Kas Shift Kasir yang belum diotorisasi supervisor (DEC-005)
        $varianceShifts = DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereNotNull('closed_at')
            ->where('cash_difference', '!=', 0)
            ->whereNull('variance_approved_by')
            ->where(function ($q) {
                $q->whereNull('variance_reason')
                    ->orWhere('variance_reason', 'not like', 'Ditolak%');
            })
            ->orderBy('closed_at', 'desc')
            ->take(5)
            ->get();

        foreach ($varianceShifts as $s) {
            $cashierName = DB::table('users')->where('id', $s->user_id)->value('name') ?? 'Kasir';
            $diff = (float) $s->cash_difference;
            $diffType = $diff < 0 ? 'Selisih Kurang' : 'Selisih Lebih';

            $queue[] = [
                'id' => $s->id,
                'type' => 'CASH_VARIANCE',
                'ticket_no' => "SHIFT-{$s->id}",
                'cashier_name' => $cashierName,
                'amount' => abs($diff),
                'time' => Carbon::parse($s->closed_at)->format('H:i'),
                'created_at' => Carbon::parse($s->closed_at)->format('H:i'),
                'reason' => $s->variance_reason ?? "{$diffType} closing shift",
                'details' => $s->variance_reason ?? "{$diffType} closing shift",
            ];
        }

        return $queue;
    }

    /**
     * Monitoring stok bahan baku dan produk di bawah level minimum.
     *
     * @return array<int, array{name: string, current: string, min: string, unit: string, is_critical: bool}>
     */
    protected function computeLowStockAlerts(int $tenantId, int $storeId): array
    {
        $alerts = [];

        // Bahan baku F&B di bawah minimum
        if (Schema::hasTable('ingredient_stock_balances') && Schema::hasTable('ingredients')) {
            $stockCol = Schema::hasColumn('ingredient_stock_balances', 'quantity') ? 'quantity' : 'current_stock';
            $reorderCol = Schema::hasColumn('ingredient_stock_balances', 'reorder_point') ? 'isb.reorder_point' : 'ing.reorder_level';

            $lowIngredients = DB::table('ingredient_stock_balances as isb')
                ->join('ingredients as ing', 'isb.ingredient_id', '=', 'ing.id')
                ->where('isb.tenant_id', $tenantId)
                ->where('isb.store_id', $storeId)
                ->whereRaw("isb.{$stockCol} <= {$reorderCol}")
                ->select('ing.name', "isb.{$stockCol} as current_stock", "{$reorderCol} as reorder_level", 'ing.unit')
                ->take(5)
                ->get();

            foreach ($lowIngredients as $ing) {
                $alerts[] = [
                    'name' => $ing->name,
                    'current' => (string) round((float) $ing->current_stock, 1),
                    'min' => (string) round((float) $ing->reorder_level, 1),
                    'unit' => $ing->unit ?? 'unit',
                    'is_critical' => (float) $ing->current_stock <= 0.0,
                ];
            }
        }

        return $alerts;
    }
}
