<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services\Analytics;

use App\Enums\OrderStatus;
use App\Modules\Reports\Services\MetricRegistry;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsService
{
    protected MetricRegistry $metricRegistry;

    public function __construct(
        protected TenantContext $tenantContext,
        ?MetricRegistry $metricRegistry = null,
    ) {
        $this->metricRegistry = $metricRegistry ?? app(MetricRegistry::class);
    }

    /**
     * Mengambil ringkasan valuasi aset persediaan historis atau berjalan.
     *
     * @return array{
     *     total_valuation: string,
     *     total_items_tracked: int,
     *     product_valuation: string,
     *     ingredient_valuation: string,
     *     reconciliation_status: string,
     *     items: array<int, array{
     *         item_type: string,
     *         item_id: int,
     *         item_name: string,
     *         sku: string,
     *         closing_qty: string,
     *         average_unit_cost: string,
     *         total_valuation: string
     *     }>
     * }
     */
    public function getValuationSummary(Carbon $date, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        // 1. Cek apakah ada snapshot di inventory_valuation_snapshots
        $snapshotQuery = DB::table('inventory_valuation_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', '<=', $date->toDateString());

        if ($storeId !== null) {
            $snapshotQuery->where('store_id', $storeId);
        }

        $latestDate = (clone $snapshotQuery)->max('snapshot_date');

        if ($latestDate !== null) {
            $snapshots = (clone $snapshotQuery)
                ->where('snapshot_date', $latestDate)
                ->get();

            $totalVal = '0.00';
            $prodVal = '0.00';
            $ingVal = '0.00';
            $hasDiscrepancy = false;
            $items = [];

            foreach ($snapshots as $snap) {
                $val = bcadd($this->toNumericString($snap->total_valuation_amount), '0', 2);
                $totalVal = bcadd($totalVal, $val, 2);

                if ($snap->item_type === 'PRODUCT') {
                    $prodVal = bcadd($prodVal, $val, 2);
                } else {
                    $ingVal = bcadd($ingVal, $val, 2);
                }

                if ($snap->reconciliation_status === 'DISCREPANCY') {
                    $hasDiscrepancy = true;
                }

                $items[] = [
                    'item_type' => (string) $snap->item_type,
                    'item_id' => (int) $snap->item_id,
                    'item_name' => "Item #{$snap->item_id}",
                    'sku' => '-',
                    'closing_qty' => bcadd($this->toNumericString($snap->closing_qty), '0', 4),
                    'average_unit_cost' => bcadd($this->toNumericString($snap->average_unit_cost), '0', 4),
                    'total_valuation' => $val,
                ];
            }

            return [
                'total_valuation' => $totalVal,
                'total_items_tracked' => count($items),
                'product_valuation' => $prodVal,
                'ingredient_valuation' => $ingVal,
                'reconciliation_status' => $hasDiscrepancy ? 'DISCREPANCY' : 'MATCHED',
                'items' => $items,
            ];
        }

        // 2. Fallback realtime dari master products dan stock balances jika snapshot belum dibuat
        $prodQuery = DB::table('products as p')
            ->leftJoin('product_stock_balances as psb', function ($join) use ($storeId) {
                $join->on('p.id', '=', 'psb.product_id');
                if ($storeId !== null) {
                    $join->where('psb.store_id', '=', $storeId);
                }
            })
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->selectRaw('
                p.id,
                p.product_name,
                COALESCE(p.product_code, "-") as sku,
                COALESCE(psb.quantity, p.stock, 0) as current_qty,
                COALESCE(psb.average_unit_cost, p.standard_cost, p.product_price, 0) as unit_cost
            ')
            ->get();

        $totalVal = '0.00';
        $items = [];

        foreach ($prodQuery as $row) {
            $qty = bcadd($this->toNumericString($row->current_qty), '0', 4);
            $cost = bcadd($this->toNumericString($row->unit_cost), '0', 4);
            $val = bcmul($qty, $cost, 2);
            $totalVal = bcadd($totalVal, $val, 2);

            $items[] = [
                'item_type' => 'PRODUCT',
                'item_id' => (int) $row->id,
                'item_name' => (string) $row->product_name,
                'sku' => (string) $row->sku,
                'closing_qty' => $qty,
                'average_unit_cost' => $cost,
                'total_valuation' => $val,
            ];
        }

        return [
            'total_valuation' => $totalVal,
            'total_items_tracked' => count($items),
            'product_valuation' => $totalVal,
            'ingredient_valuation' => '0.00',
            'reconciliation_status' => 'MATCHED',
            'items' => $items,
        ];
    }

    /**
     * Menghitung rasio perputaran persediaan (Turnover Rate) & Days Sales of Inventory (DSI).
     *
     * @return array{
     *     period_days: int,
     *     cogs_amount: string,
     *     average_inventory_value: string,
     *     turnover_ratio: string,
     *     days_sales_of_inventory: string,
     *     velocity_category: string
     * }
     */
    public function getInventoryTurnoverRate(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $periodDays = max(1, (int) $start->diffInDays($end) + 1);

        // 1. Hitung COGS (Harga Pokok Penjualan) dalam rentang periode
        $cogsQuery = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'oi.product_id', '=', 'p.id')
            ->where('oi.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereBetween('o.order_date', [$start->toDateString(), $end->toDateString()])
            ->where('o.order_status', OrderStatus::Paid->value);

        if ($storeId !== null) {
            $cogsQuery->where('o.store_id', $storeId);
        }

        // COGS = sum(qty * COALESCE(standard_cost, product_price * 0.6))
        $rawCogs = $cogsQuery->selectRaw('
            COALESCE(SUM(oi.qty * COALESCE(p.standard_cost, p.product_price * 0.6)), 0) as total_cogs
        ')->value('total_cogs');

        $cogs = bcadd($this->toNumericString($rawCogs), '0', 2);

        // 2. Hitung Rata-rata Nilai Persediaan (Opening & Closing)
        $startVal = $this->getValuationSummary($start, $storeId)['total_valuation'];
        $endVal = $this->getValuationSummary($end, $storeId)['total_valuation'];

        $avgInv = $this->metricRegistry->calculateAverageInventoryValue($startVal, $endVal);

        // 3. Turnover Ratio = COGS / Avg Inventory & DSI = Period Days / Turnover Ratio
        $turnoverRatio = $this->metricRegistry->calculateInventoryTurnover($cogs, $avgInv, 4);
        $dsi = $this->metricRegistry->calculateDaysSalesOfInventory($turnoverRatio, $periodDays);

        $turnoverScaled = $this->metricRegistry->toNumericString($turnoverRatio, 4);
        $category = match (true) {
            bccomp($turnoverScaled, '6.0000', 4) >= 0 => 'FAST_MOVING',
            bccomp($turnoverScaled, '2.5000', 4) >= 0 => 'HEALTHY',
            default => 'SLOW_MOVING',
        };

        return [
            'period_days' => $periodDays,
            'cogs_amount' => $cogs,
            'average_inventory_value' => $avgInv,
            'turnover_ratio' => $this->metricRegistry->toNumericString($turnoverRatio, 2),
            'days_sales_of_inventory' => $dsi,
            'velocity_category' => $category,
        ];
    }

    /**
     * Analisis Umur Stok (Stock Aging):
     * Mengelompokkan barang ke dalam kelompok umur (0-30, 31-60, 61-90, >90 hari).
     *
     * @return array{
     *     total_stock_value: string,
     *     buckets: array<string, array{
     *         label: string,
     *         item_count: int,
     *         total_quantity: string,
     *         total_value: string,
     *         percentage: string
     *     }>,
     *     aging_items: array<int, array{
     *         product_id: int,
     *         product_name: string,
     *         sku: string,
     *         quantity: string,
     *         unit_cost: string,
     *         total_value: string,
     *         age_days: int,
     *         age_bucket: string
     *     }>
     * }
     */
    public function getStockAging(?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $now = Carbon::now();

        $query = DB::table('products as p')
            ->leftJoin('product_stock_balances as psb', function ($join) use ($storeId) {
                $join->on('p.id', '=', 'psb.product_id');
                if ($storeId !== null) {
                    $join->where('psb.store_id', '=', $storeId);
                }
            })
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->selectRaw('
                p.id,
                p.product_name,
                COALESCE(p.product_code, "-") as sku,
                COALESCE(psb.quantity, p.stock, 0) as current_qty,
                COALESCE(psb.average_unit_cost, p.standard_cost, p.product_price, 0) as unit_cost,
                p.created_at
            ')
            ->having('current_qty', '>', 0)
            ->get();

        $buckets = [
            '0_30' => ['label' => '0–30 Hari (Cepat)', 'item_count' => 0, 'qty' => '0.00', 'val' => '0.00'],
            '31_60' => ['label' => '31–60 Hari (Normal)', 'item_count' => 0, 'qty' => '0.00', 'val' => '0.00'],
            '61_90' => ['label' => '61–90 Hari (Lambat)', 'item_count' => 0, 'qty' => '0.00', 'val' => '0.00'],
            'over_90' => ['label' => '> 90 Hari (Kritis/Usang)', 'item_count' => 0, 'qty' => '0.00', 'val' => '0.00'],
        ];

        $totalVal = '0.00';
        $agingItems = [];

        foreach ($query as $row) {
            $created = Carbon::parse((string) $row->created_at);
            $ageDays = max(0, (int) $created->diffInDays($now));

            $bucketKey = match (true) {
                $ageDays <= 30 => '0_30',
                $ageDays <= 60 => '31_60',
                $ageDays <= 90 => '61_90',
                default => 'over_90',
            };

            $qty = bcadd($this->toNumericString($row->current_qty), '0', 4);
            $cost = bcadd($this->toNumericString($row->unit_cost), '0', 4);
            $val = bcmul($qty, $cost, 2);

            $totalVal = bcadd($totalVal, $val, 2);

            $buckets[$bucketKey]['item_count']++;
            $buckets[$bucketKey]['qty'] = bcadd($buckets[$bucketKey]['qty'], $qty, 4);
            $buckets[$bucketKey]['val'] = bcadd($buckets[$bucketKey]['val'], $val, 2);

            $agingItems[] = [
                'product_id' => (int) $row->id,
                'product_name' => (string) $row->product_name,
                'sku' => (string) $row->sku,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'total_value' => $val,
                'age_days' => $ageDays,
                'age_bucket' => $bucketKey,
            ];
        }

        $formattedBuckets = [];
        foreach ($buckets as $k => $b) {
            $pct = '0.00';
            if (bccomp($totalVal, '0.00', 2) > 0) {
                $pct = bcmul(bcdiv($b['val'], $totalVal, 4), '100', 2);
            }

            $formattedBuckets[$k] = [
                'label' => $b['label'],
                'item_count' => $b['item_count'],
                'total_quantity' => $b['qty'],
                'total_value' => $b['val'],
                'percentage' => $pct,
            ];
        }

        return [
            'total_stock_value' => $totalVal,
            'buckets' => $formattedBuckets,
            'aging_items' => $agingItems,
        ];
    }

    /**
     * Analisis Stok Mati (Dead Stock):
     * Produk dengan kuantitas > 0 namun tidak memiliki catatan penjualan dalam N hari terakhir.
     *
     * @return array{
     *     total_dead_stock_value: string,
     *     dead_stock_count: int,
     *     items: array<int, array{
     *         product_id: int,
     *         product_name: string,
     *         sku: string,
     *         quantity: string,
     *         unit_cost: string,
     *         tied_up_capital: string,
     *         days_without_sale: int,
     *         recommended_action: string
     *     }>
     * }
     */
    public function getDeadStock(int $daysThreshold = 60, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $cutoffDate = Carbon::now()->subDays($daysThreshold)->toDateString();

        // 1. Ambil seluruh produk yang terjual dalam N hari terakhir
        $activeSoldProductIds = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('oi.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->where('o.order_date', '>=', $cutoffDate)
            ->where('o.order_status', OrderStatus::Paid->value)
            ->pluck('oi.product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 2. Ambil produk dengan stok > 0 yang ID-nya TIDAK ada di activeSoldProductIds
        $query = DB::table('products as p')
            ->leftJoin('product_stock_balances as psb', function ($join) use ($storeId) {
                $join->on('p.id', '=', 'psb.product_id');
                if ($storeId !== null) {
                    $join->where('psb.store_id', '=', $storeId);
                }
            })
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at');

        if (! empty($activeSoldProductIds)) {
            $query->whereNotIn('p.id', $activeSoldProductIds);
        }

        $records = $query->selectRaw('
                p.id,
                p.product_name,
                COALESCE(p.product_code, "-") as sku,
                COALESCE(psb.quantity, p.stock, 0) as current_qty,
                COALESCE(psb.average_unit_cost, p.standard_cost, p.product_price, 0) as unit_cost,
                p.created_at
            ')
            ->having('current_qty', '>', 0)
            ->get();

        $totalTiedCapital = '0.00';
        $items = [];
        $now = Carbon::now();

        foreach ($records as $row) {
            $qty = bcadd($this->toNumericString($row->current_qty), '0', 4);
            $cost = bcadd($this->toNumericString($row->unit_cost), '0', 4);
            $tied = bcmul($qty, $cost, 2);
            $totalTiedCapital = bcadd($totalTiedCapital, $tied, 2);

            // Hitung hari terakhir sejak penjualan (atau sejak dibuat jika belum pernah laku)
            $lastSoldDate = DB::table('order_items as oi')
                ->join('orders as o', 'oi.order_id', '=', 'o.id')
                ->where('oi.tenant_id', $tenantId)
                ->where('oi.product_id', $row->id)
                ->where('o.order_status', OrderStatus::Paid->value)
                ->max('o.order_date');

            $lastDate = $lastSoldDate ? Carbon::parse($lastSoldDate) : Carbon::parse((string) $row->created_at);
            $daysWithoutSale = max($daysThreshold, (int) $lastDate->diffInDays($now));

            $items[] = [
                'product_id' => (int) $row->id,
                'product_name' => (string) $row->product_name,
                'sku' => (string) $row->sku,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tied_up_capital' => $tied,
                'days_without_sale' => $daysWithoutSale,
                'recommended_action' => $daysWithoutSale > 90 ? 'CLEARANCE_DISCOUNT_OR_RETURN' : 'BUNDLE_PROMOTION',
            ];
        }

        return [
            'total_dead_stock_value' => $totalTiedCapital,
            'dead_stock_count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Prediksi Kebutuhan Restock Terpadu (Products & Ingredients):
     * Menghitung estimasi hari habis stok dan rekomendasi kuantitas restock.
     *
     * @return array<int, array{
     *     item_type: string,
     *     item_id: int,
     *     item_name: string,
     *     sku: string,
     *     current_stock: string,
     *     avg_daily_sales: string,
     *     days_remaining: int|null,
     *     suggested_reorder_qty: string,
     *     trend: string,
     *     stockout_risk: string
     * }>
     */
    public function getRestockForecast(?int $storeId = null, string $itemType = 'ALL', int $horizonDays = 14): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $windowStart = Carbon::now()->subDays(14)->toDateString();
        $midpoint = Carbon::now()->subDays(7)->toDateString();

        $results = [];

        // 1. Analisis Products jika diminta
        if ($itemType === 'ALL' || $itemType === 'PRODUCT') {
            $products = DB::table('products as p')
                ->leftJoin('product_stock_balances as psb', function ($join) use ($storeId) {
                    $join->on('p.id', '=', 'psb.product_id');
                    if ($storeId !== null) {
                        $join->where('psb.store_id', '=', $storeId);
                    }
                })
                ->where('p.tenant_id', $tenantId)
                ->whereNull('p.deleted_at')
                ->selectRaw('
                    p.id,
                    p.product_name,
                    COALESCE(p.product_code, "-") as sku,
                    COALESCE(psb.quantity, p.stock, 0) as current_stock,
                    COALESCE(psb.reorder_point, 10) as reorder_level
                ')
                ->get();

            // Ambil konsumsi / penjualan 14 hari terakhir
            $salesRecent = DB::table('order_items as oi')
                ->join('orders as o', 'oi.order_id', '=', 'o.id')
                ->where('oi.tenant_id', $tenantId)
                ->where('o.tenant_id', $tenantId)
                ->where('o.order_date', '>=', $windowStart)
                ->where('o.order_status', OrderStatus::Paid->value)
                ->selectRaw('
                    oi.product_id,
                    SUM(CASE WHEN o.order_date >= ? THEN oi.qty ELSE 0 END) as recent_half,
                    SUM(CASE WHEN o.order_date < ? THEN oi.qty ELSE 0 END) as older_half,
                    SUM(oi.qty) as total_qty
                ', [$midpoint, $midpoint])
                ->groupBy('oi.product_id')
                ->get()
                ->keyBy('product_id');

            foreach ($products as $prod) {
                $stat = $salesRecent->get($prod->id);
                $totalSold = (float) ($stat->total_qty ?? 0);
                $recentHalf = (float) ($stat->recent_half ?? 0);
                $olderHalf = (float) ($stat->older_half ?? 0);

                $avgDaily = $totalSold > 0 ? $totalSold / 14 : 0.0;
                $currentStock = (float) $prod->current_stock;
                $daysRemaining = $avgDaily > 0 ? (int) floor($currentStock / $avgDaily) : null;

                $buffer = $currentStock - (float) $prod->reorder_level;
                $forwardNeed = $avgDaily * $horizonDays;
                $suggested = max(0.0, $forwardNeed - $buffer);

                $trend = $this->resolveTrend($recentHalf / 7, $olderHalf / 7);

                $risk = match (true) {
                    $daysRemaining !== null && $daysRemaining <= 3 => 'CRITICAL',
                    $daysRemaining !== null && $daysRemaining <= 7 => 'HIGH',
                    $daysRemaining !== null && $daysRemaining <= 14 => 'MEDIUM',
                    default => 'LOW',
                };

                $results[] = [
                    'item_type' => 'PRODUCT',
                    'item_id' => (int) $prod->id,
                    'item_name' => (string) $prod->product_name,
                    'sku' => (string) $prod->sku,
                    'current_stock' => bcadd($this->toNumericString($currentStock), '0', 2),
                    'avg_daily_sales' => bcadd($this->toNumericString($avgDaily), '0', 2),
                    'days_remaining' => $daysRemaining,
                    'suggested_reorder_qty' => bcadd($this->toNumericString($suggested), '0', 2),
                    'trend' => $trend,
                    'stockout_risk' => $risk,
                ];
            }
        }

        // Urutkan berdasarkan resiko kehabisan stok tercepat
        usort($results, function ($a, $b) {
            $daysA = $a['days_remaining'] ?? 9999;
            $daysB = $b['days_remaining'] ?? 9999;

            return $daysA <=> $daysB;
        });

        return $results;
    }

    private function resolveTrend(float $recentAvg, float $olderAvg): string
    {
        if ($olderAvg <= 0) {
            return $recentAvg > 0 ? 'RISING' : 'STABLE';
        }

        $delta = ($recentAvg - $olderAvg) / $olderAvg;

        return match (true) {
            $delta >= 0.10 => 'RISING',
            $delta <= -0.10 => 'FALLING',
            default => 'STABLE',
        };
    }

    /**
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
