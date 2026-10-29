<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PeriodClosingLog;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ControlledPeriodReopenAndYearEndCloseTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected AccountingPeriodService $periodService;

    protected JournalEntryService $journalService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->periodService = app(AccountingPeriodService::class);
        $this->journalService = app(JournalEntryService::class);

        $this->period = AccountingPeriod::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('m'),
            'status' => AccountingPeriodStatusEnum::OPEN,
            'is_adjustment_period' => false,
        ]);

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_controlled_reopening_requires_valid_reason_and_sets_open_status(): void
    {
        $this->periodService->closePeriod($this->period, $this->user);
        $this->assertSame(AccountingPeriodStatusEnum::CLOSED, $this->period->fresh()->status);

        $validReason = 'Pembukaan kembali untuk jurnal penyesuaian audit eksternal tahunan';
        $reopened = $this->periodService->reopenPeriod($this->period->id, $validReason, $this->user);

        $this->assertSame(AccountingPeriodStatusEnum::OPEN, $reopened->status);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertSame($this->user->id, $reopened->reopened_by_user_id);
        $this->assertSame($validReason, $reopened->reopen_reason);

        $log = PeriodClosingLog::where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $this->period->id)
            ->where('action', 'REOPEN')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(AccountingPeriodStatusEnum::OPEN->value, $log->new_status);
        $this->assertSame($validReason, $log->reason);
    }

    public function test_reopen_period_fails_if_reason_too_short(): void
    {
        $this->periodService->closePeriod($this->period, $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-08: Alasan pembukaan kembali periode wajib minimal 20 karakter.');

        $this->periodService->reopenPeriod($this->period->id, 'Alasan pendek', $this->user);
    }

    public function test_reopen_period_fails_if_period_is_locked(): void
    {
        $this->periodService->closePeriod($this->period, $this->user);
        $this->periodService->lockPeriod($this->period->id, $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-08: Periode berstatus LOCKED tidak dapat dibuka kembali.');

        $this->periodService->reopenPeriod(
            $this->period->id,
            'Alasan pembukaan kembali periode yang valid lebih dari dua puluh karakter',
            $this->user
        );
    }

    public function test_run_year_end_close_fails_if_not_all_12_periods_closed(): void
    {
        $fiscalYear = 2026;
        $periods = $this->periodService->createFiscalYearPeriods($fiscalYear, 1, $this->tenant->id);

        // Hanya tutup 11 periode
        for ($i = 0; $i < 11; $i++) {
            $this->periodService->closePeriod($periods[$i], $this->user);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("FIN-M7-12: Tutup tahun fiskal gagal: Periode {$periods[11]->period_code} belum berstatus CLOSED atau LOCKED.");

        $this->periodService->runYearEndClose($fiscalYear, $this->user, $this->tenant->id);
    }

    public function test_run_year_end_close_rolls_nominal_accounts_to_retained_earnings_and_sets_next_year_opening_balance(): void
    {
        $fiscalYear = 2026;
        $periods = $this->periodService->createFiscalYearPeriods($fiscalYear, 1, $this->tenant->id);

        $cashAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '1101')->first();
        $revenueAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '4101')->first();
        $expenseAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '6101')->first();
        $retainedEarningsAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('account_code', '3200')->first();

        // 1. Post Revenue Rp 10.000.000 pada periode 6 (Juni 2026)
        $p6 = $periods[5];
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $p6->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_revenue_fy2026',
            lines: [
                new JournalLineDto(accountId: $cashAccount->id, debitAmount: '10000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $revenueAccount->id, debitAmount: '0.00', creditAmount: '10000000.00'),
            ],
            notes: 'Revenue FY 2026'
        );

        // 2. Post Expense Rp 4.000.000 pada periode 9 (September 2026)
        $p9 = $periods[8];
        $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: $p9->start_date->copy()->addDays(5),
            idempotencyKey: 'idemp_expense_fy2026',
            lines: [
                new JournalLineDto(accountId: $expenseAccount->id, debitAmount: '4000000.00', creditAmount: '0.00'),
                new JournalLineDto(accountId: $cashAccount->id, debitAmount: '0.00', creditAmount: '4000000.00'),
            ],
            notes: 'Expense FY 2026'
        );

        // Tutup seluruh 12 periode
        foreach ($periods as $p) {
            $this->periodService->closePeriod($p, $this->user);
        }

        // 3. Jalankan Year-End Close
        $result = $this->periodService->runYearEndClose($fiscalYear, $this->user, $this->tenant->id);

        $this->assertSame($fiscalYear, $result['fiscal_year']);
        $this->assertSame($fiscalYear + 1, $result['next_fiscal_year']);

        /** @var JournalEntry $closingJournal */
        $closingJournal = $result['closing_journal'];
        $this->assertSame(JournalTypeEnum::CLOSING_ENTRY, $closingJournal->journal_type);
        $this->assertSame('YEAR_END_CLOSE', $closingJournal->source_type);

        // Net Profit = 10.000.000 - 4.000.000 = 6.000.000
        // Jurnal penutup:
        // Dr Pendapatan 10.000.000
        // Cr Beban 4.000.000
        // Cr Laba Ditahan 6.000.000
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $closingJournal->id,
            'account_id' => $revenueAccount->id,
            'debit_amount' => 10000000.00,
            'credit_amount' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $closingJournal->id,
            'account_id' => $expenseAccount->id,
            'debit_amount' => 0.00,
            'credit_amount' => 4000000.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $closingJournal->id,
            'account_id' => $retainedEarningsAccount->id,
            'debit_amount' => 0.00,
            'credit_amount' => 6000000.00,
        ]);

        // Verifikasi saldo awal tahun 2027 pada periode 1
        $period2027_01 = AccountingPeriod::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('fiscal_year', 2027)
            ->where('period_number', 1)
            ->first();

        $this->assertNotNull($period2027_01);

        $cashOpening2027 = AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $period2027_01->id)
            ->where('account_id', $cashAccount->id)
            ->first();

        $this->assertNotNull($cashOpening2027);
        $this->assertEquals(6000000.00, (float) $cashOpening2027->opening_debit);

        $retainedEarningsOpening2027 = AccountPeriodBalance::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('accounting_period_id', $period2027_01->id)
            ->where('account_id', $retainedEarningsAccount->id)
            ->first();

        $this->assertNotNull($retainedEarningsOpening2027);
        $this->assertEquals(6000000.00, (float) $retainedEarningsOpening2027->opening_credit);
    }

    public function test_reconcile_command_outputs_table_and_exits_successfully(): void
    {
        $exitCode = Artisan::call('finance:reconcile-ledgers', [
            'tenant' => $this->tenant->id,
            '--period' => $this->period->period_code,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('GATE_1_OUTBOX', $output);
        $this->assertStringContainsString('GATE_2_DRAFT_JOURNALS', $output);
        $this->assertStringContainsString('GATE_3_TRIAL_BALANCE', $output);
        $this->assertStringContainsString('GATE_4_AR_SUBLEDGER', $output);
        $this->assertStringContainsString('GATE_5_AP_SUBLEDGER', $output);
        $this->assertStringContainsString('GATE_6_INVENTORY_SUBLEDGER', $output);
        $this->assertStringContainsString('GATE_7_DEPRECIATION', $output);
        $this->assertStringContainsString('PASSED', $output);
    }
}
