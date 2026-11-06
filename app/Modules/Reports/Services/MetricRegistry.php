<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single Source of Truth (SSOT) untuk definisi metrik, metadata kamus KPI,
 * dan perhitungan formula deterministik berbasis BCMath pada Modul 15 (RBI).
 */
class MetricRegistry
{
    public const KPI_GROSS_SALES = 'KPI-SAL-01';

    public const KPI_TOTAL_DISCOUNTS = 'KPI-SAL-02';

    public const KPI_NET_SALES = 'KPI-SAL-03';

    public const KPI_AVERAGE_ORDER_VALUE = 'KPI-SAL-04';

    public const KPI_UNITS_PER_TRANSACTION = 'KPI-SAL-05';

    public const KPI_COGS = 'KPI-FIN-01';

    public const KPI_GROSS_PROFIT = 'KPI-FIN-02';

    public const KPI_GROSS_MARGIN_PCT = 'KPI-FIN-03';

    public const KPI_STOCK_VALUE = 'KPI-INV-01';

    public const KPI_INVENTORY_TURNOVER = 'KPI-INV-02';

    public const KPI_DAYS_SALES_OF_INVENTORY = 'KPI-INV-03';

    public const KPI_CUSTOMER_LIFETIME_VALUE = 'KPI-CRM-01';

    public const KPI_FOOD_COST_PCT = 'KPI-FNB-01';

    public const KPI_SHRINKAGE_PCT = 'KPI-RTL-01';

    public const KPI_LABOR_COST_PCT = 'KPI-HRM-01';

    public const KPI_OEE = 'KPI-MFG-01';

    public function __construct(
        protected TenantContext $tenantContext,
    ) {}

    /**
     * Mengembalikan seluruh definisi metrik kanonikal beserta metadata dan sumber otoritatifnya.
     *
     * @return array<string, array{
     *     code: string,
     *     key: string,
     *     name: string,
     *     formula: string,
     *     authoritative_source: string,
     *     unit: string,
     *     scale: int,
     *     gl_account_code: string|null
     * }>
     */
    public function all(): array
    {
        return [
            self::KPI_GROSS_SALES => [
                'code' => self::KPI_GROSS_SALES,
                'key' => 'gross_sales',
                'name' => 'Gross Sales',
                'formula' => 'SUM(item_price * quantity)',
                'authoritative_source' => 'order_items / orders.subtotal_amount',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4-10000',
            ],
            self::KPI_TOTAL_DISCOUNTS => [
                'code' => self::KPI_TOTAL_DISCOUNTS,
                'key' => 'total_discounts',
                'name' => 'Total Discounts',
                'formula' => 'SUM(discount_amount)',
                'authoritative_source' => 'order_items, discounts, orders.discount_amount',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4201',
            ],
            self::KPI_NET_SALES => [
                'code' => self::KPI_NET_SALES,
                'key' => 'net_sales',
                'name' => 'Net Sales',
                'formula' => 'Gross Sales - Total Discounts - Sales Returns',
                'authoritative_source' => 'journal_lines (GL 4-10000 / REVENUE) & orders',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '4-10000',
            ],
            self::KPI_AVERAGE_ORDER_VALUE => [
                'code' => self::KPI_AVERAGE_ORDER_VALUE,
                'key' => 'average_order_value',
                'name' => 'Average Order Value (AOV)',
                'formula' => 'Net Sales / Completed Transactions',
                'authoritative_source' => 'orders',
                'unit' => 'IDR/Order',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_UNITS_PER_TRANSACTION => [
                'code' => self::KPI_UNITS_PER_TRANSACTION,
                'key' => 'units_per_transaction',
                'name' => 'Units per Transaction (UPT)',
                'formula' => 'Total Quantity Sold / Completed Transactions',
                'authoritative_source' => 'order_items, orders',
                'unit' => 'Units/Order',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_COGS => [
                'code' => self::KPI_COGS,
                'key' => 'cogs',
                'name' => 'Cost of Goods Sold (COGS)',
                'formula' => 'SUM(moving_avg_cost * sold_qty)',
                'authoritative_source' => 'stock_movements, ingredient_stock_movements, journal_lines (COGS)',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '5101',
            ],
            self::KPI_GROSS_PROFIT => [
                'code' => self::KPI_GROSS_PROFIT,
                'key' => 'gross_profit',
                'name' => 'Gross Profit',
                'formula' => 'Net Sales - COGS',
                'authoritative_source' => 'journal_lines (GL Aggregation)',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_GROSS_MARGIN_PCT => [
                'code' => self::KPI_GROSS_MARGIN_PCT,
                'key' => 'gross_margin_pct',
                'name' => 'Gross Margin (%)',
                'formula' => '(Gross Profit / Net Sales) * 100',
                'authoritative_source' => 'journal_lines (GL Aggregation)',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_STOCK_VALUE => [
                'code' => self::KPI_STOCK_VALUE,
                'key' => 'stock_value',
                'name' => 'Total Stock Value',
                'formula' => 'SUM(quantity * unit_cost)',
                'authoritative_source' => 'product_stock_balances, inventory_valuation_snapshots',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '1140',
            ],
            self::KPI_INVENTORY_TURNOVER => [
                'code' => self::KPI_INVENTORY_TURNOVER,
                'key' => 'inventory_turnover',
                'name' => 'Inventory Turnover',
                'formula' => 'Period COGS / Average Inventory Valuation',
                'authoritative_source' => 'product_stock_balances & COGS',
                'unit' => 'Ratio',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_DAYS_SALES_OF_INVENTORY => [
                'code' => self::KPI_DAYS_SALES_OF_INVENTORY,
                'key' => 'days_sales_of_inventory',
                'name' => 'Days Sales of Inventory (DSI)',
                'formula' => 'Period Days / Inventory Turnover Ratio',
                'authoritative_source' => 'product_stock_balances & COGS',
                'unit' => 'Days',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_CUSTOMER_LIFETIME_VALUE => [
                'code' => self::KPI_CUSTOMER_LIFETIME_VALUE,
                'key' => 'customer_lifetime_value',
                'name' => 'Customer Lifetime Value (CLV)',
                'formula' => 'Total Customer Revenue / Total Tracked Customers',
                'authoritative_source' => 'orders, customers',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            self::KPI_FOOD_COST_PCT => [
                'code' => self::KPI_FOOD_COST_PCT,
                'key' => 'food_cost_pct',
                'name' => 'Food Cost (%)',
                'formula' => '(Recipe Raw Material COGS / Net Food Sales) * 100',
                'authoritative_source' => 'ingredient_stock_movements',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5102',
            ],
            self::KPI_SHRINKAGE_PCT => [
                'code' => self::KPI_SHRINKAGE_PCT,
                'key' => 'shrinkage_pct',
                'name' => 'Inventory Shrinkage (%)',
                'formula' => '(Damaged or Lost Stock Value / Gross Sales) * 100',
                'authoritative_source' => 'stock_adjustments',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5201',
            ],
            self::KPI_LABOR_COST_PCT => [
                'code' => self::KPI_LABOR_COST_PCT,
                'key' => 'labor_cost_pct',
                'name' => 'Labor Cost (%)',
                'formula' => '(Total Payroll + Overtime + BPJS / Net Sales) * 100',
                'authoritative_source' => 'payroll_batches & orders',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5301',
            ],
            self::KPI_OEE => [
                'code' => self::KPI_OEE,
                'key' => 'oee',
                'name' => 'Overall Equipment Effectiveness (OEE)',
                'formula' => 'Availability * Performance * Quality * 100',
                'authoritative_source' => 'kitchen_tickets, pos_devices',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
        ];
    }

    /**
     * Memeriksa apakah kode atau key metrik terdaftar di dalam kamus.
     */
    public function has(string $codeOrKey): bool
    {
        $definitions = $this->all();
        if (isset($definitions[$codeOrKey])) {
            return true;
        }

        foreach ($definitions as $definition) {
            if ($definition['key'] === $codeOrKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mengambil definisi metrik berdasarkan kode KPI (misal KPI-SAL-03) atau key (misal net_sales).
     *
     * @return array{
     *     code: string,
     *     key: string,
     *     name: string,
     *     formula: string,
     *     authoritative_source: string,
     *     unit: string,
     *     scale: int,
     *     gl_account_code: string|null
     * }
     */
    public function get(string $codeOrKey): array
    {
        $definitions = $this->all();
        if (isset($definitions[$codeOrKey])) {
            return $definitions[$codeOrKey];
        }

        foreach ($definitions as $definition) {
            if ($definition['key'] === $codeOrKey) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Metrik '{$codeOrKey}' tidak ditemukan di dalam MetricRegistry.");
    }

    /**
     * Mengonversi input campuran menjadi numeric-string aman untuk BCMath.
     *
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
     * Pajak (PPN/PB1) dan Service Charge tidak termasuk di dalam Net Sales.
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
        $countStr = (string) $orderCount;

        return bcdiv($net, $countStr, 2);
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
        $countStr = (string) $orderCount;

        return bcdiv($units, $countStr, 2);
    }

    /**
     * KPI-FIN-02: Gross Profit = Net Sales - COGS.
     */
    public function calculateGrossProfit(mixed $netSales, mixed $cogs): string
    {
        $net = $this->toNumericString($netSales, 2);
        $cost = $this->toNumericString($cogs, 2);

        return bcsub($net, $cost, 2);
    }

    /**
     * KPI-FIN-03: Gross Margin % = (Gross Profit / Net Sales) * 100.
     */
    public function calculateGrossMarginPct(mixed $grossProfit, mixed $netSales): string
    {
        $profit = $this->toNumericString($grossProfit, 2);
        $net = $this->toNumericString($netSales, 2);

        if (bccomp($net, '0.00', 2) <= 0) {
            return '0.00';
        }

        return bcmul(bcdiv($profit, $net, 4), '100', 2);
    }

    /**
     * Rata-rata Nilai Persediaan = (Persediaan Awal + Persediaan Akhir) / 2.
     */
    public function calculateAverageInventoryValue(mixed $openingInventory, mixed $closingInventory): string
    {
        $open = $this->toNumericString($openingInventory, 2);
        $close = $this->toNumericString($closingInventory, 2);

        return bcdiv(bcadd($open, $close, 2), '2', 2);
    }

    /**
     * KPI-INV-02: Inventory Turnover Ratio = COGS / Average Inventory Value.
     */
    public function calculateInventoryTurnover(mixed $cogs, mixed $averageInventoryValue, int $scale = 2): string
    {
        $cost = $this->toNumericString($cogs, 4);
        $avgInv = $this->toNumericString($averageInventoryValue, 4);

        if (bccomp($avgInv, '0.0000', 4) <= 0) {
            return $this->toNumericString('0', $scale);
        }

        return bcdiv($cost, $avgInv, $scale);
    }

    /**
     * KPI-INV-03: Days Sales of Inventory (DSI) = Period Days / Inventory Turnover Ratio.
     */
    public function calculateDaysSalesOfInventory(mixed $turnoverRatio, int $periodDays = 365): string
    {
        if ($periodDays <= 0) {
            return '0.00';
        }

        $ratio = $this->toNumericString($turnoverRatio, 4);
        if (bccomp($ratio, '0.0000', 4) <= 0) {
            return '0.00';
        }

        $daysStr = (string) $periodDays;

        return bcdiv($daysStr, $ratio, 2);
    }

    /**
     * KPI-CRM-01: Customer Lifetime Value (CLV) = Total Customer Revenue / Total Tracked Customers.
     */
    public function calculateClv(mixed $totalCustomerRevenue, int $totalTrackedCustomers): string
    {
        if ($totalTrackedCustomers <= 0) {
            return '0.00';
        }

        $revenue = $this->toNumericString($totalCustomerRevenue, 2);
        $custStr = (string) $totalTrackedCustomers;

        return bcdiv($revenue, $custStr, 2);
    }

    /**
     * KPI-FNB-01: Food Cost % = (Recipe Ingredient Cost / Net F&B Sales) * 100.
     */
    public function calculateFoodCostPct(mixed $recipeIngredientCost, mixed $netFnbSales): string
    {
        return $this->calculateContributionPct($recipeIngredientCost, $netFnbSales);
    }

    /**
     * KPI-RTL-01: Shrinkage % = (Lost or Damaged Stock Value / Gross Sales) * 100.
     */
    public function calculateShrinkagePct(mixed $lostDamagedStockValue, mixed $grossSales): string
    {
        return $this->calculateContributionPct($lostDamagedStockValue, $grossSales);
    }

    /**
     * KPI-HRM-01: Labor Cost % = (Total Labor Cost / Net Sales) * 100.
     */
    public function calculateLaborCostPct(mixed $totalLaborCost, mixed $netSales): string
    {
        return $this->calculateContributionPct($totalLaborCost, $netSales);
    }

    /**
     * KPI-MFG-01: Overall Equipment Effectiveness (OEE %) = Availability * Performance * Quality * 100.
     * Mendukung input rasio desimal (0.0 - 1.0) maupun persentase (0 - 100).
     */
    public function calculateOee(mixed $availability, mixed $performance, mixed $quality): string
    {
        $norm = function (mixed $val): string {
            $num = $this->toNumericString($val, 6);
            if (bccomp($num, '0.000000', 6) <= 0) {
                return '0.000000';
            }
            if (bccomp($num, '1.000000', 6) > 0) {
                return bcdiv($num, '100', 6);
            }

            return $num;
        };

        $a = $this->toNumericString($norm($availability), 6);
        $p = $this->toNumericString($norm($performance), 6);
        $q = $this->toNumericString($norm($quality), 6);

        $product = bcmul(bcmul($a, $p, 6), $q, 6);

        return bcmul($product, '100', 2);
    }

    /**
     * Menghitung persentase kontribusi = (Part / Total) * 100.
     */
    public function calculateContributionPct(mixed $partValue, mixed $totalValue): string
    {
        $part = $this->toNumericString($partValue, 4);
        $total = $this->toNumericString($totalValue, 4);

        if (bccomp($total, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcmul(bcdiv($part, $total, 4), '100', 2);
    }

    /**
     * Menghitung persentase pertumbuhan periode = ((Current - Previous) / Previous) * 100.
     */
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
     * Menghitung rincian pendapatan penjualan bersih (Net Sales) dari General Ledger
     * untuk akun pendapatan (4-10000 / 4101-4202 / AccountTypeEnum::REVENUE)
     * dengan menegakkan isolasi tenant, isolasi cabang, validasi periode fiskal,
     * penanganan jurnal pembalik (reversal), diskon kontra, dan retur penjualan.
     *
     * @return array{
     *     gross_revenue: string,
     *     sales_discounts: string,
     *     sales_returns: string,
     *     net_sales: string,
     *     journal_count: int
     * }
     */
    public function computeGlRevenueBreakdown(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?string $accountCode = null
    ): array {
        $resolvedTenantId = $tenantId ?? $this->tenantContext->requireTenantId();
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $query = DB::table('journal_lines as jl')
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
            ->where('je.tenant_id', $resolvedTenantId)
            ->whereBetween('je.posting_date', [$startStr, $endStr])
            ->where(function ($q): void {
                // Pastikan tanggal posting konsisten dengan batas periode fiskal jika terhubung
                $q->whereNull('ap.id')
                    ->orWhereRaw('je.posting_date BETWEEN ap.start_date AND ap.end_date');
            })
            ->where(function ($q): void {
                // Hanya hitung jurnal POSTED, ditambah jurnal REVERSED yang memiliki pasangan jurnal pembalik POSTED
                // agar efek bersih jurnal yang di-reverse melalui JournalEntryService/PostingEngineService adalah 0.00.
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
            });

        if ($accountCode !== null) {
            $query->where('coa.account_code', $accountCode);
        } else {
            $query->where(function ($q): void {
                $q->where('coa.account_type', AccountTypeEnum::REVENUE->value)
                    ->orWhere('coa.account_code', '4-10000')
                    ->orWhere('coa.account_code', 'like', '4-1%');
            });
        }

        if ($storeId !== null) {
            $query->whereRaw('COALESCE(jl.store_id, je.store_id) = ?', [$storeId]);
        }

        $rows = $query->selectRaw('
            coa.account_code,
            coa.account_subtype,
            coa.system_account_key,
            coa.normal_balance,
            COALESCE(SUM(jl.debit_amount), 0) as total_debit,
            COALESCE(SUM(jl.credit_amount), 0) as total_credit,
            COUNT(DISTINCT je.id) as journal_count
        ')
            ->groupBy('coa.id', 'coa.account_code', 'coa.account_subtype', 'coa.system_account_key', 'coa.normal_balance')
            ->get();

        $grossRevenue = '0.00';
        $salesDiscounts = '0.00';
        $salesReturns = '0.00';
        $netSales = '0.00';
        $journalCount = 0;

        foreach ($rows as $row) {
            $debit = $this->toNumericString($row->total_debit, 2);
            $credit = $this->toNumericString($row->total_credit, 2);
            $journalCount += (int) $row->journal_count;

            // Kontribusi terhadap Net Sales untuk seluruh akun grup Revenue adalah Credit - Debit
            $lineNet = bcsub($credit, $debit, 2);
            $netSales = bcadd($netSales, $lineNet, 2);

            $subtype = (string) ($row->account_subtype ?? '');
            $sysKey = (string) ($row->system_account_key ?? '');
            $code = (string) ($row->account_code ?? '');

            if ($subtype === AccountSubtypeEnum::DISCOUNT_CONTRA->value || $sysKey === 'SALES_DISCOUNT' || $code === '4201') {
                $salesDiscounts = bcadd($salesDiscounts, bcsub($debit, $credit, 2), 2);
            } elseif ($subtype === AccountSubtypeEnum::RETURN_CONTRA->value || $sysKey === 'SALES_RETURN' || $code === '4202') {
                $salesReturns = bcadd($salesReturns, bcsub($debit, $credit, 2), 2);
            } else {
                $grossRevenue = bcadd($grossRevenue, bcsub($credit, $debit, 2), 2);
            }
        }

        return [
            'gross_revenue' => $grossRevenue,
            'sales_discounts' => $salesDiscounts,
            'sales_returns' => $salesReturns,
            'net_sales' => $netSales,
            'journal_count' => $journalCount,
        ];
    }

    /**
     * Menghitung Net Sales otoritatif dari General Ledger (akun 4-10000 / REVENUE).
     */
    public function computeGlNetSales(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?string $accountCode = null
    ): string {
        return $this->computeGlRevenueBreakdown($start, $end, $storeId, $tenantId, $accountCode)['net_sales'];
    }
}
