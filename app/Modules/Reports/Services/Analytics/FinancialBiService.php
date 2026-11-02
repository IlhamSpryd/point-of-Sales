<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services\Analytics;

use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialBiService
{
    public function __construct(
        protected TenantContext $tenantContext,
    ) {}

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
     * Ringkasan Laporan Laba Rugi Eksekutif (Executive Profit & Loss):
     * Menghitung Pendapatan, HPP, Laba Kotor, Biaya Operasional, dan Laba Bersih
     * bersumber langsung dari buku besar FIN (Journal Entries terposting).
     *
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     store_id: int|null,
     *     revenue: string,
     *     cogs: string,
     *     gross_profit: string,
     *     gross_margin_pct: string,
     *     operating_expense: string,
     *     operating_profit: string,
     *     operating_margin_pct: string,
     *     other_income: string,
     *     other_expense: string,
     *     net_profit: string,
     *     net_margin_pct: string,
     *     revenue_breakdown: array<int, array{account_id: int, account_code: string, account_name: string, amount: string}>,
     *     cogs_breakdown: array<int, array{account_id: int, account_code: string, account_name: string, amount: string}>,
     *     expense_breakdown: array<int, array{account_id: int, account_code: string, account_name: string, amount: string}>
     * }
     */
    public function getProfitAndLossSummary(Carbon $start, Carbon $end, ?int $storeId = null): array
    {
        $tenantId = $this->tenantContext->requireTenantId();

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.tenant_id', $tenantId)
            ->where('je.status', JournalStatusEnum::POSTED->value)
            ->whereBetween('je.posting_date', [$start->toDateString(), $end->toDateString()]);

        if ($storeId !== null) {
            $query->where(function ($q) use ($storeId) {
                $q->where('je.store_id', $storeId)
                    ->orWhere('jl.store_id', $storeId);
            });
        }

        $records = $query->selectRaw('
            coa.id as account_id,
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            SUM(jl.debit_amount) as total_debit,
            SUM(jl.credit_amount) as total_credit
        ')
        ->groupBy('coa.id', 'coa.account_code', 'coa.account_name', 'coa.account_type', 'coa.normal_balance')
        ->get();

        $revenue = '0.00';
        $cogs = '0.00';
        $opEx = '0.00';
        $otherIncome = '0.00';
        $otherExpense = '0.00';

        $revenueBreakdown = [];
        $cogsBreakdown = [];
        $expenseBreakdown = [];

        foreach ($records as $row) {
            $debit = bcadd($this->toNumericString($row->total_debit), '0', 2);
            $credit = bcadd($this->toNumericString($row->total_credit), '0', 2);

            // Perhitungan saldo bersih akun berdasarkan normal balance
            // Akun CREDIT (Revenue, Other Income): saldo = credit - debit
            // Akun DEBIT (COGS, Expense, Other Expense): saldo = debit - credit
            $normalBal = (string) $row->normal_balance;
            $net = $normalBal === NormalBalanceEnum::CREDIT->value
                ? bcsub($credit, $debit, 2)
                : bcsub($debit, $credit, 2);
            $netStr = $this->toNumericString($net);

            $accType = (string) $row->account_type;

            $itemData = [
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'amount' => $netStr,
            ];

            match ($accType) {
                AccountTypeEnum::REVENUE->value => (function () use (&$revenue, &$revenueBreakdown, $netStr, $itemData) {
                    $revenue = bcadd($revenue, $netStr, 2);
                    $revenueBreakdown[] = $itemData;
                })(),
                AccountTypeEnum::COGS->value => (function () use (&$cogs, &$cogsBreakdown, $netStr, $itemData) {
                    $cogs = bcadd($cogs, $netStr, 2);
                    $cogsBreakdown[] = $itemData;
                })(),
                AccountTypeEnum::EXPENSE->value => (function () use (&$opEx, &$expenseBreakdown, $netStr, $itemData) {
                    $opEx = bcadd($opEx, $netStr, 2);
                    $expenseBreakdown[] = $itemData;
                })(),
                AccountTypeEnum::OTHER_INCOME->value => (function () use (&$otherIncome, $netStr) {
                    $otherIncome = bcadd($otherIncome, $netStr, 2);
                })(),
                AccountTypeEnum::OTHER_EXPENSE->value => (function () use (&$otherExpense, $netStr) {
                    $otherExpense = bcadd($otherExpense, $netStr, 2);
                })(),
                default => null,
            };
        }

        // Laba Kotor = Revenue - COGS
        $grossProfit = bcsub($revenue, $cogs, 2);
        $grossMarginPct = '0.00';
        if (bccomp($revenue, '0.00', 2) > 0) {
            $grossMarginPct = bcmul(bcdiv($grossProfit, $revenue, 4), '100', 2);
        }

        // Laba Operasi (EBIT) = Gross Profit - OpEx
        $operatingProfit = bcsub($grossProfit, $opEx, 2);
        $operatingMarginPct = '0.00';
        if (bccomp($revenue, '0.00', 2) > 0) {
            $operatingMarginPct = bcmul(bcdiv($operatingProfit, $revenue, 4), '100', 2);
        }

        // Laba Bersih = Operating Profit + Other Income - Other Expense
        $netProfit = bcsub(bcadd($operatingProfit, $otherIncome, 2), $otherExpense, 2);
        $netMarginPct = '0.00';
        if (bccomp($revenue, '0.00', 2) > 0) {
            $netMarginPct = bcmul(bcdiv($netProfit, $revenue, 4), '100', 2);
        }

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'store_id' => $storeId,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $grossMarginPct,
            'operating_expense' => $opEx,
            'operating_profit' => $operatingProfit,
            'operating_margin_pct' => $operatingMarginPct,
            'other_income' => $otherIncome,
            'other_expense' => $otherExpense,
            'net_profit' => $netProfit,
            'net_margin_pct' => $netMarginPct,
            'revenue_breakdown' => $revenueBreakdown,
            'cogs_breakdown' => $cogsBreakdown,
            'expense_breakdown' => $expenseBreakdown,
        ];
    }
}
