<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AiBusinessAssistantService
{
    /**
     * Menganalisis tren penjualan dan mendeteksi anomali pertumbuhan (Read-Only).
     *
     * @return array{
     *     period_days: int,
     *     current_period_revenue: string,
     *     previous_period_revenue: string,
     *     growth_rate_percent: string,
     *     trend_direction: 'RISING'|'FALLING'|'STABLE',
     *     total_orders_count: int,
     *     average_order_value: string,
     *     anomalies: list<array{type: string, severity: 'HIGH'|'MEDIUM'|'LOW', description: string}>
     * }
     */
    public function analyzeSalesTrends(int $tenantId, ?int $storeId = null, int $days = 14): array
    {
        $window = max(3, intdiv($days, 2));
        $now = Carbon::now();
        $currentStart = $now->copy()->subDays($window)->startOfDay();
        $previousStart = $now->copy()->subDays($window * 2)->startOfDay();

        // 1. Current Window Sales
        $currentQuery = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('order_date', '>=', $currentStart->toDateString())
            ->whereNotIn('order_status', ['void', 'cancelled']);

        if ($storeId !== null) {
            $currentQuery->where('store_id', $storeId);
        }

        $currentRevenue = (string) $currentQuery->sum('order_amount');
        $currentOrders = (int) $currentQuery->count();

        // 2. Previous Window Sales
        $previousQuery = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('order_date', '>=', $previousStart->toDateString())
            ->where('order_date', '<', $currentStart->toDateString())
            ->whereNotIn('order_status', ['void', 'cancelled']);

        if ($storeId !== null) {
            $previousQuery->where('store_id', $storeId);
        }

        $previousRevenue = (string) $previousQuery->sum('order_amount');

        // 3. Growth Rate Calculation
        $currentRevNum = bcadd($currentRevenue, '0', 2);
        $prevRevNum = bcadd($previousRevenue, '0', 2);

        if (bccomp($prevRevNum, '0.00', 2) > 0) {
            $diff = bcsub($currentRevNum, $prevRevNum, 2);
            $growth = bcmul(bcdiv($diff, $prevRevNum, 4), '100', 2);
        } else {
            $growth = bccomp($currentRevNum, '0.00', 2) > 0 ? '100.00' : '0.00';
        }

        $growthFloat = (float) $growth;
        if ($growthFloat > 5.0) {
            $trend = 'RISING';
        } elseif ($growthFloat < -5.0) {
            $trend = 'FALLING';
        } else {
            $trend = 'STABLE';
        }

        $aov = $currentOrders > 0 ? bcdiv($currentRevNum, (string) $currentOrders, 2) : '0.00';

        // 4. Anomaly Detection
        $anomalies = [];
        if ($growthFloat < -20.0 && bccomp($prevRevNum, '0.00', 2) > 0) {
            $anomalies[] = [
                'type' => 'CRITICAL_SALES_DROP',
                'severity' => 'HIGH',
                'description' => "Penjualan turun {$growth}% dibandingkan periode sebelumnya (dari Rp {$prevRevNum} menjadi Rp {$currentRevNum}). Periksa stok item populer atau traffic pelanggan.",
            ];
        } elseif ($growthFloat > 50.0 && bccomp($prevRevNum, '0.00', 2) > 0) {
            $anomalies[] = [
                'type' => 'UNUSUAL_DEMAND_SURGE',
                'severity' => 'MEDIUM',
                'description' => "Lonjakan pesanan tidak biasa sebesar +{$growth}%. Pastikan ketersediaan pasokan bahan dan staf operasional mencukupi.",
            ];
        }

        return [
            'period_days' => $days,
            'current_period_revenue' => $currentRevNum,
            'previous_period_revenue' => $prevRevNum,
            'growth_rate_percent' => $growth,
            'trend_direction' => $trend,
            'total_orders_count' => $currentOrders,
            'average_order_value' => $aov,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Mendeteksi anomali persediaan (Low Stock, Dead Stock, Shrinkage Divergence) secara read-only.
     *
     * @return array{
     *     critical_low_stock_count: int,
     *     dead_stock_count: int,
     *     critical_low_items: list<array{product_id: int, product_code: string, product_name: string, stock_balance: int}>,
     *     dead_stock_items: list<array{product_id: int, product_code: string, product_name: string, stock_balance: int}>
     * }
     */
    public function detectInventoryAnomalies(int $tenantId, ?int $storeId = null): array
    {
        // 1. Critical Low Stock (quantity <= 5 dan track_inventory = true)
        $lowStockQuery = DB::table('product_stock_balances')
            ->join('products', 'products.id', '=', 'product_stock_balances.product_id')
            ->where('product_stock_balances.tenant_id', $tenantId)
            ->where('products.track_inventory', true)
            ->where('products.is_active', true)
            ->where('product_stock_balances.quantity', '<=', 5);

        if ($storeId !== null) {
            $lowStockQuery->where('product_stock_balances.store_id', $storeId);
        }

        $lowStockRows = $lowStockQuery->select([
            'products.id as product_id',
            'products.product_code',
            'products.product_name',
            'product_stock_balances.quantity',
        ])->limit(10)->get();

        $criticalLowItems = [];
        foreach ($lowStockRows as $row) {
            $criticalLowItems[] = [
                'product_id' => (int) $row->product_id,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'stock_balance' => (int) $row->quantity,
            ];
        }

        // 2. Dead Stock (quantity >= 20 tetapi tidak ada penjualan dalam 30 hari terakhir)
        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();

        $soldProductIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.order_date', '>=', $thirtyDaysAgo)
            ->whereNotIn('orders.order_status', ['void', 'cancelled'])
            ->pluck('order_items.product_id')
            ->unique()
            ->toArray();

        $deadStockQuery = DB::table('product_stock_balances')
            ->join('products', 'products.id', '=', 'product_stock_balances.product_id')
            ->where('product_stock_balances.tenant_id', $tenantId)
            ->where('products.track_inventory', true)
            ->where('products.is_active', true)
            ->where('product_stock_balances.quantity', '>=', 20);

        if (! empty($soldProductIds)) {
            $deadStockQuery->whereNotIn('product_stock_balances.product_id', $soldProductIds);
        }

        if ($storeId !== null) {
            $deadStockQuery->where('product_stock_balances.store_id', $storeId);
        }

        $deadStockRows = $deadStockQuery->select([
            'products.id as product_id',
            'products.product_code',
            'products.product_name',
            'product_stock_balances.quantity',
        ])->limit(10)->get();

        $deadStockItems = [];
        foreach ($deadStockRows as $row) {
            $deadStockItems[] = [
                'product_id' => (int) $row->product_id,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'stock_balance' => (int) $row->quantity,
            ];
        }

        return [
            'critical_low_stock_count' => count($criticalLowItems),
            'dead_stock_count' => count($deadStockItems),
            'critical_low_items' => $criticalLowItems,
            'dead_stock_items' => $deadStockItems,
        ];
    }

    /**
     * Menghasilkan Draf Rekomendasi Terkendali (Strict Human-in-the-Loop Principle).
     * AI dilarang melakukan eksekusi langsung ke basis data; seluruh saran berupa draf yang membutuhkan persetujuan manusia.
     *
     * @return list<array{
     *     id: string,
     *     type: string,
     *     title: string,
     *     description: string,
     *     expected_impact: string,
     *     action_payload: array<string, mixed>,
     *     requires_human_approval: bool,
     *     status: 'draft_recommendation'
     * }>
     */
    public function generateActionableRecommendations(int $tenantId, ?int $storeId = null): array
    {
        $recommendations = [];

        // 1. Rekomendasi Pemesanan Ulang untuk Stok Menipis
        $inventory = $this->detectInventoryAnomalies($tenantId, $storeId);
        foreach ($inventory['critical_low_items'] as $item) {
            $recommendations[] = [
                'id' => 'REC-RESTOCK-' . $item['product_id'],
                'type' => 'INVENTORY_REORDER',
                'title' => "Pesan Ulang Stok: {$item['product_name']}",
                'description' => "Stok tersisa {$item['stock_balance']} unit. Disarankan membuat Purchase Order sebesar 50 unit untuk mencegah stockout.",
                'expected_impact' => 'Menjaga kontinuitas penjualan dan menghindari kehilangan potensi pendapatan.',
                'action_payload' => [
                    'suggested_action' => 'create_purchase_order',
                    'product_id' => $item['product_id'],
                    'product_code' => $item['product_code'],
                    'suggested_quantity' => 50,
                ],
                'requires_human_approval' => true,
                'status' => 'draft_recommendation',
            ];
        }

        // 2. Rekomendasi Bundling / Diskon untuk Dead Stock
        foreach ($inventory['dead_stock_items'] as $item) {
            $recommendations[] = [
                'id' => 'REC-MARKDOWN-' . $item['product_id'],
                'type' => 'DEAD_STOCK_CLEARANCE',
                'title' => "Program Cuci Gudang / Bundel: {$item['product_name']}",
                'description' => "Barang mengendap ({$item['stock_balance']} unit) tanpa transaksi selama 30 hari. Disarankan membuat paket promosi bundling atau diskon 15%.",
                'expected_impact' => 'Meningkatkan perputaran kas dan membebaskan kapasitas ruang penyimpanan.',
                'action_payload' => [
                    'suggested_action' => 'create_promotion_campaign',
                    'product_id' => $item['product_id'],
                    'product_code' => $item['product_code'],
                    'suggested_discount_percent' => '15.00',
                ],
                'requires_human_approval' => true,
                'status' => 'draft_recommendation',
            ];
        }

        // 3. Rekomendasi Pertumbuhan Penjualan jika tren turun
        $sales = $this->analyzeSalesTrends($tenantId, $storeId);
        if ($sales['trend_direction'] === 'FALLING') {
            $recommendations[] = [
                'id' => 'REC-SALES-PROMO',
                'type' => 'SALES_REVIVAL_CAMPAIGN',
                'title' => 'Aktivasi Kampanye Voucher Loyalitas Pelanggan',
                'description' => "Tren penjualan turun {$sales['growth_rate_percent']}%. Disarankan menyiarkan voucher cashback 10% ke member loyal melalui WhatsApp blast.",
                'expected_impact' => 'Menstimulasi repeat order dan memulihkan volume penjualan mingguan.',
                'action_payload' => [
                    'suggested_action' => 'broadcast_loyalty_voucher',
                    'target_segment' => 'ACTIVE_MEMBERS',
                    'voucher_discount_percent' => '10.00',
                ],
                'requires_human_approval' => true,
                'status' => 'draft_recommendation',
            ];
        }

        return $recommendations;
    }

    /**
     * Memproses pertanyaan analitika bahasa alami manajerial secara terarah dan terstruktur.
     *
     * @return array{
     *     query: string,
     *     intent: string,
     *     summary: string,
     *     insights: array<string, mixed>,
     *     draft_recommendations: list<array<string, mixed>>,
     *     compliance_note: string
     * }
     */
    public function askAssistant(int $tenantId, string $query, ?int $storeId = null): array
    {
        $q = strtolower(trim($query));

        if (str_contains($q, 'stok') || str_contains($q, 'stock') || str_contains($q, 'anomali') || str_contains($q, 'habis') || str_contains($q, 'dead')) {
            $intent = 'INVENTORY_HEALTH';
            $data = $this->detectInventoryAnomalies($tenantId, $storeId);
            $summary = "Ditemukan {$data['critical_low_stock_count']} item dengan stok kritis dan {$data['dead_stock_count']} item dead stock mengendap.";
        } elseif (str_contains($q, 'saran') || str_contains($q, 'rekomendasi') || str_contains($q, 'action') || str_contains($q, 'reorder')) {
            $intent = 'ACTIONABLE_RECOMMENDATIONS';
            $data = ['recommendation_count' => count($this->generateActionableRecommendations($tenantId, $storeId))];
            $summary = "Asisten AI menyusun draf rekomendasi operasional yang siap ditinjau oleh manajer.";
        } else {
            $intent = 'SALES_PERFORMANCE';
            $data = $this->analyzeSalesTrends($tenantId, $storeId);
            $summary = "Tren penjualan saat ini berstatus {$data['trend_direction']} dengan pertumbuhan {$data['growth_rate_percent']}% dan rata-rata order (AOV) sebesar Rp {$data['average_order_value']}.";
        }

        $recs = $this->generateActionableRecommendations($tenantId, $storeId);

        return [
            'query' => $query,
            'intent' => $intent,
            'summary' => $summary,
            'insights' => $data,
            'draft_recommendations' => $recs,
            'compliance_note' => 'AI Assistant beroperasi dalam mode Read-Only. Seluruh draf rekomendasi bersifat saran manajerial dan wajib disetujui secara sadar oleh personel berwenang sebelum eksekusi (Human-in-the-Loop).',
        ];
    }
}
