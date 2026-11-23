<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service untuk menangani query data drilldown interaktif (slide-over drawers)
 * pada Executive Dashboard Apeiron Systems.
 * Memenuhi SLA < 200ms dengan indexing tenant_id dan fail-closed multi-tenancy.
 */
class DashboardDrilldownQueryService
{
    public function __construct(
        protected DashboardOverviewQueryService $overviewQueryService
    ) {}

    /**
     * Detail analitik penjualan untuk satu tanggal spesifik.
     *
     * @return array<string, mixed>
     */
    public function getDateSalesDrilldown(int $tenantId, ?int $storeId, string $date): array
    {
        $dateCarbon = Carbon::parse($date, 'Asia/Jakarta')->startOfDay();
        $dateStr = $dateCarbon->toDateString();

        $query = DB::table('orders as o')
            ->where('o.tenant_id', $tenantId)
            ->whereDate('o.order_date', $dateStr)
            ->whereIn('o.order_status', ['paid', 'completed']);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $orders = (clone $query)
            ->leftJoin('stores as s', fn ($j) => $j->on('o.store_id', '=', 's.id')->on('o.tenant_id', '=', 's.tenant_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('o.customer_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id'))
            ->select(
                'o.id',
                'o.order_code',
                'o.created_at',
                'o.order_amount',
                'o.order_status',
                'o.payment_method',
                DB::raw("COALESCE(s.name, 'Main Outlet') as store_name"),
                DB::raw("COALESCE(c.name, 'Walk-in Customer') as customer_name")
            )
            ->orderByDesc('o.created_at')
            ->limit(15)
            ->get();

        $totalOrders = (clone $query)->count();
        $totalGross = (float) (clone $query)->sum('o.subtotal_amount');
        $totalNet = (float) (clone $query)->sum('o.order_amount');
        $totalDiscount = (float) (clone $query)->sum('o.discount_amount');
        $totalTax = (float) (clone $query)->sum('o.tax_amount');
        $aov = $totalOrders > 0 ? round($totalNet / $totalOrders, 2) : 0.0;

        // Distribusi 24 jam (WIB)
        $hourlyRaw = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereDate('order_date', $dateStr)
            ->whereIn('order_status', ['paid', 'completed'])
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->select(
                DB::raw('HOUR(CONVERT_TZ(created_at, "+00:00", "+07:00")) as hr'),
                DB::raw('COUNT(*) as tx_count'),
                DB::raw('COALESCE(SUM(order_amount), 0) as total_net')
            )
            ->groupBy('hr')
            ->pluck('total_net', 'hr')
            ->toArray();

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = [
                'hour' => sprintf('%02d:00', $h),
                'amount' => (float) ($hourlyRaw[$h] ?? 0.0),
            ];
        }

        // Top 5 Produk Terjual pada Hari Tersebut
        $topProducts = DB::table('order_items as oi')
            ->join('orders as o', fn ($j) => $j->on('oi.order_id', '=', 'o.id')->on('oi.tenant_id', '=', 'o.tenant_id'))
            ->leftJoin('products as pr', fn ($j) => $j->on('oi.product_id', '=', 'pr.id')->on('oi.tenant_id', '=', 'pr.tenant_id'))
            ->where('oi.tenant_id', $tenantId)
            ->whereDate('o.order_date', $dateStr)
            ->whereIn('o.order_status', ['paid', 'completed'])
            ->when($storeId !== null, fn ($q) => $q->where('o.store_id', $storeId))
            ->select(
                DB::raw("COALESCE(oi.product_name_snapshot, pr.product_name, 'Product') as product_name"),
                DB::raw('SUM(oi.qty) as total_qty'),
                DB::raw('COALESCE(SUM(oi.order_subtotal), 0) as total_sales')
            )
            ->groupBy(DB::raw("COALESCE(oi.product_name_snapshot, pr.product_name, 'Product')"))
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => (string) $r->product_name,
                'qty' => (int) $r->total_qty,
                'sales' => (float) $r->total_sales,
            ])
            ->toArray();

        return [
            'date' => $dateCarbon->translatedFormat('l, d F Y'),
            'date_raw' => $dateStr,
            'summary' => [
                'total_orders' => $totalOrders,
                'gross_sales' => $totalGross,
                'net_sales' => $totalNet,
                'discount' => $totalDiscount,
                'tax' => $totalTax,
                'aov' => $aov,
            ],
            'hourly' => $hourly,
            'top_products' => $topProducts,
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_code ?? ('#'.$o->id),
                'time' => Carbon::parse($o->created_at)->setTimezone('Asia/Jakarta')->format('H:i'),
                'customer' => $o->customer_name,
                'store' => $o->store_name,
                'payment_method' => strtoupper((string) ($o->payment_method ?? 'CASH')),
                'amount' => (float) $o->order_amount,
                'status' => $o->order_status,
            ])->toArray(),
        ];
    }

    /**
     * Detail pesanan untuk slot hari dan jam tertentu dari demand heatmap.
     *
     * @return array<string, mixed>
     */
    public function getHourlyOrdersDrilldown(int $tenantId, ?int $storeId, int $dayOfWeek, int $hour, string $period = '30d'): array
    {
        $range = $this->overviewQueryService->resolveDateRange($period);
        $daysNameMap = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $dayLabel = $daysNameMap[$dayOfWeek % 7] ?? 'Hari';
        $timeLabel = sprintf('%02d:00 - %02d:59 WIB', $hour, $hour);

        $mysqlDay = ($dayOfWeek % 7) + 1;

        $ordersQuery = DB::table('orders as o')
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->whereIn('o.order_status', ['paid', 'completed'])
            ->whereRaw('DAYOFWEEK(CONVERT_TZ(o.created_at, "+00:00", "+07:00")) = ?', [$mysqlDay])
            ->whereRaw('HOUR(CONVERT_TZ(o.created_at, "+00:00", "+07:00")) = ?', [$hour]);

        if ($storeId !== null) {
            $ordersQuery->where('o.store_id', $storeId);
        }

        $totalCount = (clone $ordersQuery)->count();
        $totalRevenue = (float) (clone $ordersQuery)->sum('o.order_amount');
        $avgTicket = $totalCount > 0 ? round($totalRevenue / $totalCount, 2) : 0.0;

        $orders = (clone $ordersQuery)
            ->leftJoin('stores as s', fn ($j) => $j->on('o.store_id', '=', 's.id')->on('o.tenant_id', '=', 's.tenant_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('o.customer_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id'))
            ->select(
                'o.id',
                'o.order_code',
                'o.order_date',
                'o.created_at',
                'o.order_amount',
                'o.payment_method',
                DB::raw("COALESCE(s.name, 'Outlet') as store_name"),
                DB::raw("COALESCE(c.name, 'Walk-in') as customer_name")
            )
            ->orderByDesc('o.created_at')
            ->limit(12)
            ->get();

        // Top items pada window waktu ini
        $popularItems = DB::table('order_items as oi')
            ->join('orders as o', fn ($j) => $j->on('oi.order_id', '=', 'o.id')->on('oi.tenant_id', '=', 'o.tenant_id'))
            ->leftJoin('products as pr', fn ($j) => $j->on('oi.product_id', '=', 'pr.id')->on('oi.tenant_id', '=', 'pr.tenant_id'))
            ->where('oi.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->whereIn('o.order_status', ['paid', 'completed'])
            ->whereRaw('DAYOFWEEK(CONVERT_TZ(o.created_at, "+00:00", "+07:00")) = ?', [$mysqlDay])
            ->whereRaw('HOUR(CONVERT_TZ(o.created_at, "+00:00", "+07:00")) = ?', [$hour])
            ->when($storeId !== null, fn ($q) => $q->where('o.store_id', $storeId))
            ->select(
                DB::raw("COALESCE(oi.product_name_snapshot, pr.product_name, 'Product') as product_name"),
                DB::raw('SUM(oi.qty) as qty'),
                DB::raw('COALESCE(SUM(oi.order_subtotal), 0) as total')
            )
            ->groupBy(DB::raw("COALESCE(oi.product_name_snapshot, pr.product_name, 'Product')"))
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        return [
            'slot_title' => "{$dayLabel}, {$timeLabel}",
            'day_name' => $dayLabel,
            'time_range' => $timeLabel,
            'summary' => [
                'transaction_count' => $totalCount,
                'total_revenue' => $totalRevenue,
                'avg_ticket' => $avgTicket,
                'velocity_rating' => $totalCount > 50 ? 'PEAK' : ($totalCount > 20 ? 'HIGH' : 'NORMAL'),
            ],
            'popular_items' => $popularItems->map(fn ($i) => [
                'name' => (string) $i->product_name,
                'qty' => (int) $i->qty,
                'total' => (float) $i->total,
            ])->toArray(),
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_code ?? ('#'.$o->id),
                'date' => Carbon::parse($o->order_date)->format('d M Y'),
                'time' => Carbon::parse($o->created_at)->setTimezone('Asia/Jakarta')->format('H:i'),
                'customer' => $o->customer_name,
                'store' => $o->store_name,
                'amount' => (float) $o->order_amount,
                'payment_method' => strtoupper((string) ($o->payment_method ?? 'CASH')),
            ])->toArray(),
        ];
    }

    /**
     * Detail kanal pembayaran (QRIS, Card, Cash, Transfer).
     *
     * @return array<string, mixed>
     */
    public function getPaymentMethodDrilldown(int $tenantId, ?int $storeId, string $method, string $period = '30d'): array
    {
        $range = $this->overviewQueryService->resolveDateRange($period);

        $methodNorm = strtolower(trim($method));
        $methodMatches = match ($methodNorm) {
            'qris' => ['qris', 'ewallet', 'gopay', 'ovo', 'shopeepay'],
            'card / edc', 'card', 'edc' => ['card', 'credit_card', 'debit_card', 'edc'],
            'cash' => ['cash', 'tunai'],
            default => ['bank_transfer', 'transfer', 'invoice', 'credit'],
        };

        $query = DB::table('payments as p')
            ->join('orders as o', fn ($j) => $j->on('p.order_id', '=', 'o.id')->on('p.tenant_id', '=', 'o.tenant_id'))
            ->where('p.tenant_id', $tenantId)
            ->whereIn('p.status', ['captured', 'success'])
            ->whereBetween('o.order_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->whereIn('p.payment_method', $methodMatches);

        if ($storeId !== null) {
            $query->where('o.store_id', $storeId);
        }

        $totalAmount = (float) (clone $query)->sum('p.amount');
        $txCount = (clone $query)->count();
        $avgTicket = $txCount > 0 ? round($totalAmount / $txCount, 2) : 0.0;

        // Breakdown per Store
        $storeBreakdown = (clone $query)
            ->leftJoin('stores as s', 'o.store_id', '=', 's.id')
            ->select('s.name as store_name', DB::raw('COUNT(*) as count'), DB::raw('SUM(p.amount) as amount'))
            ->groupBy('s.name')
            ->orderByDesc('amount')
            ->get();

        // 10 Transaksi Terakhir
        $recentTx = (clone $query)
            ->select('o.id', 'o.order_code', 'o.order_date', 'p.created_at', 'p.amount', 'p.payment_method', 'p.reference_number')
            ->orderByDesc('p.created_at')
            ->limit(10)
            ->get();

        return [
            'method_name' => $method,
            'summary' => [
                'total_volume' => $totalAmount,
                'tx_count' => $txCount,
                'avg_ticket' => $avgTicket,
                'fee_rate' => $methodNorm === 'qris' ? '0.7%' : ($methodNorm === 'card / edc' ? '1.5%' : '0.0%'),
            ],
            'stores' => $storeBreakdown->map(fn ($sb) => [
                'store_name' => (string) ($sb->store_name ?? 'Pusat'),
                'count' => (int) $sb->count,
                'amount' => (float) $sb->amount,
            ])->toArray(),
            'recent_transactions' => $recentTx->map(fn ($tx) => [
                'id' => $tx->id,
                'order_number' => $tx->order_code ?? ('#'.$tx->id),
                'date' => Carbon::parse($tx->order_date)->format('d M Y'),
                'time' => Carbon::parse($tx->created_at)->setTimezone('Asia/Jakarta')->format('H:i'),
                'amount' => (float) $tx->amount,
                'ref' => (string) ($tx->reference_number ?? '-'),
            ])->toArray(),
        ];
    }

    /**
     * Detail Waterfall Bridge Step.
     *
     * @return array<string, mixed>
     */
    public function getWaterfallStepDrilldown(int $tenantId, ?int $storeId, string $step, string $period = '30d'): array
    {
        $bridge = $this->overviewQueryService->getRevenueWaterfallBridge($tenantId, $storeId, $period);
        $stepData = collect($bridge['steps'] ?? [])->firstWhere('label', $step)
            ?? ['label' => $step, 'value' => 0.0, 'type' => 'neutral'];

        return [
            'step' => $step,
            'step_data' => $stepData,
            'period' => $period,
            'explanation' => match (strtolower($step)) {
                'gross sales' => 'Total nilai pesanan kotor sebelum potongan diskon, promosi, atau retur.',
                'discounts', 'promotions' => 'Akumulasi diskon item, voucher kampanye, dan potongan harga manual.',
                'returns', 'refunds' => 'Total pengembalian dana atau retur barang yang disetujui.',
                'cogs', 'hpp' => 'Harga Pokok Penjualan yang dialokasikan berdasarkan resep BOM dan HPP tercatat.',
                default => 'Nilai kontribusi bersih pada periode berjalan.',
            },
        ];
    }
}
