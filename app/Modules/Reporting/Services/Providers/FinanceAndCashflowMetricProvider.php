<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services\Providers;

use App\Core\Context\TenantContext;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Metric calculations for Finance, General Ledger, COGS, Margins, and Cashflow (STR-14, ADR-108).
 */
class FinanceAndCashflowMetricProvider
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
            'KPI-FIN-01' => [
                'code' => 'KPI-FIN-01',
                'key' => 'cogs',
                'name' => 'Cost of Goods Sold (COGS)',
                'formula' => 'SUM(moving_avg_cost * sold_qty)',
                'authoritative_source' => 'stock_movements, ingredient_stock_movements, journal_lines (COGS)',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '5101',
            ],
            'KPI-FIN-02' => [
                'code' => 'KPI-FIN-02',
                'key' => 'gross_profit',
                'name' => 'Gross Profit',
                'formula' => 'Net Sales - COGS',
                'authoritative_source' => 'journal_lines (GL Aggregation)',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-FIN-03' => [
                'code' => 'KPI-FIN-03',
                'key' => 'gross_margin_pct',
                'name' => 'Gross Margin (%)',
                'formula' => '(Gross Profit / Net Sales) * 100',
                'authoritative_source' => 'journal_lines (GL Aggregation)',
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
     * Menghitung rincian pendapatan penjualan bersih (Net Sales) dari General Ledger.
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
