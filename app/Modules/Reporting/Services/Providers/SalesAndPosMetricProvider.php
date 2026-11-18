<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services\Providers;

use App\Core\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metric calculations for Sales, POS, Attainment, and Accrual Streams (STR-14, ADR-108).
 */
class SalesAndPosMetricProvider
{
    public function __construct(
        protected TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, array{code: string, key: string, name: string, formula: string, authoritative_source: string, unit: string, scale: int, gl_account_code: string|null}>
     */
    public function definitions(): array
    {
        return [
            'KPI-SAL-01' => [
                'code' => 'KPI-SAL-01',
                'key' => 'gross_sales',
                'name' => 'Gross Sales',
                'formula' => 'SUM(item_price * quantity)',
                'authoritative_source' => 'order_items / orders.subtotal_amount',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4-10000',
            ],
            'KPI-SAL-02' => [
                'code' => 'KPI-SAL-02',
                'key' => 'total_discounts',
                'name' => 'Total Discounts',
                'formula' => 'SUM(discount_amount)',
                'authoritative_source' => 'order_items, discounts, orders.discount_amount',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4201',
            ],
            'KPI-SAL-03' => [
                'code' => 'KPI-SAL-03',
                'key' => 'net_sales',
                'name' => 'Net Sales',
                'formula' => 'Gross Sales - Total Discounts - Sales Returns',
                'authoritative_source' => 'journal_lines (GL 4-10000 / REVENUE) & orders',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4-10000',
            ],
            'KPI-SAL-04' => [
                'code' => 'KPI-SAL-04',
                'key' => 'average_order_value',
                'name' => 'Average Order Value (AOV)',
                'formula' => 'Net Sales / Completed Transactions',
                'authoritative_source' => 'orders',
                'unit' => 'IDR/Order',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-SAL-05' => [
                'code' => 'KPI-SAL-05',
                'key' => 'units_per_transaction',
                'name' => 'Units per Transaction (UPT)',
                'formula' => 'Total Quantity Sold / Completed Transactions',
                'authoritative_source' => 'order_items, orders',
                'unit' => 'Units/Order',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-FNB-02' => [
                'code' => 'KPI-FNB-02',
                'key' => 'channel_order_mix',
                'name' => 'Order Type & Channel Mix (%)',
                'formula' => '(Channel Net Sales / Total Net Sales) * 100',
                'authoritative_source' => 'orders.order_type, orders.channel',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-FNB-03' => [
                'code' => 'KPI-FNB-03',
                'key' => 'table_occupancy_revpash',
                'name' => 'Table Occupancy & Spend Per Cover',
                'formula' => 'Dine-In Net Sales / Total Pax Count',
                'authoritative_source' => 'tables, orders.pax_count',
                'unit' => 'IDR/Pax',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-FNB-04' => [
                'code' => 'KPI-FNB-04',
                'key' => 'kds_sla_breach_rate',
                'name' => 'KDS SLA Breach Rate (%)',
                'formula' => '(Tickets > 15 Mins / Total Kitchen Tickets) * 100',
                'authoritative_source' => 'order_items.kds_status, order_items.kds_completed_at',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-PLN-01' => [
                'code' => 'KPI-PLN-01',
                'key' => 'target_attainment_pct',
                'name' => 'Target Attainment & Run-Rate Projection',
                'formula' => '(Actual Net Sales / Store Target Sales) * 100',
                'authoritative_source' => 'store_targets, orders',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
        ];
    }

    /**
     * @return numeric-string
     */
    public function toNumericString(mixed $value, int $scale = 2): string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            /** @var numeric-string $str */
            $str = (string) $value;

            return bcadd($str, '0', $scale);
        }

        /** @var numeric-string $zero */
        $zero = bcadd('0', '0', $scale);

        return $zero;
    }

    /**
     * KPI-SAL-01: Gross Sales = SUM(item_price * quantity).
     *
     * @param  array<int, array{price?: mixed, item_price?: mixed, quantity?: mixed, qty?: mixed}>  $items
     */
    public function calculateGrossSalesFromItems(array $items): string
    {
        $total = '0.00';

        foreach ($items as $item) {
            $price = $this->toNumericString($item['item_price'] ?? $item['price'] ?? '0.00', 4);
            $qty = $this->toNumericString($item['quantity'] ?? $item['qty'] ?? '0', 4);
            $lineTotal = bcmul($price, $qty, 2);
            $total = bcadd($total, $lineTotal, 2);
        }

        return $total;
    }

    /**
     * KPI-SAL-03: Net Sales = Gross Sales - Total Discounts - Sales Returns.
     */
    public function calculateNetSales(
        mixed $grossSales,
        mixed $totalDiscounts = '0.00',
        mixed $salesReturns = '0.00'
    ): string {
        $gross = $this->toNumericString($grossSales, 2);
        $discounts = $this->toNumericString($totalDiscounts, 2);
        $returns = $this->toNumericString($salesReturns, 2);

        $afterDiscount = bcsub($gross, $discounts, 2);

        return bcsub($afterDiscount, $returns, 2);
    }

    /**
     * KPI-SAL-04: Average Order Value (AOV) = Net Sales / Jumlah Transaksi Berhasil.
     */
    public function calculateAverageOrderValue(mixed $netSales, int $orderCount): string
    {
        if ($orderCount <= 0) {
            return '0.00';
        }

        $net = $this->toNumericString($netSales, 2);

        return bcdiv($net, (string) $orderCount, 2);
    }

    /**
     * KPI-SAL-05: Units per Transaction (UPT) = Total Kuantitas Item Terjual / Jumlah Transaksi Berhasil.
     */
    public function calculateUnitsPerTransaction(mixed $totalUnits, int $orderCount): string
    {
        if ($orderCount <= 0) {
            return '0.00';
        }

        $units = $this->toNumericString($totalUnits, 4);

        return bcdiv($units, (string) $orderCount, 2);
    }

    /**
     * KPI-FNB-03: Average Spend Per Cover (Pax) = Net Dine-In Sales / Total Pax.
     */
    public function calculateSpendPerCover(mixed $netSales, int $coverCount): string
    {
        if ($coverCount <= 0) {
            return '0.00';
        }

        $net = $this->toNumericString($netSales, 2);

        return bcdiv($net, (string) $coverCount, 2);
    }

    /**
     * KPI-FNB-04: KDS SLA Breach Rate % = (Tiket Melebihi SLA / Total Tiket) * 100.
     */
    public function calculateSlaBreachRate(int $breachedTickets, int $totalTickets): string
    {
        if ($totalTickets <= 0) {
            return '0.00';
        }

        return $this->calculateContributionPct($breachedTickets, $totalTickets);
    }

    /**
     * KPI-PLN-01: Target Attainment % = (Actual Net Sales / Store Target Sales) * 100.
     */
    public function calculateTargetAttainment(mixed $actualNetSales, mixed $targetNetSales): string
    {
        return $this->calculateContributionPct($actualNetSales, $targetNetSales);
    }

    /**
     * KPI-PLN-01: Run-Rate Projection = (Actual to date / Elapsed Days) * Total Days in Month.
     */
    public function calculateRunRateProjection(mixed $actualNetSalesToDate, int $elapsedDays, int $totalDaysInMonth): string
    {
        if ($elapsedDays <= 0 || $totalDaysInMonth <= 0) {
            return '0.00';
        }

        $act = $this->toNumericString($actualNetSalesToDate, 2);
        $dailyPace = bcdiv($act, (string) $elapsedDays, 4);

        return bcmul($dailyPace, (string) $totalDaysInMonth, 2);
    }

    public function calculateContributionPct(mixed $partValue, mixed $totalValue): string
    {
        $part = $this->toNumericString($partValue, 4);
        $total = $this->toNumericString($totalValue, 4);

        if (bccomp($total, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcmul(bcdiv($part, $total, 4), '100', 2);
    }

    public function calculateGrowthPct(mixed $currentValue, mixed $previousValue): string
    {
        $cur = $this->toNumericString($currentValue, 2);
        $prev = $this->toNumericString($previousValue, 2);

        if (bccomp($prev, '0.00', 2) === 0) {
            return bccomp($cur, '0.00', 2) > 0 ? '100.00' : '0.00';
        }

        $diff = bcsub($cur, $prev, 2);

        return bcmul(bcdiv($diff, $prev, 4), '100', 2);
    }

    /**
     * Agregasi Penjualan Bersih Harian Akrual Standar PSAK / SAP S/4HANA (UNION ALL Terindeks).
     *
     * @return array<string, array{
     *     date: string,
     *     gross_sales: string,
     *     discounts: string,
     *     returns: string,
     *     net_sales: string,
     *     order_count: int
     * }>
     */
    public function computeAccrualDailySales(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?int $companyId = null,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $resolvedTenantId = $tenantId ?? $this->tenantContext->requireTenantId();

        $localStart = $start->copy()->setTimezone($timezone)->startOfDay();
        $localEnd = $end->copy()->setTimezone($timezone)->endOfDay();
        $startUtc = $localStart->copy()->utc()->toDateTimeString();
        $endUtc = $localEnd->copy()->utc()->toDateTimeString();

        $startDateStr = $localStart->toDateString();
        $endDateStr = $localEnd->toDateString();

        $driver = DB::connection()->getDriverName();
        $returnDateExpr = $driver === 'mysql'
            ? "DATE(CONVERT_TZ(COALESCE(completed_at, created_at), '+00:00', '+07:00'))"
            : 'DATE(COALESCE(completed_at, created_at))';

        $ordersQuery = DB::table('orders')
            ->selectRaw('
                order_date as tx_date,
                COUNT(id) as order_count,
                COALESCE(SUM(subtotal_amount), 0) as gross_sales,
                COALESCE(SUM(discount_amount), 0) as discounts,
                0.00 as returns
            ')
            ->where('tenant_id', $resolvedTenantId)
            ->whereBetween('order_date', [$startDateStr, $endDateStr])
            ->whereIn('order_status', ['paid', 'completed']);

        if ($companyId !== null && Schema::hasColumn('orders', 'company_id')) {
            $ordersQuery->where('company_id', $companyId);
        }
        if ($storeId !== null) {
            $ordersQuery->where('store_id', $storeId);
        }
        $ordersQuery->groupBy('order_date');

        $returnsQuery = DB::table('order_returns')
            ->selectRaw("
                {$returnDateExpr} as tx_date,
                0 as order_count,
                0.00 as gross_sales,
                0.00 as discounts,
                COALESCE(SUM(subtotal_returned), 0) as returns
            ")
            ->where('tenant_id', $resolvedTenantId)
            ->where('status', 'completed')
            ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$startUtc, $endUtc]);

        if ($storeId !== null) {
            $returnsQuery->where('store_id', $storeId);
        }
        $returnsQuery->groupByRaw($returnDateExpr);

        $unionSql = "({$ordersQuery->toSql()}) UNION ALL ({$returnsQuery->toSql()})";
        $bindings = array_merge($ordersQuery->getBindings(), $returnsQuery->getBindings());

        $rows = DB::table(DB::raw("({$unionSql}) as stream"))
            ->setBindings($bindings)
            ->selectRaw('
                tx_date,
                SUM(order_count) as total_orders,
                SUM(gross_sales) as total_gross,
                SUM(discounts) as total_discounts,
                SUM(returns) as total_returns
            ')
            ->groupBy('tx_date')
            ->orderBy('tx_date', 'asc')
            ->get();

        $daily = [];
        foreach ($rows as $row) {
            $date = (string) $row->tx_date;
            $gross = $this->toNumericString($row->total_gross, 2);
            $discounts = $this->toNumericString($row->total_discounts, 2);
            $returns = $this->toNumericString($row->total_returns, 2);
            $net = $this->calculateNetSales($gross, $discounts, $returns);

            $daily[$date] = [
                'date' => $date,
                'gross_sales' => $gross,
                'discounts' => $discounts,
                'returns' => $returns,
                'net_sales' => $net,
                'order_count' => (int) $row->total_orders,
            ];
        }

        return $daily;
    }
}
