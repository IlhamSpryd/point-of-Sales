<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services\Analytics;

use App\Enums\OrderStatus;
use App\Modules\Reports\Services\MetricRegistry;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsService
{
    protected MetricRegistry $metricRegistry;

    public function __construct(
        protected TenantContext $tenantContext,
        ?MetricRegistry $metricRegistry = null,
    ) {
        $this->metricRegistry = $metricRegistry ?? app(MetricRegistry::class);
    }

    /**
     * Helper untuk konversi aman ke numeric-string bagi bcmath.
     *
     * @return numeric-string
     */
    protected function toNumericString(mixed $value): string
    {
        if (is_numeric($value)) {
            /** @var numeric-string */
            return (string) $value;
        }

        return '0.00';
    }

    /**
     * Ringkasan Nilai Seumur Hidup Pelanggan (Customer Lifetime Value - CLV):
     * Menghitung total belanja per pelanggan, frekuensi transaksi, AOV, dan top spending customers.
     *
     * @return array{
     *     total_tracked_customers: int,
     *     total_customer_revenue: string,
     *     total_customer_orders: int,
     *     average_clv: string,
     *     average_order_frequency: string,
     *     average_order_value: string,
     *     top_customers: array<int, array{
     *         customer_id: int,
     *         name: string,
     *         phone: string,
     *         total_spend: string,
     *         order_count: int,
     *         average_order_value: string,
     *         first_order_date: string,
     *         last_order_date: string
     *     }>
     * }
     */
    public function getCustomerLifetimeValueSummary(?int $storeId = null, int $topLimit = 10): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $query = DB::table('orders as o')
            ->join('customers as c', 'o.customer_id', '=', 'c.id')
            ->where('o.tenant_id', $tenantId)
            ->where('c.tenant_id', $tenantId)
            ->whereNotNull('o.customer_id')
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $records = $query->selectRaw('
            c.id as customer_id,
            c.name as customer_name,
            COALESCE(c.phone, "-") as customer_phone,
            COUNT(o.id) as order_count,
            SUM(o.order_amount) as total_spend,
            MIN(o.order_date) as first_order,
            MAX(o.order_date) as last_order
        ')
            ->groupBy('c.id', 'c.name', 'c.phone')
            ->orderByDesc('total_spend')
            ->get();

        $totalCustomers = count($records);
        $totalRev = '0.00';
        $totalOrders = 0;
        $topCustomers = [];

        foreach ($records as $index => $row) {
            $spend = bcadd($this->toNumericString($row->total_spend), '0', 2);
            $totalRev = bcadd($this->toNumericString($totalRev), $this->toNumericString($spend), 2);
            $orders = (int) $row->order_count;
            $totalOrders += $orders;

            if ($index < $topLimit) {
                $aov = $this->metricRegistry->calculateAverageOrderValue($spend, $orders);
                $topCustomers[] = [
                    'customer_id' => (int) $row->customer_id,
                    'name' => (string) $row->customer_name,
                    'phone' => (string) $row->customer_phone,
                    'total_spend' => $spend,
                    'order_count' => $orders,
                    'average_order_value' => $aov,
                    'first_order_date' => (string) $row->first_order,
                    'last_order_date' => (string) $row->last_order,
                ];
            }
        }

        $avgClv = $this->metricRegistry->calculateClv($totalRev, $totalCustomers);
        $avgFreq = $totalCustomers > 0 ? bcdiv((string) $totalOrders, (string) $totalCustomers, 2) : '0.00';
        $overallAov = $this->metricRegistry->calculateAverageOrderValue($totalRev, $totalOrders);

        return [
            'total_tracked_customers' => $totalCustomers,
            'total_customer_revenue' => $totalRev,
            'total_customer_orders' => $totalOrders,
            'average_clv' => $avgClv,
            'average_order_frequency' => $avgFreq,
            'average_order_value' => $overallAov,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Segmentasi Pelanggan RFM (Recency, Frequency, Monetary):
     * Mengelompokkan pelanggan ke dalam kuadran perilaku pembelian:
     * - CHAMPIONS: Baru beli (<= 30 hari), sering (>= 5 kali), belanja tinggi
     * - LOYAL_CUSTOMERS: Beli dalam 60 hari, frekuensi >= 3
     * - POTENTIAL_LOYALIST: Baru beli (<= 30 hari), frekuensi < 3
     * - AT_RISK: Tidak beli > 60 hari, dulunya sering (>= 3)
     * - HIBERNATING: Tidak beli 91–180 hari
     * - LOST: Tidak ada transaksi > 180 hari
     *
     * @return array{
     *     total_segmented_customers: int,
     *     segments: array<string, array{
     *         segment_name: string,
     *         customer_count: int,
     *         percentage: string,
     *         total_revenue: string
     *     }>
     * }
     */
    public function getRfmSegmentation(?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $now = Carbon::now();

        $query = DB::table('orders as o')
            ->join('customers as c', 'o.customer_id', '=', 'c.id')
            ->where('o.tenant_id', $tenantId)
            ->where('c.tenant_id', $tenantId)
            ->whereNotNull('o.customer_id')
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $records = $query->selectRaw('
            c.id as customer_id,
            COUNT(o.id) as frequency,
            SUM(o.order_amount) as monetary,
            MAX(o.order_date) as last_order_date
        ')
            ->groupBy('c.id')
            ->get();

        $segments = [
            'CHAMPIONS' => ['segment_name' => 'Juara (Champions)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
            'LOYAL_CUSTOMERS' => ['segment_name' => 'Pelanggan Setia (Loyal)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
            'POTENTIAL_LOYALIST' => ['segment_name' => 'Potensial Setia (Recent)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
            'AT_RISK' => ['segment_name' => 'Beresiko Hilang (At Risk)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
            'HIBERNATING' => ['segment_name' => 'Hibernasi (Dormant)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
            'LOST' => ['segment_name' => 'Pelanggan Lepas (Lost)', 'customer_count' => 0, 'percentage' => '0.00', 'total_revenue' => '0.00'],
        ];

        $totalCustomers = count($records);

        foreach ($records as $row) {
            $recencyDays = max(0, (int) Carbon::parse((string) $row->last_order_date)->diffInDays($now));
            $frequency = (int) $row->frequency;
            $monetary = bcadd($this->toNumericString($row->monetary), '0', 2);

            $segmentKey = match (true) {
                $recencyDays <= 30 && $frequency >= 5 => 'CHAMPIONS',
                $recencyDays <= 60 && $frequency >= 3 => 'LOYAL_CUSTOMERS',
                $recencyDays <= 30 && $frequency < 3 => 'POTENTIAL_LOYALIST',
                $recencyDays > 60 && $recencyDays <= 90 && $frequency >= 3 => 'AT_RISK',
                $recencyDays > 90 && $recencyDays <= 180 => 'HIBERNATING',
                default => $recencyDays > 180 ? 'LOST' : 'AT_RISK',
            };

            $segments[$segmentKey]['customer_count']++;
            $segments[$segmentKey]['total_revenue'] = bcadd($this->toNumericString($segments[$segmentKey]['total_revenue']), $this->toNumericString($monetary), 2);
        }

        if ($totalCustomers > 0) {
            foreach ($segments as $key => $data) {
                $count = (string) $data['customer_count'];
                $segments[$key]['percentage'] = bcmul(bcdiv($count, (string) $totalCustomers, 4), '100', 2);
            }
        }

        return [
            'total_segmented_customers' => $totalCustomers,
            'segments' => $segments,
        ];
    }

    /**
     * Ringkasan Pergerakan Poin Loyalitas (Loyalty Ledger Audit):
     * Kueri ketat READ-ONLY terhadap tabel rantai kriptografis `loyalty_ledger`.
     *
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     total_transactions: int,
     *     active_members: int,
     *     points_earned: string,
     *     points_redeemed: string,
     *     points_expired: string,
     *     net_points_change: string
     * }
     */
    public function getLoyaltyPointsMovementSummary(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $query = DB::table('loyalty_ledger as ll')
            ->where('ll.tenant_id', $tenantId)
            ->whereBetween('ll.created_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()]);

        if ($storeId !== null) {
            $query->where('ll.store_id', $storeId);
        }

        $records = $query->selectRaw('
            COUNT(ll.id) as total_tx,
            COUNT(DISTINCT ll.customer_id) as active_members,
            COALESCE(SUM(CASE WHEN ll.points > 0 THEN ll.points ELSE 0 END), 0) as points_earned,
            COALESCE(SUM(CASE WHEN ll.type = "redeem" THEN ABS(ll.points) ELSE 0 END), 0) as points_redeemed,
            COALESCE(SUM(CASE WHEN ll.type = "expire" THEN ABS(ll.points) ELSE 0 END), 0) as points_expired,
            COALESCE(SUM(ll.points), 0) as net_points_change
        ')->first();

        $txCount = (int) ($records->total_tx ?? 0);
        $membersCount = (int) ($records->active_members ?? 0);
        $earned = bcadd($this->toNumericString($records->points_earned ?? 0), '0', 2);
        $redeemed = bcadd($this->toNumericString($records->points_redeemed ?? 0), '0', 2);
        $expired = bcadd($this->toNumericString($records->points_expired ?? 0), '0', 2);
        $netChange = bcadd($this->toNumericString($records->net_points_change ?? 0), '0', 2);

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_transactions' => $txCount,
            'active_members' => $membersCount,
            'points_earned' => $earned,
            'points_redeemed' => $redeemed,
            'points_expired' => $expired,
            'net_points_change' => $netChange,
        ];
    }
}
