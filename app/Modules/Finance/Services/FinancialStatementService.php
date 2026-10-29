<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\AccountingPeriod;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialStatementService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Menghasilkan Neraca Saldo (Trial Balance) per cabang atau konsolidasi tenant.
     *
     * @return array{
     *     metadata: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, string>,
     *     is_balanced: bool,
     *     discrepancy: string
     * }
     */
    public function generateTrialBalance(int $periodId, ?int $storeId = null, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $rows = [];
        $totalOpeningDebit = '0.00';
        $totalOpeningCredit = '0.00';
        $totalPeriodDebit = '0.00';
        $totalPeriodCredit = '0.00';
        $totalClosingDebit = '0.00';
        $totalClosingCredit = '0.00';

        foreach ($accounts as $acc) {
            $isPermanent = in_array($acc->account_type, [
                AccountTypeEnum::ASSET,
                AccountTypeEnum::LIABILITY,
                AccountTypeEnum::EQUITY,
            ], true);

            // 1. Saldo Awal
            // Untuk akun permanen: seluruh mutasi historis sebelum start_date periode
            // Untuk akun nominal: mutasi dari awal tahun fiskal hingga sebelum start_date periode
            $openingQuery = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_lines.account_id', $acc->id)
                ->where('journal_entries.posting_date', '<', $period->start_date->toDateString());

            if ($storeId !== null) {
                $openingQuery->where('journal_entries.store_id', $storeId);
            }

            if (! $isPermanent) {
                $fiscalYearStart = Carbon::createFromDate($period->fiscal_year, 1, 1)->toDateString();
                $openingQuery->where('journal_entries.posting_date', '>=', $fiscalYearStart);
            }

            $openingTotals = $openingQuery->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as debits, COALESCE(SUM(journal_lines.credit_amount), 0) as credits')->first();
            $rawOpenDebit = (string) ($openingTotals->debits ?? '0.00');
            $rawOpenCredit = (string) ($openingTotals->credits ?? '0.00');

            if ($acc->normal_balance === NormalBalanceEnum::DEBIT) {
                $netOpen = bcsub($rawOpenDebit, $rawOpenCredit, 2);
                if (bccomp($netOpen, '0.00', 2) >= 0) {
                    $openDebit = $netOpen;
                    $openCredit = '0.00';
                } else {
                    $openDebit = '0.00';
                    $openCredit = bcmul($netOpen, '-1', 2);
                }
            } else {
                $netOpen = bcsub($rawOpenCredit, $rawOpenDebit, 2);
                if (bccomp($netOpen, '0.00', 2) >= 0) {
                    $openCredit = $netOpen;
                    $openDebit = '0.00';
                } else {
                    $openCredit = '0.00';
                    $openDebit = bcmul($netOpen, '-1', 2);
                }
            }

            // 2. Mutasi Periode Ini
            $periodQuery = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_lines.account_id', $acc->id)
                ->where('journal_entries.posting_date', '>=', $period->start_date->toDateString())
                ->where('journal_entries.posting_date', '<=', $period->end_date->toDateString());

            if ($storeId !== null) {
                $periodQuery->where('journal_entries.store_id', $storeId);
            }

            $periodTotals = $periodQuery->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as debits, COALESCE(SUM(journal_lines.credit_amount), 0) as credits')->first();
            $periodDebit = (string) ($periodTotals->debits ?? '0.00');
            $periodCredit = (string) ($periodTotals->credits ?? '0.00');

            // 3. Saldo Akhir
            $cumDebit = bcadd($openDebit, $periodDebit, 2);
            $cumCredit = bcadd($openCredit, $periodCredit, 2);

            if ($acc->normal_balance === NormalBalanceEnum::DEBIT) {
                $netClose = bcsub($cumDebit, $cumCredit, 2);
                if (bccomp($netClose, '0.00', 2) >= 0) {
                    $closingDebit = $netClose;
                    $closingCredit = '0.00';
                } else {
                    $closingDebit = '0.00';
                    $closingCredit = bcmul($netClose, '-1', 2);
                }
            } else {
                $netClose = bcsub($cumCredit, $cumDebit, 2);
                if (bccomp($netClose, '0.00', 2) >= 0) {
                    $closingCredit = $netClose;
                    $closingDebit = '0.00';
                } else {
                    $closingCredit = '0.00';
                    $closingDebit = bcmul($netClose, '-1', 2);
                }
            }

            // Akumulasi total
            $totalOpeningDebit = bcadd($totalOpeningDebit, $openDebit, 2);
            $totalOpeningCredit = bcadd($totalOpeningCredit, $openCredit, 2);
            $totalPeriodDebit = bcadd($totalPeriodDebit, $periodDebit, 2);
            $totalPeriodCredit = bcadd($totalPeriodCredit, $periodCredit, 2);
            $totalClosingDebit = bcadd($totalClosingDebit, $closingDebit, 2);
            $totalClosingCredit = bcadd($totalClosingCredit, $closingCredit, 2);

            $rows[] = [
                'account_id' => $acc->id,
                'account_code' => $acc->account_code,
                'account_name' => $acc->account_name,
                'account_type' => $acc->account_type->value,
                'account_subtype' => $acc->account_subtype?->value,
                'normal_balance' => $acc->normal_balance->value,
                'opening_debit' => $openDebit,
                'opening_credit' => $openCredit,
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_debit' => $closingDebit,
                'closing_credit' => $closingCredit,
            ];
        }

        $discrepancy = bcsub($totalClosingDebit, $totalClosingCredit, 2);
        $isBalanced = bccomp($discrepancy, '0.00', 2) === 0;

        return [
            'metadata' => [
                'report_name' => 'Trial Balance',
                'period_id' => $period->id,
                'period_code' => $period->period_code,
                'fiscal_year' => $period->fiscal_year,
                'period_number' => $period->period_number,
                'store_id' => $storeId,
                'tenant_id' => $tenantId,
                'generated_at' => now()->toIso8601String(),
            ],
            'rows' => $rows,
            'totals' => [
                'total_opening_debit' => $totalOpeningDebit,
                'total_opening_credit' => $totalOpeningCredit,
                'total_period_debit' => $totalPeriodDebit,
                'total_period_credit' => $totalPeriodCredit,
                'total_closing_debit' => $totalClosingDebit,
                'total_closing_credit' => $totalClosingCredit,
            ],
            'is_balanced' => $isBalanced,
            'discrepancy' => $discrepancy,
        ];
    }

    /**
     * Menghasilkan Laporan Laba Rugi (Profit and Loss / Income Statement).
     *
     * @return array{
     *     metadata: array<string, mixed>,
     *     sections: array<string, mixed>,
     *     net_income: string
     * }
     */
    public function generateProfitAndLoss(int $periodId, ?int $storeId = null, ?int $tenantId = null, bool $yearToDate = false): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $startDate = $yearToDate
            ? Carbon::createFromDate($period->fiscal_year, 1, 1)->toDateString()
            : $period->start_date->toDateString();
        $endDate = $period->end_date->toDateString();

        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('account_type', [
                AccountTypeEnum::REVENUE,
                AccountTypeEnum::COGS,
                AccountTypeEnum::EXPENSE,
                AccountTypeEnum::OTHER_INCOME,
                AccountTypeEnum::OTHER_EXPENSE,
            ])
            ->orderBy('account_code')
            ->get();

        $revenueItems = [];
        $totalRevenue = '0.00';

        $cogsItems = [];
        $totalCogs = '0.00';

        $expenseItems = [];
        $totalExpense = '0.00';

        $otherIncomeItems = [];
        $totalOtherIncome = '0.00';

        $otherExpenseItems = [];
        $totalOtherExpense = '0.00';

        foreach ($accounts as $acc) {
            $query = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_lines.account_id', $acc->id)
                ->where('journal_entries.posting_date', '>=', $startDate)
                ->where('journal_entries.posting_date', '<=', $endDate);

            if ($storeId !== null) {
                $query->where('journal_entries.store_id', $storeId);
            }

            $totals = $query->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as debits, COALESCE(SUM(journal_lines.credit_amount), 0) as credits')->first();
            $debits = (string) ($totals->debits ?? '0.00');
            $credits = (string) ($totals->credits ?? '0.00');

            if ($acc->account_type === AccountTypeEnum::REVENUE) {
                $net = bcsub($credits, $debits, 2);
                $totalRevenue = bcadd($totalRevenue, $net, 2);
                $revenueItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            } elseif ($acc->account_type === AccountTypeEnum::COGS) {
                $net = bcsub($debits, $credits, 2);
                $totalCogs = bcadd($totalCogs, $net, 2);
                $cogsItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            } elseif ($acc->account_type === AccountTypeEnum::EXPENSE) {
                $net = bcsub($debits, $credits, 2);
                $totalExpense = bcadd($totalExpense, $net, 2);
                $expenseItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            } elseif ($acc->account_type === AccountTypeEnum::OTHER_INCOME) {
                $net = bcsub($credits, $debits, 2);
                $totalOtherIncome = bcadd($totalOtherIncome, $net, 2);
                $otherIncomeItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            } elseif ($acc->account_type === AccountTypeEnum::OTHER_EXPENSE) {
                $net = bcsub($debits, $credits, 2);
                $totalOtherExpense = bcadd($totalOtherExpense, $net, 2);
                $otherExpenseItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            }
        }

        // Laba Kotor = Total Pendapatan - Total HPP
        $grossProfit = bcsub($totalRevenue, $totalCogs, 2);

        // Laba Operasional = Laba Kotor - Beban Operasional
        $operatingIncome = bcsub($grossProfit, $totalExpense, 2);

        // Pendapatan / (Beban) Lain-lain Bersih
        $netOtherIncomeExpense = bcsub($totalOtherIncome, $totalOtherExpense, 2);

        // Laba Bersih = Laba Operasional + Pendapatan / (Beban) Lain-lain Bersih
        $netIncome = bcadd($operatingIncome, $netOtherIncomeExpense, 2);

        return [
            'metadata' => [
                'report_name' => 'Profit and Loss Statement',
                'period_id' => $period->id,
                'period_code' => $period->period_code,
                'fiscal_year' => $period->fiscal_year,
                'period_number' => $period->period_number,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_year_to_date' => $yearToDate,
                'store_id' => $storeId,
                'tenant_id' => $tenantId,
                'generated_at' => now()->toIso8601String(),
            ],
            'sections' => [
                'revenue' => [
                    'items' => $revenueItems,
                    'total' => $totalRevenue,
                ],
                'cogs' => [
                    'items' => $cogsItems,
                    'total' => $totalCogs,
                ],
                'gross_profit' => $grossProfit,
                'operating_expenses' => [
                    'items' => $expenseItems,
                    'total' => $totalExpense,
                ],
                'operating_income' => $operatingIncome,
                'other_income' => [
                    'items' => $otherIncomeItems,
                    'total' => $totalOtherIncome,
                ],
                'other_expense' => [
                    'items' => $otherExpenseItems,
                    'total' => $totalOtherExpense,
                ],
                'net_other_income_expense' => $netOtherIncomeExpense,
            ],
            'net_income' => $netIncome,
        ];
    }

    /**
     * Menghasilkan Neraca (Balance Sheet / Statement of Financial Position).
     *
     * @return array{
     *     metadata: array<string, mixed>,
     *     assets: array<string, mixed>,
     *     liabilities: array<string, mixed>,
     *     equity: array<string, mixed>,
     *     total_liabilities_and_equity: string,
     *     is_balanced: bool,
     *     discrepancy: string
     * }
     */
    public function generateBalanceSheet(int $periodId, ?int $storeId = null, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $asOfDate = $period->end_date->toDateString();

        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('account_type', [
                AccountTypeEnum::ASSET,
                AccountTypeEnum::LIABILITY,
                AccountTypeEnum::EQUITY,
            ])
            ->orderBy('account_code')
            ->get();

        $currentAssetItems = [];
        $totalCurrentAssets = '0.00';

        $nonCurrentAssetItems = [];
        $totalNonCurrentAssets = '0.00';

        $currentLiabilityItems = [];
        $totalCurrentLiabilities = '0.00';

        $nonCurrentLiabilityItems = [];
        $totalNonCurrentLiabilities = '0.00';

        $equityItems = [];
        $totalBaseEquity = '0.00';

        foreach ($accounts as $acc) {
            $query = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_lines.account_id', $acc->id)
                ->where('journal_entries.posting_date', '<=', $asOfDate);

            if ($storeId !== null) {
                $query->where('journal_entries.store_id', $storeId);
            }

            $totals = $query->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as debits, COALESCE(SUM(journal_lines.credit_amount), 0) as credits')->first();
            $debits = (string) ($totals->debits ?? '0.00');
            $credits = (string) ($totals->credits ?? '0.00');

            if ($acc->account_type === AccountTypeEnum::ASSET) {
                if ($acc->normal_balance === NormalBalanceEnum::DEBIT) {
                    $net = bcsub($debits, $credits, 2);
                } else {
                    $net = bcmul(bcsub($credits, $debits, 2), '-1', 2);
                }

                $isNonCurrent = in_array($acc->account_subtype, [
                    AccountSubtypeEnum::FIXED_ASSET,
                    AccountSubtypeEnum::ACCUMULATED_DEPRECIATION,
                ], true) || str_starts_with($acc->account_code, '15') || str_starts_with($acc->account_code, '16');

                $item = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];

                if ($isNonCurrent) {
                    $totalNonCurrentAssets = bcadd($totalNonCurrentAssets, $net, 2);
                    $nonCurrentAssetItems[] = $item;
                } else {
                    $totalCurrentAssets = bcadd($totalCurrentAssets, $net, 2);
                    $currentAssetItems[] = $item;
                }
            } elseif ($acc->account_type === AccountTypeEnum::LIABILITY) {
                if ($acc->normal_balance === NormalBalanceEnum::CREDIT) {
                    $net = bcsub($credits, $debits, 2);
                } else {
                    $net = bcmul(bcsub($debits, $credits, 2), '-1', 2);
                }

                $isNonCurrent = str_starts_with($acc->account_code, '25') || str_starts_with($acc->account_code, '26');

                $item = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];

                if ($isNonCurrent) {
                    $totalNonCurrentLiabilities = bcadd($totalNonCurrentLiabilities, $net, 2);
                    $nonCurrentLiabilityItems[] = $item;
                } else {
                    $totalCurrentLiabilities = bcadd($totalCurrentLiabilities, $net, 2);
                    $currentLiabilityItems[] = $item;
                }
            } elseif ($acc->account_type === AccountTypeEnum::EQUITY) {
                if ($acc->normal_balance === NormalBalanceEnum::CREDIT) {
                    $net = bcsub($credits, $debits, 2);
                } else {
                    $net = bcmul(bcsub($debits, $credits, 2), '-1', 2);
                }

                $totalBaseEquity = bcadd($totalBaseEquity, $net, 2);
                $equityItems[] = [
                    'account_id' => $acc->id,
                    'account_code' => $acc->account_code,
                    'account_name' => $acc->account_name,
                    'amount' => $net,
                ];
            }
        }

        $totalAssets = bcadd($totalCurrentAssets, $totalNonCurrentAssets, 2);
        $totalLiabilities = bcadd($totalCurrentLiabilities, $totalNonCurrentLiabilities, 2);

        // Laba Bersih Tahun Berjalan (YTD Net Income)
        // Dihitung dari seluruh akun nominal (Revenues - COGS - Expenses) sejak awal tahun fiskal s/d periode ini.
        $pnl = $this->generateProfitAndLoss($period->id, $storeId, $tenantId, yearToDate: true);
        $currentYearNetIncome = $pnl['net_income'];

        // Total Ekuitas = Ekuitas Pokok + Laba Tahun Berjalan
        $totalEquity = bcadd($totalBaseEquity, $currentYearNetIncome, 2);

        $totalLiabilitiesAndEquity = bcadd($totalLiabilities, $totalEquity, 2);

        $discrepancy = bcsub($totalAssets, $totalLiabilitiesAndEquity, 2);
        $isBalanced = bccomp($discrepancy, '0.00', 2) === 0;

        return [
            'metadata' => [
                'report_name' => 'Balance Sheet',
                'period_id' => $period->id,
                'period_code' => $period->period_code,
                'as_of_date' => $asOfDate,
                'store_id' => $storeId,
                'tenant_id' => $tenantId,
                'generated_at' => now()->toIso8601String(),
            ],
            'assets' => [
                'current_assets' => [
                    'items' => $currentAssetItems,
                    'total' => $totalCurrentAssets,
                ],
                'non_current_assets' => [
                    'items' => $nonCurrentAssetItems,
                    'total' => $totalNonCurrentAssets,
                ],
                'total_assets' => $totalAssets,
            ],
            'liabilities' => [
                'current_liabilities' => [
                    'items' => $currentLiabilityItems,
                    'total' => $totalCurrentLiabilities,
                ],
                'non_current_liabilities' => [
                    'items' => $nonCurrentLiabilityItems,
                    'total' => $totalNonCurrentLiabilities,
                ],
                'total_liabilities' => $totalLiabilities,
            ],
            'equity' => [
                'items' => $equityItems,
                'base_equity_total' => $totalBaseEquity,
                'current_year_net_income' => $currentYearNetIncome,
                'total_equity' => $totalEquity,
            ],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => $isBalanced,
            'discrepancy' => $discrepancy,
        ];
    }

    /**
     * Menghasilkan Laporan Arus Kas (Cash Flow Statement).
     *
     * @return array{
     *     metadata: array<string, mixed>,
     *     beginning_cash: string,
     *     operating_activities: array<string, mixed>,
     *     investing_activities: array<string, mixed>,
     *     financing_activities: array<string, mixed>,
     *     net_cash_flow: string,
     *     ending_cash: string,
     *     is_reconciled: bool,
     *     difference: string
     * }
     */
    public function generateCashFlowStatement(int $periodId, ?int $storeId = null, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $startDate = $period->start_date->toDateString();
        $endDate = $period->end_date->toDateString();

        // 1. Dapatkan akun Kas & Bank
        $cashAccounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('account_subtype', AccountSubtypeEnum::CASH_BANK)
                    ->orWhereIn('account_code', ['1101', '1102', '1103', '1110', '1120']);
            })
            ->pluck('id')
            ->toArray();

        // 2. Saldo Kas Awal & Akhir
        $calcCash = function (?string $upToDate) use ($tenantId, $cashAccounts, $storeId): string {
            if (empty($cashAccounts)) {
                return '0.00';
            }

            $query = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->whereIn('journal_lines.account_id', $cashAccounts);

            if ($upToDate !== null) {
                $query->where('journal_entries.posting_date', '<=', $upToDate);
            }

            if ($storeId !== null) {
                $query->where('journal_entries.store_id', $storeId);
            }

            $res = $query->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) as balance')->first();

            return (string) ($res->balance ?? '0.00');
        };

        $dayBeforeStart = $period->start_date->copy()->subDay()->toDateString();
        $beginningCash = $calcCash($dayBeforeStart);
        $endingCash = $calcCash($endDate);
        $actualNetChange = bcsub($endingCash, $beginningCash, 2);

        // 3. Analisis mutasi jurnal kas selama periode ini
        $journalIds = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'POSTED')
            ->where('journal_entries.posting_date', '>=', $startDate)
            ->where('journal_entries.posting_date', '<=', $endDate)
            ->whereIn('journal_lines.account_id', $cashAccounts)
            ->when($storeId !== null, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->distinct()
            ->pluck('journal_entries.id');

        $operatingInflow = '0.00';
        $operatingOutflow = '0.00';
        $investingInflow = '0.00';
        $investingOutflow = '0.00';
        $financingInflow = '0.00';
        $financingOutflow = '0.00';

        if ($journalIds->isNotEmpty()) {
            $lines = DB::table('journal_lines')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
                ->whereIn('journal_lines.journal_entry_id', $journalIds)
                ->select([
                    'journal_lines.journal_entry_id',
                    'journal_lines.account_id',
                    'journal_lines.debit_amount',
                    'journal_lines.credit_amount',
                    'chart_of_accounts.account_type',
                    'chart_of_accounts.account_subtype',
                    'chart_of_accounts.account_code',
                ])
                ->get()
                ->groupBy('journal_entry_id');

            foreach ($lines as $entryLines) {
                $cashDebit = '0.00';
                $cashCredit = '0.00';

                /** @var Collection $entryLines */
                foreach ($entryLines as $l) {
                    if (in_array($l->account_id, $cashAccounts, true)) {
                        $cashDebit = bcadd($cashDebit, (string) $l->debit_amount, 2);
                        $cashCredit = bcadd($cashCredit, (string) $l->credit_amount, 2);
                    }
                }

                $netCashForEntry = bcsub($cashDebit, $cashCredit, 2);
                if (bccomp($netCashForEntry, '0.00', 2) === 0) {
                    continue; // Transfer antar-kas internal
                }

                // Pengalokasian berdasarkan lawan akun non-kas
                foreach ($entryLines as $l) {
                    if (in_array($l->account_id, $cashAccounts, true)) {
                        continue;
                    }

                    $debit = (string) $l->debit_amount;
                    $credit = (string) $l->credit_amount;

                    $accType = $l->account_type;
                    $accSubtype = $l->account_subtype;
                    $accCode = $l->account_code;

                    // Arus Kas Masuk (Lawan akun berada di sisi Kredit)
                    if (bccomp($credit, '0.00', 2) > 0) {
                        if ($accType === 'EQUITY') {
                            $financingInflow = bcadd($financingInflow, $credit, 2);
                        } elseif ($accSubtype === 'FIXED_ASSET' || str_starts_with($accCode, '15')) {
                            $investingInflow = bcadd($investingInflow, $credit, 2);
                        } else {
                            $operatingInflow = bcadd($operatingInflow, $credit, 2);
                        }
                    }

                    // Arus Kas Keluar (Lawan akun berada di sisi Debit)
                    if (bccomp($debit, '0.00', 2) > 0) {
                        if ($accType === 'EQUITY') {
                            $financingOutflow = bcadd($financingOutflow, $debit, 2);
                        } elseif ($accSubtype === 'FIXED_ASSET' || str_starts_with($accCode, '15')) {
                            $investingOutflow = bcadd($investingOutflow, $debit, 2);
                        } else {
                            $operatingOutflow = bcadd($operatingOutflow, $debit, 2);
                        }
                    }
                }
            }
        }

        $netOperating = bcsub($operatingInflow, $operatingOutflow, 2);
        $netInvesting = bcsub($investingInflow, $investingOutflow, 2);
        $netFinancing = bcsub($financingInflow, $financingOutflow, 2);

        $calculatedNetCashFlow = bcadd(bcadd($netOperating, $netInvesting, 2), $netFinancing, 2);
        $reconciliationDiff = bcsub($calculatedNetCashFlow, $actualNetChange, 2);
        $isReconciled = bccomp($reconciliationDiff, '0.00', 2) === 0;

        return [
            'metadata' => [
                'report_name' => 'Cash Flow Statement',
                'period_id' => $period->id,
                'period_code' => $period->period_code,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'store_id' => $storeId,
                'tenant_id' => $tenantId,
                'generated_at' => now()->toIso8601String(),
            ],
            'beginning_cash' => $beginningCash,
            'operating_activities' => [
                'inflows' => $operatingInflow,
                'outflows' => $operatingOutflow,
                'net' => $netOperating,
            ],
            'investing_activities' => [
                'inflows' => $investingInflow,
                'outflows' => $investingOutflow,
                'net' => $netInvesting,
            ],
            'financing_activities' => [
                'inflows' => $financingInflow,
                'outflows' => $financingOutflow,
                'net' => $netFinancing,
            ],
            'net_cash_flow' => $calculatedNetCashFlow,
            'ending_cash' => $endingCash,
            'is_reconciled' => $isReconciled,
            'difference' => $reconciliationDiff,
        ];
    }

    /**
     * Membangun ulang (rebuild) saldo account_period_balances secara matematis deterministik dari journal_lines.
     */
    public function rebuildPeriodBalances(int $periodId, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->get();

        $prevPeriod = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('end_date', '<', $period->start_date->toDateString())
            ->where('is_adjustment_period', false)
            ->orderByDesc('end_date')
            ->first();

        // Rebuild untuk Consolidated (store_scope_key = 0)
        foreach ($accounts as $account) {
            $openingDebit = '0.00';
            $openingCredit = '0.00';

            if ($prevPeriod) {
                $prevBalance = AccountPeriodBalance::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('accounting_period_id', $prevPeriod->id)
                    ->where('account_id', $account->id)
                    ->where('store_scope_key', 0)
                    ->first();

                if ($prevBalance) {
                    $isPermanent = in_array($account->account_type, [
                        AccountTypeEnum::ASSET,
                        AccountTypeEnum::LIABILITY,
                        AccountTypeEnum::EQUITY,
                    ], true);

                    if ($prevPeriod->fiscal_year !== $period->fiscal_year && ! $isPermanent) {
                        $openingDebit = '0.00';
                        $openingCredit = '0.00';
                    } else {
                        $openingDebit = (string) $prevBalance->closing_debit;
                        $openingCredit = (string) $prevBalance->closing_credit;
                    }
                }
            }

            $movements = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_entries.posting_date', '>=', $period->start_date->toDateString())
                ->where('journal_entries.posting_date', '<=', $period->end_date->toDateString())
                ->where('journal_lines.account_id', $account->id)
                ->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as period_debit, COALESCE(SUM(journal_lines.credit_amount), 0) as period_credit')
                ->first();

            $periodDebit = (string) ($movements->period_debit ?? '0.00');
            $periodCredit = (string) ($movements->period_credit ?? '0.00');
            $netMovement = bcsub($periodDebit, $periodCredit, 2);

            if ($account->normal_balance === NormalBalanceEnum::DEBIT) {
                $net = bcsub(bcadd($openingDebit, $periodDebit, 2), bcadd($openingCredit, $periodCredit, 2), 2);
                if (bccomp($net, '0.00', 2) >= 0) {
                    $closingDebit = $net;
                    $closingCredit = '0.00';
                } else {
                    $closingDebit = '0.00';
                    $closingCredit = bcmul($net, '-1', 2);
                }
            } else {
                $net = bcsub(bcadd($openingCredit, $periodCredit, 2), bcadd($openingDebit, $periodDebit, 2), 2);
                if (bccomp($net, '0.00', 2) >= 0) {
                    $closingCredit = $net;
                    $closingDebit = '0.00';
                } else {
                    $closingCredit = '0.00';
                    $closingDebit = bcmul($net, '-1', 2);
                }
            }

            AccountPeriodBalance::withoutTenantScope()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $period->id,
                    'account_id' => $account->id,
                    'store_scope_key' => 0,
                ],
                [
                    'store_id' => null,
                    'opening_debit' => $openingDebit,
                    'opening_credit' => $openingCredit,
                    'period_debit' => $periodDebit,
                    'period_credit' => $periodCredit,
                    'closing_debit' => $closingDebit,
                    'closing_credit' => $closingCredit,
                    'net_movement' => $netMovement,
                ]
            );
        }
    }
}
