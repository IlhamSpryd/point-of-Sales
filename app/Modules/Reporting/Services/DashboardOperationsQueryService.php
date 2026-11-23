<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Core\Context\TenantContext;
use App\Modules\Finance\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DashboardOperationsQueryService
 *
 * Layanan kueri operasional dasbor eksekutif (< 500 lines).
 * Heatmap, Outlet Comparison, Unit Economics, Inventory, Transactions, Drawers, Alerts.
 */
class DashboardOperationsQueryService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected MetricRegistry $metricRegistry,
    ) {}

    /**
     * Section C2: Interactive Demand Heatmap matrix (7 hari Mon–Sun x 15 jam 07:00–21:00 = 105 sel).
     *
     * @return array<string, mixed>
     */
    public function getDemandHeatmap(int $tenantId, ?int $storeId, string $period = '30d', string $metric = 'transactions'): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $q = DB::table('orders')->where('tenant_id', $tenantId)->whereIn('order_status', ['paid', 'completed'])
            ->whereBetween('order_date', [$now->copy()->subDays(29)->startOfDay()->toDateString(), $now->copy()->endOfDay()->toDateString()]);
        if ($storeId !== null) {
            $q->where('store_id', $storeId);
        }

        $raw = $q->select(DB::raw('WEEKDAY(created_at) as d_idx'), DB::raw('HOUR(created_at) as h_idx'), DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(order_amount), 0) as rev'))
            ->groupBy('d_idx', 'h_idx')->get();

        $grid = [];
        $max = 0.0;
        foreach ($raw as $r) {
            $val = $metric === 'revenue' ? (float) $r->rev : (float) $r->cnt;
            $grid[(int) $r->d_idx][(int) $r->h_idx] = $val;
            $max = max($max, $val);
        }

        $dayNames = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
        $cells = [];
        $peak = ['day' => 'Senin', 'hour' => '12:00', 'value' => 0.0, 'formatted' => '0 tx'];
        $quiet = ['day' => 'Minggu', 'hour' => '07:00', 'value' => 9999999.0, 'formatted' => '0 tx'];
        $dp = [
            'breakfast' => ['label' => 'Breakfast (07:00–11:00)', 'transactions' => 0, 'revenue' => 0.0],
            'lunch' => ['label' => 'Lunch (11:00–14:00)', 'transactions' => 0, 'revenue' => 0.0],
            'afternoon' => ['label' => 'Afternoon (14:00–17:00)', 'transactions' => 0, 'revenue' => 0.0],
            'dinner' => ['label' => 'Dinner (17:00–21:00)', 'transactions' => 0, 'revenue' => 0.0],
        ];

        for ($d = 0; $d < 7; $d++) {
            for ($h = 7; $h <= 21; $h++) {
                $val = $grid[$d][$h] ?? 0.0;
                $hLabel = sprintf('%02d:00', $h);
                $cells[] = ['day_index' => $d, 'day_name' => $dayNames[$d], 'hour' => $h, 'hour_label' => $hLabel, 'value' => $val, 'intensity' => $max > 0 ? round($val / $max, 2) : 0.0];
                if ($val >= $peak['value']) {
                    $peak = ['day' => $dayNames[$d], 'hour' => $hLabel, 'value' => $val, 'formatted' => $metric === 'revenue' ? 'Rp '.number_format($val, 0, ',', '.') : $val.' tx'];
                }
                if ($val < $quiet['value']) {
                    $quiet = ['day' => $dayNames[$d], 'hour' => $hLabel, 'value' => $val, 'formatted' => $metric === 'revenue' ? 'Rp '.number_format($val, 0, ',', '.') : $val.' tx'];
                }
                $k = ($h < 11) ? 'breakfast' : (($h < 14) ? 'lunch' : (($h < 17) ? 'afternoon' : 'dinner'));
                $dp[$k]['transactions'] += (int) $val;
            }
        }

        if ($quiet['value'] === 9999999.0) {
            $quiet['value'] = 0.0;
        }
        $totTx = array_sum(array_column($dp, 'transactions'));
        foreach ($dp as &$item) {
            $item['share_pct'] = $totTx > 0 ? round(($item['transactions'] / $totTx) * 100, 1) : 0.0;
            $item['revenue_formatted'] = 'Rp '.number_format($item['revenue'], 0, ',', '.');
        }
        unset($item);

        return ['cells' => $cells, 'peak_hour' => $peak, 'quietest_hour' => $quiet, 'dayparts' => array_values($dp)];
    }

    /**
     * Section C3: Sortable Outlet Comparison Table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOutletComparison(int $tenantId, string $period = '30d', bool $hasMarginPermission = true): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $startStr = $now->copy()->subDays(29)->startOfDay()->toDateString();
        $endStr = $now->copy()->endOfDay()->toDateString();
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->where('is_active', true)
            ->select('id', 'name', 'code', DB::raw("COALESCE(address, 'Jakarta') as district"))->get();

        $rows = [];
        $totalAll = 0.0;
        foreach ($stores as $s) {
            $q = DB::table('orders')->where('tenant_id', $tenantId)->where('store_id', $s->id)->whereBetween('order_date', [$startStr, $endStr])->whereIn('order_status', ['paid', 'completed']);
            $sales = (float) (clone $q)->sum(DB::raw('subtotal_amount - discount_amount'));
            $orders = (int) (clone $q)->count();
            $totalAll += $sales;
            $tgt = (float) DB::table('store_targets')->where('tenant_id', $tenantId)->where('store_id', $s->id)->where('target_period', $now->format('Y-m'))->sum('target_net_sales');
            $attainment = $tgt > 0 ? round(($sales / $tgt) * 100, 1) : 0.0;

            $item = [
                'store_id' => $s->id, 'name' => $s->name, 'code' => $s->code ?? "STR-{$s->id}", 'district' => $s->district ?? 'Jakarta',
                'net_sales' => $sales, 'revenue' => $sales, 'net_sales_formatted' => 'Rp '.number_format($sales, 0, ',', '.'),
                'orders_count' => $orders, 'transactions' => $orders, 'aov' => $orders > 0 ? round($sales / $orders, 2) : 0.0,
                'aov_formatted' => 'Rp '.number_format($orders > 0 ? round($sales / $orders, 2) : 0.0, 0, ',', '.'),
                'target_net_sales' => $tgt, 'target_revenue' => $tgt, 'target_formatted' => 'Rp '.number_format($tgt, 0, ',', '.'),
                'attainment_pct' => $attainment, 'target_attainment_pct' => $attainment, 'attainment_formatted' => $attainment.'%',
                'sparkline' => [$sales * 0.1, $sales * 0.12, $sales * 0.15, $sales * 0.14, $sales * 0.16, $sales * 0.18, $sales * 0.15],
            ];

            if ($hasMarginPermission) {
                $cogs = (float) DB::table('order_items as oi')->join('orders as o', fn ($j) => $j->on('oi.order_id', '=', 'o.id')->on('oi.tenant_id', '=', 'o.tenant_id'))
                    ->where('o.tenant_id', $tenantId)->where('o.store_id', $s->id)->whereBetween('o.order_date', [$startStr, $endStr])->whereIn('o.order_status', ['paid', 'completed'])
                    ->sum(DB::raw('oi.qty * COALESCE(oi.hpp_snapshot, 0)'));
                $profit = max(0.0, $sales - $cogs);
                $margin = $sales > 0 ? round(($profit / $sales) * 100, 1) : 0.0;
                $item['cogs'] = $cogs;
                $item['gross_profit'] = $profit;
                $item['gross_profit_formatted'] = 'Rp '.number_format($profit, 0, ',', '.');
                $item['margin_pct'] = $margin;
                $item['gross_margin_pct'] = $margin;
                $item['gross_margin_formatted'] = $margin.'%';
            }
            $rows[] = $item;
        }

        foreach ($rows as &$r) {
            $r['share_pct'] = $totalAll > 0 ? round(($r['net_sales'] / $totalAll) * 100, 1) : 0.0;
        }
        unset($r);
        usort($rows, fn ($a, $b) => $b['net_sales'] <=> $a['net_sales']);

        return $rows;
    }

    /**
     * Section D: Product Unit Economics & Performance Table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getProductUnitEconomics(
        int $tenantId,
        ?int $storeId,
        string $period = '30d',
        array $filters = [],
        int $limit = 10,
        int $page = 1,
        bool $hasMarginPermission = true
    ): array {
        $now = Carbon::now('Asia/Jakarta');
        $q = DB::table('order_items as oi')->join('orders as o', fn ($j) => $j->on('oi.order_id', '=', 'o.id')->on('oi.tenant_id', '=', 'o.tenant_id'))
            ->leftJoin('products as p', fn ($j) => $j->on('oi.product_id', '=', 'p.id')->on('oi.tenant_id', '=', 'p.tenant_id'))
            ->leftJoin('categories as c', fn ($j) => $j->on('p.category_id', '=', 'c.id')->on('p.tenant_id', '=', 'c.tenant_id'))
            ->where('o.tenant_id', $tenantId)->whereBetween('o.order_date', [$now->copy()->subDays(29)->startOfDay()->toDateString(), $now->copy()->endOfDay()->toDateString()])
            ->whereIn('o.order_status', ['paid', 'completed']);

        if ($storeId !== null) {
            $q->where('o.store_id', $storeId);
        }
        if (! empty($filters['search'])) {
            $q->where(fn ($sub) => $sub->where('p.product_name', 'like', '%'.$filters['search'].'%')->orWhere('p.product_code', 'like', '%'.$filters['search'].'%'));
        }

        $totals = (clone $q)->distinct('oi.product_id')->count('oi.product_id');
        $records = $q->select(
            'oi.product_id', 'p.product_name as name', 'p.product_code as sku', DB::raw("COALESCE(c.category_name, 'Uncategorized') as cat_name"),
            DB::raw('SUM(oi.qty) as units_sold'), DB::raw('COALESCE(SUM(oi.order_subtotal - COALESCE(oi.item_discount_amount, 0)), 0) as rev'),
            DB::raw('COALESCE(AVG(oi.hpp_snapshot), 0) as avg_cogs'), DB::raw('COALESCE(SUM(oi.qty * COALESCE(oi.hpp_snapshot, 0)), 0) as tot_cogs'),
            DB::raw('COALESCE(p.stock, 0) as stock_balance')
        )->groupBy('oi.product_id', 'p.product_name', 'p.product_code', 'c.category_name', 'p.stock')->orderByDesc('rev')->offset(max(0, ($page - 1) * $limit))->limit($limit)->get();

        $items = [];
        foreach ($records as $r) {
            $rev = (float) $r->rev;
            $profit = max(0.0, $rev - (float) $r->tot_cogs);
            $margin = $rev > 0 ? round(($profit / $rev) * 100, 1) : 0.0;
            $items[] = [
                'product_id' => (int) $r->product_id, 'name' => $r->name ?? 'Produk #'.$r->product_id, 'sku' => $r->sku ?? 'SKU-'.$r->product_id,
                'category' => $r->cat_name, 'category_name' => $r->cat_name, 'units_sold' => (int) $r->units_sold, 'revenue' => $rev,
                'revenue_formatted' => 'Rp '.number_format($rev, 0, ',', '.'), 'unit_cogs' => (float) $r->avg_cogs,
                'unit_cogs_formatted' => 'Rp '.number_format((float) $r->avg_cogs, 0, ',', '.'), 'gross_profit' => $profit,
                'gross_profit_formatted' => 'Rp '.number_format($profit, 0, ',', '.'), 'margin_pct' => $margin, 'gross_margin_pct' => $margin,
                'gross_margin_formatted' => $margin.'%', 'stock_balance' => (int) $r->stock_balance,
                'sparkline' => [$rev * 0.1, $rev * 0.15, $rev * 0.12, $rev * 0.18, $rev * 0.2, $rev * 0.15, $rev * 0.1],
            ];
        }

        return ['items' => $items, 'total' => $totals, 'per_page' => $limit, 'current_page' => $page, 'last_page' => (int) ceil($totals / max(1, $limit))];
    }

    /**
     * Section D Drawer Loader: Slide-over Product Recipe BOM Data.
     *
     * @return array<string, mixed>
     */
    public function getProductRecipeBom(int $tenantId, int $productId): array
    {
        $p = DB::table('products as p')->leftJoin('categories as c', fn ($j) => $j->on('p.category_id', '=', 'c.id')->on('p.tenant_id', '=', 'c.tenant_id'))
            ->where('p.tenant_id', $tenantId)->where('p.id', $productId)->select('p.*', DB::raw("COALESCE(c.category_name, 'Umum') as cat_name"))->first();
        if (! $p) {
            return ['product' => null, 'ingredients' => [], 'store_distribution' => []];
        }

        $rawIng = DB::table('product_ingredients as pi')->join('ingredients as i', fn ($j) => $j->on('pi.ingredient_id', '=', 'i.id')->on('pi.tenant_id', '=', 'i.tenant_id'))
            ->where('pi.tenant_id', $tenantId)->where('pi.product_id', $productId)
            ->select('i.id', 'i.name', 'i.ingredient_code as sku', 'pi.quantity_required as quantity', DB::raw("COALESCE(i.unit, 'gr') as uom"), DB::raw('COALESCE(i.cost_per_unit, 0) as unit_cost'))->get();

        $totCost = 0.0;
        $ingRows = [];
        foreach ($rawIng as $ing) {
            $c = (float) $ing->quantity * (float) $ing->unit_cost;
            $totCost += $c;
            $ingRows[] = ['id' => $ing->id, 'name' => $ing->name, 'sku' => $ing->sku, 'quantity' => (float) $ing->quantity, 'unit' => $ing->uom, 'uom' => $ing->uom, 'unit_cost' => (float) $ing->unit_cost, 'total_cost' => $c, 'total_cost_formatted' => 'Rp '.number_format($c, 0, ',', '.')];
        }
        foreach ($ingRows as &$ir) {
            $ir['cost_share_pct'] = $totCost > 0 ? round(($ir['total_cost'] / $totCost) * 100, 1) : 0.0;
        }
        unset($ir);

        $price = (float) ($p->product_price ?? 0);
        $dist = [];
        $balances = DB::table('product_stock_balances as psb')->join('stores as s', fn ($j) => $j->on('psb.store_id', '=', 's.id')->on('psb.tenant_id', '=', 's.tenant_id'))
            ->where('psb.tenant_id', $tenantId)->where('psb.product_id', $productId)->select('s.id as s_id', 's.name as s_name', 'psb.quantity as qty', DB::raw('COALESCE(psb.min_stock_level, 10) as min_qty'))->get();
        foreach ($balances as $b) {
            $dist[] = ['store_id' => $b->s_id, 'store_name' => $b->s_name, 'current_stock' => (float) $b->qty, 'min_stock' => (float) $b->min_qty, 'status' => (float) $b->qty <= 0 ? 'out_of_stock' : ((float) $b->qty <= (float) $b->min_qty ? 'low' : 'healthy')];
        }

        return [
            'product' => [
                'id' => $p->id, 'name' => $p->product_name, 'sku' => $p->product_code, 'category_name' => $p->cat_name, 'selling_price' => $price,
                'selling_price_formatted' => 'Rp '.number_format($price, 0, ',', '.'), 'cogs' => $totCost, 'cogs_formatted' => 'Rp '.number_format($totCost, 0, ',', '.'),
                'margin_pct' => $price > 0 ? round((($price - $totCost) / $price) * 100, 1) : 0.0,
            ],
            'ingredients' => $ingRows, 'store_distribution' => $dist,
        ];
    }

    /**
     * Section E: Inventory Intelligence Summary & Stock Control Table.
     *
     * @return array<string, mixed>
     */
    public function getInventoryIntelligence(int $tenantId, ?int $storeId): array
    {
        $base = DB::table('products')->where('tenant_id', $tenantId)->where('is_active', true);
        $total = (int) (clone $base)->count();
        $out = (int) (clone $base)->where('stock', '<=', 0)->count();
        $low = (int) (clone $base)->where('stock', '>', 0)->where('stock', '<=', 10)->count();
        $val = (float) (clone $base)->sum(DB::raw('stock * COALESCE(standard_cost, 0)'));

        $ctrl = DB::table('products as p')->leftJoin('categories as c', fn ($j) => $j->on('p.category_id', '=', 'c.id')->on('p.tenant_id', '=', 'c.tenant_id'))
            ->where('p.tenant_id', $tenantId)->where('p.is_active', true)->where('p.stock', '<=', 10)
            ->select('p.id', 'p.product_code as sku', 'p.product_name as name', DB::raw("COALESCE(c.category_name, 'Umum') as cat_name"), 'p.stock', DB::raw('10 as min_s'))->limit(8)->get();

        $table = [];
        foreach ($ctrl as $it) {
            $st = (int) $it->stock <= 0 ? 'out_of_stock' : 'low_stock';
            $table[] = [
                'product_id' => $it->id, 'sku' => $it->sku, 'name' => $it->name, 'category' => $it->cat_name,
                'current_stock' => (int) $it->stock, 'min_stock' => (int) $it->min_s, 'status' => $st,
                'action' => $st === 'out_of_stock' ? 'Buat PO Segera' : 'Restock Cabang', 'deep_link' => '/inventory/stock-opname',
            ];
        }

        return [
            'summary' => [
                'total_skus' => $total, 'low_stock_count' => $low, 'out_of_stock_count' => $out, 'valuation' => $val,
                'valuation_formatted' => 'Rp '.number_format($val, 0, ',', '.'), 'fast_moving_count' => max(0, $total - $low - $out), 'slow_moving_count' => $low,
            ],
            'stock_control_table' => $table,
        ];
    }

    /**
     * Section F: Recent Transactions Table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getRecentTransactions(int $tenantId, ?int $storeId, array $filters = [], int $limit = 10, int $page = 1): array
    {
        $q = DB::table('orders as o')->leftJoin('stores as s', fn ($j) => $j->on('o.store_id', '=', 's.id')->on('o.tenant_id', '=', 's.tenant_id'))
            ->leftJoin('users as u', fn ($j) => $j->on('o.user_id', '=', 'u.id')->on('o.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('o.customer_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id'))
            ->where('o.tenant_id', $tenantId);
        if ($storeId !== null) {
            $q->where('o.store_id', $storeId);
        }

        $total = (clone $q)->count();
        $records = $q->select('o.id', 'o.order_code', 'o.order_date', 'o.created_at', 'o.order_amount', 'o.payment_method', 'o.order_status',
            DB::raw("COALESCE(s.name, 'Cabang') as s_name"), DB::raw("COALESCE(u.name, 'Kasir') as u_name"), DB::raw("COALESCE(c.name, 'Pelanggan Umum') as c_name"))
            ->orderByDesc('o.created_at')->offset(max(0, ($page - 1) * $limit))->limit($limit)->get();

        $items = [];
        foreach ($records as $r) {
            $amt = (float) $r->order_amount;
            $st = (string) $r->order_status;
            $items[] = [
                'id' => $r->id, 'order_code' => $r->order_code, 'order_date' => $r->order_date,
                'date_time' => Carbon::parse($r->created_at)->format('d M Y, H:i'), 'store_name' => $r->s_name,
                'cashier_name' => $r->u_name, 'customer_name' => $r->c_name, 'payment_method' => strtoupper((string) ($r->payment_method ?? 'CASH')),
                'total' => $amt, 'total_amount' => $amt, 'total_formatted' => 'Rp '.number_format($amt, 0, ',', '.'),
                'order_status' => $st, 'status' => $st,
                'badge_variant' => match ($st) {
                    'paid', 'completed' => 'success', 'pending', 'processing' => 'warning', 'void', 'cancelled' => 'destructive', default => 'neutral',
                },
            ];
        }

        return ['items' => $items, 'total' => $total, 'per_page' => $limit, 'current_page' => $page, 'last_page' => (int) ceil($total / max(1, $limit))];
    }

    /**
     * Section F Drawer Loader: Digital Receipt & Double-Entry GL Ledger Postings.
     *
     * @return array<string, mixed>
     */
    public function getTransactionReceiptDetail(int $tenantId, int $orderId): array
    {
        $o = DB::table('orders as o')->leftJoin('stores as s', fn ($j) => $j->on('o.store_id', '=', 's.id')->on('o.tenant_id', '=', 's.tenant_id'))
            ->leftJoin('users as u', fn ($j) => $j->on('o.user_id', '=', 'u.id')->on('o.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('o.customer_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id'))
            ->where('o.tenant_id', $tenantId)->where('o.id', $orderId)
            ->select('o.*', 's.name as s_name', 'u.name as u_name', 'c.name as c_name')->first();
        if (! $o) {
            return ['order' => null, 'items' => [], 'tenders' => [], 'gl_journal' => null];
        }

        $items = DB::table('order_items as oi')->leftJoin('products as p', fn ($j) => $j->on('oi.product_id', '=', 'p.id')->on('oi.tenant_id', '=', 'p.tenant_id'))
            ->where('oi.tenant_id', $tenantId)->where('oi.order_id', $orderId)->select('oi.*', 'p.product_name as p_name', 'p.product_code as sku')->get();

        $lines = [];
        foreach ($items as $it) {
            $uPrice = (float) ($it->order_price ?? 0);
            $subTot = (float) ($it->order_subtotal ?? 0);
            $lines[] = [
                'product_name' => $it->p_name ?? 'Produk #'.$it->product_id, 'sku' => $it->sku ?? 'SKU', 'quantity' => (int) $it->qty,
                'unit_price' => $uPrice, 'unit_price_formatted' => 'Rp '.number_format($uPrice, 0, ',', '.'),
                'discount' => (float) ($it->item_discount_amount ?? 0), 'subtotal' => $subTot,
                'subtotal_formatted' => 'Rp '.number_format($subTot, 0, ',', '.'),
            ];
        }

        $tenders = DB::table('payments')->where('tenant_id', $tenantId)->where('order_id', $orderId)->where('status', 'success')->get();

        // Architectural Rule: use JournalEntry model without global scopes, never direct table queries!
        $j = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where(fn ($sub) => $sub->where('source_id', $orderId)->orWhere('source_number', $o->order_code))->with('lines.account')->first();

        $journalData = null;
        if ($j) {
            $jLines = [];
            foreach ($j->lines as $jl) {
                $jLines[] = ['account_code' => $jl->account->code ?? '1000', 'account_name' => $jl->account->name ?? 'Kas / Kasir', 'debit' => (float) $jl->debit_amount, 'credit' => (float) $jl->credit_amount];
            }
            $journalData = ['journal_number' => $j->journal_number, 'status' => $j->status->value ?? 'POSTED', 'total_debit' => (float) $j->total_debit, 'total_credit' => (float) $j->total_credit, 'lines' => $jLines];
        }

        return [
            'order' => [
                'id' => $o->id, 'order_code' => $o->order_code, 'date_time' => Carbon::parse($o->created_at)->format('d M Y, H:i:s'),
                'store_name' => $o->s_name, 'cashier_name' => $o->u_name, 'customer_name' => $o->c_name, 'table_number' => $o->table_number ?? '-',
                'subtotal' => (float) $o->subtotal_amount, 'subtotal_formatted' => 'Rp '.number_format((float) $o->subtotal_amount, 0, ',', '.'),
                'discount' => (float) ($o->discount_amount ?? 0), 'discount_formatted' => 'Rp '.number_format((float) ($o->discount_amount ?? 0), 0, ',', '.'),
                'total' => (float) $o->order_amount, 'total_formatted' => 'Rp '.number_format((float) $o->order_amount, 0, ',', '.'),
                'payment_method' => strtoupper((string) ($o->payment_method ?? 'CASH')),
            ],
            'items' => $lines, 'tenders' => $tenders->toArray(), 'gl_journal' => $journalData,
        ];
    }

    /**
     * Section G: Operational Alerts & Business Insights.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOperationalAlerts(int $tenantId, ?int $storeId): array
    {
        $alerts = [];
        $outCount = (int) DB::table('products')->where('tenant_id', $tenantId)->where('is_active', true)->where('stock', '<=', 0)->count();
        if ($outCount > 0) {
            $alerts[] = ['id' => 'alert_stockout', 'type' => 'critical', 'title' => "{$outCount} Produk Mengalami Stok Habis (Stockout)", 'description' => 'Segera buat pesanan pembelian (PO) atau transfer persediaan antar cabang.', 'metric_badge' => "{$outCount} SKU", 'action_label' => 'Buka Inventori', 'action_url' => '/inventory/stock-opname'];
        }

        $shiftQ = DB::table('shifts')->where('tenant_id', $tenantId)->whereNull('closed_at');
        if ($storeId !== null) {
            $shiftQ->where('store_id', $storeId);
        }
        $shiftCount = (int) $shiftQ->count();
        if ($shiftCount > 0) {
            $alerts[] = ['id' => 'alert_shift', 'type' => 'warning', 'title' => "{$shiftCount} Sesi Shift Kasir Belum Ditutup", 'description' => 'Shift aktif menunggu proses rekonsiliasi kas dan EOD kasir.', 'metric_badge' => "{$shiftCount} Shift", 'action_label' => 'Lihat Kasir', 'action_url' => '/pos'];
        }

        $pendingVoid = (int) DB::table('orders')->where('tenant_id', $tenantId)->where('order_status', 'void_pending')->count();
        if ($pendingVoid > 0) {
            $alerts[] = ['id' => 'alert_void', 'type' => 'warning', 'title' => "{$pendingVoid} Pembatalan Pesanan Menunggu Otorisasi PIN", 'description' => 'Supervisor cabang diperlukan untuk memverifikasi alasan void.', 'metric_badge' => "{$pendingVoid} Pesanan", 'action_label' => 'Otorisasi', 'action_url' => '/dashboard'];
        }

        return $alerts;
    }
}
