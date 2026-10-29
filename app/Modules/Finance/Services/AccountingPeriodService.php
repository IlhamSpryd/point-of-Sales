<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Exceptions\ClosedAccountingPeriodException;
use App\Modules\Finance\Exceptions\ReconciliationGateFailedException;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PeriodClosingChecklist;
use App\Modules\Finance\Models\PeriodClosingLog;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingPeriodService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function createFiscalYearPeriods(int $fiscalYear, int $startMonth = 1, ?int $tenantId = null): Collection
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($fiscalYear, $startMonth, $tenantId) {
            $periods = new Collection;

            for ($i = 0; $i < 12; $i++) {
                $month = (($startMonth - 1 + $i) % 12) + 1;
                $year = $fiscalYear + (int) floor(($startMonth - 1 + $i) / 12);

                $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
                $periodNumber = $i + 1;
                $periodCode = sprintf('%04d-%02d', $fiscalYear, $periodNumber);

                $period = AccountingPeriod::withoutTenantScope()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'period_code' => $periodCode,
                    ],
                    [
                        'fiscal_year' => $fiscalYear,
                        'period_number' => $periodNumber,
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'status' => AccountingPeriodStatusEnum::OPEN,
                        'is_adjustment_period' => false,
                    ]
                );

                $periods->push($period);
            }

            return $periods;
        });
    }

    public function resolvePeriodForDate(string|CarbonInterface $date, ?int $tenantId = null): AccountingPeriod
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->where('is_adjustment_period', false)
            ->first();

        if (! $period) {
            throw ClosedAccountingPeriodException::forDate($dateStr);
        }

        return $period;
    }

    public function resolveOpenPeriodForDate(string|CarbonInterface $date, ?int $tenantId = null): AccountingPeriod
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $period = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->where('is_adjustment_period', false)
            ->first();

        if (! $period) {
            throw ClosedAccountingPeriodException::forDate($dateStr);
        }

        if (! $period->isPostingAllowed()) {
            throw ClosedAccountingPeriodException::forPeriod($period->period_code, $period->status->value);
        }

        return $period;
    }

    /**
     * Mengevaluasi 7 gerbang rekonsiliasi otomatis untuk periode akuntansi tertentu.
     *
     * @return array<string, array<string, mixed>>
     */
    public function evaluateClosingGates(int|AccountingPeriod $period, ?User $actor = null): array
    {
        $period = $period instanceof AccountingPeriod ? $period : AccountingPeriod::withoutTenantScope()->findOrFail($period);
        $tenantId = $period->tenant_id;
        $startDate = $period->start_date->toDateString();
        $endDate = $period->end_date->toDateString();
        $startDateTime = $period->start_date->copy()->startOfDay()->toDateTimeString();
        $endDateTime = $period->end_date->copy()->endOfDay()->toDateTimeString();

        $results = [];

        // Gate 1: Zero Pending / Failed Outbox Events
        $feoCount = DB::table('financial_event_outboxes')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['PENDING', 'FAILED', 'PROCESSING'])
            ->where('occurred_at', '<=', $endDateTime)
            ->count();

        $apoCount = 0;
        if (Schema::hasTable('accounting_payable_outbox')) {
            $apoCount = DB::table('accounting_payable_outbox')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<=', $endDateTime)
                ->count();
        }

        $aroCount = 0;
        if (Schema::hasTable('accounting_receivable_outboxes')) {
            $aroCount = DB::table('accounting_receivable_outboxes')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<=', $endDateTime)
                ->count();
        }

        $totalUnprocessed = $feoCount + $apoCount + $aroCount;
        $gate1Passed = $totalUnprocessed === 0;
        $results['GATE_1_OUTBOX'] = [
            'gate_code' => 'GATE_1_OUTBOX',
            'gate_name' => 'Zero Pending/Failed Outbox Events',
            'status' => $gate1Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => (string) $totalUnprocessed,
            'diagnostics' => [
                'financial_event_outbox_pending' => $feoCount,
                'ap_outbox_pending' => $apoCount,
                'ar_outbox_pending' => $aroCount,
                'total_pending' => $totalUnprocessed,
            ],
        ];

        // Gate 2: No Draft Journals
        $draftCount = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['DRAFT', 'PENDING_APPROVAL'])
            ->where('posting_date', '<=', $endDate)
            ->count();

        $gate2Passed = $draftCount === 0;
        $results['GATE_2_DRAFT_JOURNALS'] = [
            'gate_code' => 'GATE_2_DRAFT_JOURNALS',
            'gate_name' => 'Zero Draft/Unapproved Journals',
            'status' => $gate2Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => (string) $draftCount,
            'diagnostics' => [
                'draft_journal_count' => $draftCount,
            ],
        ];

        // Gate 3: Trial Balance Equilibrium (SUM(debits) === SUM(credits))
        $tbTotals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'POSTED')
            ->where('journal_entries.posting_date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_amount), 0) as total_debit, COALESCE(SUM(journal_lines.credit_amount), 0) as total_credit')
            ->first();

        $tbDebit = (string) ($tbTotals->total_debit ?? '0.00');
        $tbCredit = (string) ($tbTotals->total_credit ?? '0.00');
        $tbDiff = bcsub($tbDebit, $tbCredit, 2);
        $gate3Passed = bccomp($tbDiff, '0.00', 2) === 0;

        $results['GATE_3_TRIAL_BALANCE'] = [
            'gate_code' => 'GATE_3_TRIAL_BALANCE',
            'gate_name' => 'Trial Balance Equilibrium',
            'status' => $gate3Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => $tbDiff,
            'diagnostics' => [
                'total_debit' => $tbDebit,
                'total_credit' => $tbCredit,
                'difference' => $tbDiff,
            ],
        ];

        // Gate 4: AR Subledger == GL AR Control (Account 1130)
        $glArRaw = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'POSTED')
            ->where('journal_entries.posting_date', '<=', $endDate)
            ->where(function ($q) {
                $q->where('chart_of_accounts.account_code', '1130')
                    ->orWhere('chart_of_accounts.system_account_key', 'AR_CONTROL');
            })
            ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) as net_ar')
            ->value('net_ar') ?? '0.00';
        $glArBalance = bcadd((string) $glArRaw, '0', 2);

        $subledgerAr = '0.00';
        if (Schema::hasTable('customer_invoices')) {
            $invSum = DB::table('customer_invoices')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])
                ->where('invoice_date', '<=', $endDate)
                ->sum('balance_due');
            $subledgerAr = bcadd((string) ($invSum ?? '0.00'), '0', 2);
        }

        $arDiff = bcsub($glArBalance, $subledgerAr, 2);
        $gate4Passed = bccomp($arDiff, '0.00', 2) === 0;

        $results['GATE_4_AR_SUBLEDGER'] = [
            'gate_code' => 'GATE_4_AR_SUBLEDGER',
            'gate_name' => 'AR Subledger to GL Control Reconciliation',
            'status' => $gate4Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => $arDiff,
            'diagnostics' => [
                'gl_ar_balance' => $glArBalance,
                'subledger_ar_balance' => $subledgerAr,
                'difference' => $arDiff,
            ],
        ];

        // Gate 5: AP Subledger == GL AP Control (Account 2101)
        $glApRaw = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'POSTED')
            ->where('journal_entries.posting_date', '<=', $endDate)
            ->where(function ($q) {
                $q->where('chart_of_accounts.account_code', '2101')
                    ->orWhere('chart_of_accounts.system_account_key', 'AP_CONTROL');
            })
            ->selectRaw('COALESCE(SUM(journal_lines.credit_amount - journal_lines.debit_amount), 0) as net_ap')
            ->value('net_ap') ?? '0.00';
        $glApBalance = bcadd((string) $glApRaw, '0', 2);

        $subledgerAp = '0.00';
        if (Schema::hasTable('supplier_invoices')) {
            $invSum = DB::table('supplier_invoices')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['approved_payable', 'approved', 'partially_paid'])
                ->where('invoice_date', '<=', $endDate)
                ->sum('outstanding_amount');
            $subledgerAp = bcadd((string) ($invSum ?? '0.00'), '0', 2);
        }

        $apDiff = bcsub($glApBalance, $subledgerAp, 2);
        $gate5Passed = bccomp($apDiff, '0.00', 2) === 0;

        $results['GATE_5_AP_SUBLEDGER'] = [
            'gate_code' => 'GATE_5_AP_SUBLEDGER',
            'gate_name' => 'AP Subledger to GL Control Reconciliation',
            'status' => $gate5Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => $apDiff,
            'diagnostics' => [
                'gl_ap_balance' => $glApBalance,
                'subledger_ap_balance' => $subledgerAp,
                'difference' => $apDiff,
            ],
        ];

        // Gate 6: Inventory Subledger == GL Inventory Control (Accounts 1140, 1141)
        $glInvRaw = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', 'POSTED')
            ->where('journal_entries.posting_date', '<=', $endDate)
            ->whereIn('chart_of_accounts.account_code', ['1140', '1141'])
            ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) as net_inv')
            ->value('net_inv') ?? '0.00';
        $glInvBalance = bcadd((string) $glInvRaw, '0', 2);

        $subledgerInv = '0.00';
        if (Schema::hasTable('inventory_valuation_snapshots')) {
            $snapshotSum = DB::table('inventory_valuation_snapshots')
                ->where('tenant_id', $tenantId)
                ->where('accounting_period_id', $period->id)
                ->sum('total_valuation_amount');
            if ($snapshotSum !== null && bccomp((string) $snapshotSum, '0.00', 2) > 0) {
                $subledgerInv = bcadd((string) $snapshotSum, '0', 2);
            }
        }

        if (bccomp($subledgerInv, '0.00', 2) === 0) {
            if (Schema::hasTable('product_stock_balances')) {
                $prodSum = DB::table('product_stock_balances')
                    ->where('tenant_id', $tenantId)
                    ->selectRaw('COALESCE(SUM(quantity * average_unit_cost), 0) as total')
                    ->value('total') ?? '0.00';
                $subledgerInv = bcadd($subledgerInv, (string) $prodSum, 2);
            }
            if (Schema::hasTable('ingredient_stock_balances')) {
                $ingSum = DB::table('ingredient_stock_balances')
                    ->where('tenant_id', $tenantId)
                    ->selectRaw('COALESCE(SUM(quantity * average_unit_cost), 0) as total')
                    ->value('total') ?? '0.00';
                $subledgerInv = bcadd($subledgerInv, (string) $ingSum, 2);
            }
        }

        $invDiff = bcsub($glInvBalance, $subledgerInv, 2);
        $gate6Passed = bccomp($invDiff, '0.00', 2) === 0;

        $results['GATE_6_INVENTORY_SUBLEDGER'] = [
            'gate_code' => 'GATE_6_INVENTORY_SUBLEDGER',
            'gate_name' => 'Inventory Subledger to GL Control Reconciliation',
            'status' => $gate6Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => $invDiff,
            'diagnostics' => [
                'gl_inv_balance' => $glInvBalance,
                'subledger_inv_balance' => $subledgerInv,
                'difference' => $invDiff,
            ],
        ];

        // Gate 7: Fixed Asset Depreciation Posted
        $pendingSchedules = 0;
        if (Schema::hasTable('fixed_asset_depreciation_schedules')) {
            $pendingSchedules = DB::table('fixed_asset_depreciation_schedules')
                ->join('fixed_assets', 'fixed_assets.id', '=', 'fixed_asset_depreciation_schedules.fixed_asset_id')
                ->where('fixed_assets.tenant_id', $tenantId)
                ->where('fixed_assets.status', 'ACTIVE')
                ->where('fixed_asset_depreciation_schedules.status', 'PENDING')
                ->where(function ($q) use ($period) {
                    $q->where('fixed_asset_depreciation_schedules.accounting_period_id', $period->id)
                        ->orWhereRaw('(fixed_asset_depreciation_schedules.period_year < ? OR (fixed_asset_depreciation_schedules.period_year = ? AND fixed_asset_depreciation_schedules.period_month <= ?))', [
                            $period->fiscal_year,
                            $period->fiscal_year,
                            $period->period_number,
                        ]);
                })
                ->count();
        }

        $gate7Passed = $pendingSchedules === 0;
        $results['GATE_7_DEPRECIATION'] = [
            'gate_code' => 'GATE_7_DEPRECIATION',
            'gate_name' => 'Fixed Asset Depreciation Posted',
            'status' => $gate7Passed ? 'PASSED' : 'FAILED',
            'discrepancy_amount' => (string) $pendingSchedules,
            'diagnostics' => [
                'pending_schedule_count' => $pendingSchedules,
            ],
        ];

        // Persist checklists
        foreach ($results as $gate) {
            PeriodClosingChecklist::withoutTenantScope()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $period->id,
                    'gate_code' => $gate['gate_code'],
                ],
                [
                    'gate_name' => $gate['gate_name'],
                    'status' => $gate['status'],
                    'discrepancy_amount' => $gate['discrepancy_amount'],
                    'diagnostics_json' => $gate['diagnostics'],
                    'checked_at' => now(),
                    'checked_by_user_id' => $actor?->id,
                ]
            );
        }

        return $results;
    }

    public function closePeriod(int|AccountingPeriod $period, User $actor): AccountingPeriod
    {
        $period = $period instanceof AccountingPeriod ? $period : AccountingPeriod::withoutTenantScope()->findOrFail($period);
        $tenantId = $period->tenant_id;

        $gates = $this->evaluateClosingGates($period, $actor);

        $failedGates = array_filter($gates, fn ($g) => $g['status'] === 'FAILED');
        if (! empty($failedGates)) {
            throw ReconciliationGateFailedException::withFailedGates($period->period_code, $failedGates);
        }

        return DB::transaction(function () use ($period, $actor, $tenantId) {
            $lockedPeriod = AccountingPeriod::withoutTenantScope()
                ->where('id', $period->id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $prevStatus = $lockedPeriod->status->value;
            $lockedPeriod->status = AccountingPeriodStatusEnum::CLOSED;
            $lockedPeriod->closed_at = now();
            $lockedPeriod->closed_by_user_id = $actor->id;
            $lockedPeriod->save();

            // 1. Finalize account_period_balances
            $this->finalizeAccountPeriodBalances($lockedPeriod);

            // 2. Snapshot inventory
            $this->snapshotInventoryValuation($lockedPeriod);

            // 3. Log
            PeriodClosingLog::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $lockedPeriod->id,
                'action' => 'HARD_CLOSE',
                'user_id' => $actor->id,
                'reason' => "All 7 reconciliation gates passed. Period {$lockedPeriod->period_code} successfully closed.",
                'previous_status' => $prevStatus,
                'new_status' => AccountingPeriodStatusEnum::CLOSED->value,
            ]);

            return $lockedPeriod;
        });
    }

    public function softClosePeriod(int $periodId, User $actor): AccountingPeriod
    {
        return $this->transitionPeriodStatus($periodId, AccountingPeriodStatusEnum::SOFT_CLOSED, 'SOFT_CLOSE', $actor);
    }

    public function hardClosePeriod(int $periodId, User $actor): AccountingPeriod
    {
        return $this->closePeriod($periodId, $actor);
    }

    public function lockPeriod(int $periodId, User $actor): AccountingPeriod
    {
        return $this->transitionPeriodStatus($periodId, AccountingPeriodStatusEnum::LOCKED, 'LOCK', $actor);
    }

    public function reopenPeriod(int $periodId, string $reason, User $actor): AccountingPeriod
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        if (mb_strlen(trim($reason)) < 20) {
            throw new DomainException('FIN-M7-08: Alasan pembukaan kembali periode wajib minimal 20 karakter.');
        }

        return DB::transaction(function () use ($periodId, $reason, $actor, $tenantId) {
            $period = AccountingPeriod::where('id', $periodId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->status === AccountingPeriodStatusEnum::LOCKED) {
                throw new DomainException('FIN-M7-08: Periode berstatus LOCKED tidak dapat dibuka kembali.');
            }

            $prevStatus = $period->status->value;
            $period->status = AccountingPeriodStatusEnum::OPEN;
            $period->reopened_at = now();
            $period->reopened_by_user_id = $actor->id;
            $period->reopen_reason = $reason;
            $period->save();

            PeriodClosingLog::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $period->id,
                'action' => 'REOPEN',
                'user_id' => $actor->id,
                'reason' => $reason,
                'previous_status' => $prevStatus,
                'new_status' => AccountingPeriodStatusEnum::OPEN->value,
            ]);

            return $period;
        });
    }

    /**
     * Menjalankan penutupan akhir tahun fiskal (Year-End Close & Retained Earnings Roll-Forward).
     *
     * @return array{
     *     fiscal_year: int,
     *     closing_journal: JournalEntry,
     *     retained_earnings_amount: string,
     *     next_fiscal_year: int
     * }
     */
    public function runYearEndClose(int $fiscalYear, User $actor, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($fiscalYear, $actor, $tenantId) {
            $periods = AccountingPeriod::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year', $fiscalYear)
                ->where('is_adjustment_period', false)
                ->orderBy('period_number')
                ->get();

            if ($periods->count() < 12) {
                throw new DomainException("FIN-M7-12: Tutup tahun fiskal gagal: Tahun fiskal {$fiscalYear} belum memiliki 12 periode lengkap.");
            }

            foreach ($periods as $p) {
                if (! in_array($p->status, [AccountingPeriodStatusEnum::CLOSED, AccountingPeriodStatusEnum::LOCKED], true)) {
                    throw new DomainException("FIN-M7-12: Tutup tahun fiskal gagal: Periode {$p->period_code} belum berstatus CLOSED atau LOCKED.");
                }
            }

            $lastPeriod = $periods->last();
            $idempotencyKey = "year_end_close:{$tenantId}:{$fiscalYear}";

            $existingJournal = JournalEntry::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingJournal) {
                return [
                    'fiscal_year' => $fiscalYear,
                    'closing_journal' => $existingJournal,
                    'retained_earnings_amount' => (string) $existingJournal->total_debit,
                    'next_fiscal_year' => $fiscalYear + 1,
                ];
            }

            // Temukan akun RETAINED_EARNINGS (Kode 3200)
            $retainedEarningsAccount = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->where('account_code', '3200')
                        ->orWhere('system_account_key', 'RETAINED_EARNINGS');
                })
                ->first();

            if (! $retainedEarningsAccount) {
                throw new DomainException('FIN-M7-12: Akun Retained Earnings (3200) tidak ditemukan.');
            }

            // Ambil semua akun nominal (REVENUE, COGS, EXPENSE, OTHER_INCOME, OTHER_EXPENSE)
            $nominalTypes = [
                AccountTypeEnum::REVENUE->value,
                AccountTypeEnum::COGS->value,
                AccountTypeEnum::EXPENSE->value,
                AccountTypeEnum::OTHER_INCOME->value,
                AccountTypeEnum::OTHER_EXPENSE->value,
            ];

            $nominalMovements = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', 'POSTED')
                ->where('journal_entries.posting_date', '>=', $periods->first()->start_date->toDateString())
                ->where('journal_entries.posting_date', '<=', $lastPeriod->end_date->toDateString())
                ->whereIn('chart_of_accounts.account_type', $nominalTypes)
                ->groupBy('journal_lines.account_id', 'chart_of_accounts.account_code', 'chart_of_accounts.normal_balance')
                ->selectRaw('
                    journal_lines.account_id,
                    chart_of_accounts.account_code,
                    chart_of_accounts.normal_balance,
                    COALESCE(SUM(journal_lines.debit_amount), 0) as total_debit,
                    COALESCE(SUM(journal_lines.credit_amount), 0) as total_credit
                ')
                ->get();

            $closingLines = [];
            $totalClosingDebits = '0.00';
            $totalClosingCredits = '0.00';

            foreach ($nominalMovements as $mov) {
                $deb = (string) $mov->total_debit;
                $cred = (string) $mov->total_credit;
                $normalBalance = $mov->normal_balance;

                if ($normalBalance === NormalBalanceEnum::DEBIT->value) {
                    $net = bcsub($deb, $cred, 2);
                    if (bccomp($net, '0.00', 2) > 0) {
                        // Credit to zero out
                        $closingLines[] = new JournalLineDto(
                            accountId: (int) $mov->account_id,
                            debitAmount: '0.00',
                            creditAmount: $net,
                            description: "Closing nominal account {$mov->account_code} to Retained Earnings"
                        );
                        $totalClosingCredits = bcadd($totalClosingCredits, $net, 2);
                    } elseif (bccomp($net, '0.00', 2) < 0) {
                        $absNet = bcmul($net, '-1', 2);
                        $closingLines[] = new JournalLineDto(
                            accountId: (int) $mov->account_id,
                            debitAmount: $absNet,
                            creditAmount: '0.00',
                            description: "Closing nominal account {$mov->account_code} to Retained Earnings"
                        );
                        $totalClosingDebits = bcadd($totalClosingDebits, $absNet, 2);
                    }
                } else {
                    $net = bcsub($cred, $deb, 2);
                    if (bccomp($net, '0.00', 2) > 0) {
                        // Debit to zero out
                        $closingLines[] = new JournalLineDto(
                            accountId: (int) $mov->account_id,
                            debitAmount: $net,
                            creditAmount: '0.00',
                            description: "Closing nominal account {$mov->account_code} to Retained Earnings"
                        );
                        $totalClosingDebits = bcadd($totalClosingDebits, $net, 2);
                    } elseif (bccomp($net, '0.00', 2) < 0) {
                        $absNet = bcmul($net, '-1', 2);
                        $closingLines[] = new JournalLineDto(
                            accountId: (int) $mov->account_id,
                            debitAmount: '0.00',
                            creditAmount: $absNet,
                            description: "Closing nominal account {$mov->account_code} to Retained Earnings"
                        );
                        $totalClosingCredits = bcadd($totalClosingCredits, $absNet, 2);
                    }
                }
            }

            // Offset ke RETAINED_EARNINGS (3200)
            $retainedEarningsDiff = bcsub($totalClosingDebits, $totalClosingCredits, 2);
            if (bccomp($retainedEarningsDiff, '0.00', 2) > 0) {
                // Profit: Net credits needed to balance closing debits
                $closingLines[] = new JournalLineDto(
                    accountId: $retainedEarningsAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $retainedEarningsDiff,
                    description: "Net Profit transfer to Retained Earnings for FY {$fiscalYear}"
                );
                $totalClosingCredits = bcadd($totalClosingCredits, $retainedEarningsDiff, 2);
            } elseif (bccomp($retainedEarningsDiff, '0.00', 2) < 0) {
                // Loss: Net debits needed to balance closing credits
                $absDiff = bcmul($retainedEarningsDiff, '-1', 2);
                $closingLines[] = new JournalLineDto(
                    accountId: $retainedEarningsAccount->id,
                    debitAmount: $absDiff,
                    creditAmount: '0.00',
                    description: "Net Loss transfer to Retained Earnings for FY {$fiscalYear}"
                );
                $totalClosingDebits = bcadd($totalClosingDebits, $absDiff, 2);
            }

            if (empty($closingLines)) {
                // Bila belum ada mutasi nominal sama sekali, tambahkan no-op dummy line 0.00 atau lewati pembuatan jurnal
                $closingLines[] = new JournalLineDto(
                    accountId: $retainedEarningsAccount->id,
                    debitAmount: '0.00',
                    creditAmount: '0.00',
                    description: "No nominal balance to close for FY {$fiscalYear}"
                );
            }

            /** @var JournalEntryService $journalService */
            $journalService = app(JournalEntryService::class);
            $closingJournal = $journalService->createAndPostJournal(
                tenantId: $tenantId,
                storeId: null,
                postingDate: $lastPeriod->end_date,
                idempotencyKey: $idempotencyKey,
                lines: $closingLines,
                sourceModule: 'FINANCE',
                sourceType: 'YEAR_END_CLOSE',
                sourceId: $fiscalYear,
                sourceNumber: "YEC-{$fiscalYear}",
                journalType: JournalTypeEnum::CLOSING_ENTRY,
                notes: "Year-End Closing Entry for Fiscal Year {$fiscalYear}",
                actor: $actor
            );

            // Re-finalize balances periode 12 agar mencakup closing entry
            $this->finalizeAccountPeriodBalances($lastPeriod);

            // Roll-forward saldo akun permanen ke periode 1 tahun fiskal berikutnya
            $nextFiscalYear = $fiscalYear + 1;
            $nextPeriods = $this->createFiscalYearPeriods($nextFiscalYear, 1, $tenantId);
            $nextPeriod1 = $nextPeriods->firstWhere('period_number', 1);

            $this->rollForwardPermanentAccounts($lastPeriod, $nextPeriod1);

            PeriodClosingLog::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $lastPeriod->id,
                'action' => 'YEAR_END_CLOSE',
                'user_id' => $actor->id,
                'reason' => "Year-end closing completed for fiscal year {$fiscalYear}. Balances rolled forward to {$nextFiscalYear}.",
                'previous_status' => $lastPeriod->status->value,
                'new_status' => $lastPeriod->status->value,
            ]);

            return [
                'fiscal_year' => $fiscalYear,
                'closing_journal' => $closingJournal,
                'retained_earnings_amount' => $retainedEarningsDiff,
                'next_fiscal_year' => $nextFiscalYear,
            ];
        });
    }

    public function finalizeAccountPeriodBalances(AccountingPeriod $period): void
    {
        $tenantId = $period->tenant_id;
        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_postable', true)
            ->get();

        $prevPeriod = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', '<', $period->id)
            ->where('is_adjustment_period', false)
            ->orderBy('id', 'desc')
            ->first();

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

                    // Akun nominal di-reset 0 di awal tahun fiskal baru
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

    public function snapshotInventoryValuation(AccountingPeriod $period): void
    {
        $tenantId = $period->tenant_id;
        if (! Schema::hasTable('inventory_valuation_snapshots')) {
            return;
        }

        $exists = DB::table('inventory_valuation_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('accounting_period_id', $period->id)
            ->exists();

        if ($exists) {
            return;
        }

        if (Schema::hasTable('product_stock_balances')) {
            $productBalances = DB::table('product_stock_balances')
                ->where('tenant_id', $tenantId)
                ->select([
                    'store_id',
                    'product_id',
                    'quantity',
                    'average_unit_cost',
                ])
                ->get();

            foreach ($productBalances as $pb) {
                $unitCost = (string) ($pb->average_unit_cost ?? '0.0000');
                $qty = (string) $pb->quantity;
                $totalValuation = bcmul($qty, $unitCost, 2);

                DB::table('inventory_valuation_snapshots')->insert([
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $period->id,
                    'store_id' => $pb->store_id,
                    'snapshot_date' => $period->end_date->toDateString(),
                    'item_type' => 'PRODUCT',
                    'item_id' => $pb->product_id,
                    'closing_qty' => $qty,
                    'average_unit_cost' => $unitCost,
                    'total_valuation_amount' => $totalValuation,
                    'reconciliation_status' => 'MATCHED',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    protected function rollForwardPermanentAccounts(AccountingPeriod $sourcePeriod, AccountingPeriod $targetPeriod): void
    {
        $tenantId = $sourcePeriod->tenant_id;
        $balances = AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('accounting_period_id', $sourcePeriod->id)
            ->where('store_scope_key', 0)
            ->get();

        foreach ($balances as $b) {
            $account = ChartOfAccount::withoutTenantScope()->find($b->account_id);
            if (! $account) {
                continue;
            }

            $isPermanent = in_array($account->account_type, [
                AccountTypeEnum::ASSET,
                AccountTypeEnum::LIABILITY,
                AccountTypeEnum::EQUITY,
            ], true);

            if (! $isPermanent) {
                continue;
            }

            AccountPeriodBalance::withoutTenantScope()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $targetPeriod->id,
                    'account_id' => $account->id,
                    'store_scope_key' => 0,
                ],
                [
                    'store_id' => null,
                    'opening_debit' => $b->closing_debit,
                    'opening_credit' => $b->closing_credit,
                    'period_debit' => '0.00',
                    'period_credit' => '0.00',
                    'closing_debit' => $b->closing_debit,
                    'closing_credit' => $b->closing_credit,
                    'net_movement' => '0.00',
                ]
            );
        }
    }

    protected function transitionPeriodStatus(int $periodId, AccountingPeriodStatusEnum $newStatus, string $action, User $actor): AccountingPeriod
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($periodId, $newStatus, $action, $actor, $tenantId) {
            $period = AccountingPeriod::where('id', $periodId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $prevStatus = $period->status->value;
            $period->status = $newStatus;
            if ($newStatus === AccountingPeriodStatusEnum::CLOSED || $newStatus === AccountingPeriodStatusEnum::LOCKED) {
                $period->closed_at = now();
                $period->closed_by_user_id = $actor->id;
            }
            $period->save();

            PeriodClosingLog::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $period->id,
                'action' => $action,
                'user_id' => $actor->id,
                'reason' => "Transition to {$newStatus->value}",
                'previous_status' => $prevStatus,
                'new_status' => $newStatus->value,
            ]);

            return $period;
        });
    }
}
