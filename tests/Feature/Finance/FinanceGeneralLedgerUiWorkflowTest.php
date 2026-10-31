<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Livewire\Finance\AccountingPeriodManager;
use App\Livewire\Finance\BankReconciliationWorkbench;
use App\Livewire\Finance\ChartOfAccountManager;
use App\Livewire\Finance\JournalEntryManager;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\BankReconciliationSessionStatusEnum;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\BankReconciliationSession;
use App\Modules\Finance\Models\BankStatement;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\JournalEntry;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceGeneralLedgerUiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $financeUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $role = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->financeUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);
    }

    public function test_chart_of_accounts_ui_renders_and_creates_account(): void
    {
        $this->actingAs($this->financeUser);

        Livewire::test(ChartOfAccountManager::class)
            ->assertOk()
            ->call('openCreateModal')
            ->set('accountCode', '1105')
            ->set('accountName', 'Kas Operasional Outlet B')
            ->set('accountType', 'ASSET')
            ->set('normalBalance', 'DEBIT')
            ->set('isPostable', true)
            ->set('allowManualJournal', true)
            ->call('saveAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('chart_of_accounts', [
            'tenant_id' => $this->tenant->id,
            'account_code' => '1105',
            'account_name' => 'Kas Operasional Outlet B',
            'account_type' => AccountTypeEnum::ASSET->value,
            'is_active' => 1,
        ]);

        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '1105')
            ->firstOrFail();

        // Test editing account
        Livewire::test(ChartOfAccountManager::class)
            ->call('openEditModal', $account->id)
            ->set('accountName', 'Kas Operasional Outlet B (Updated)')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $account->id,
            'account_name' => 'Kas Operasional Outlet B (Updated)',
        ]);

        // Test toggling active status
        Livewire::test(ChartOfAccountManager::class)
            ->call('toggleActive', $account->id);

        $this->assertFalse((bool) $account->fresh()->is_active);
    }

    public function test_journal_entry_manager_validates_balancing_and_posts_manual_journal(): void
    {
        $this->actingAs($this->financeUser);

        // Ensure open accounting period for today
        AccountingPeriod::firstOrCreate(
            [
                'tenant_id' => $this->tenant->id,
                'period_code' => now()->format('Y-m'),
            ],
            [
                'fiscal_year' => (int) now()->format('Y'),
                'period_number' => (int) now()->format('m'),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'status' => AccountingPeriodStatusEnum::OPEN->value,
                'is_adjustment_period' => false,
            ]
        );

        $cashAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '1101')
            ->firstOrFail();

        $capitalAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '3100')
            ->firstOrFail();

        // 1. Unbalanced submission should fail
        Livewire::test(JournalEntryManager::class)
            ->assertOk()
            ->call('openWorkbenchModal')
            ->set('postingDate', now()->toDateString())
            ->set('notes', 'Setoran modal kas awal')
            ->set('lines', [
                ['account_id' => $cashAccount->id, 'debit_amount' => 500000.0, 'credit_amount' => 0.0, 'description' => 'Debit Kas'],
                ['account_id' => $capitalAccount->id, 'debit_amount' => 0.0, 'credit_amount' => 300000.0, 'description' => 'Credit Modal Unbalanced'],
            ])
            ->call('postManualJournal')
            ->assertHasErrors(['lines']);

        // 2. Balanced submission should succeed
        Livewire::test(JournalEntryManager::class)
            ->call('openWorkbenchModal')
            ->set('postingDate', now()->toDateString())
            ->set('notes', 'Setoran modal kas awal balanced')
            ->set('lines', [
                ['account_id' => $cashAccount->id, 'debit_amount' => 500000.0, 'credit_amount' => 0.0, 'description' => 'Debit Kas'],
                ['account_id' => $capitalAccount->id, 'debit_amount' => 0.0, 'credit_amount' => 500000.0, 'description' => 'Credit Modal'],
            ])
            ->call('postManualJournal')
            ->assertHasNoErrors();

        /** @var JournalEntry $postedJournal */
        $postedJournal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('notes', 'Setoran modal kas awal balanced')
            ->firstOrFail();

        $this->assertEquals(JournalStatusEnum::POSTED, $postedJournal->status);
        $this->assertEquals('500000.00', $postedJournal->total_debit);
        $this->assertEquals('500000.00', $postedJournal->total_credit);

        // 3. Test reversing the journal
        Livewire::test(JournalEntryManager::class)
            ->call('openReversalModal', $postedJournal->id)
            ->set('reversalReason', 'Koreksi salah nominal modal')
            ->call('confirmReversal')
            ->assertHasNoErrors();

        $this->assertEquals(JournalStatusEnum::REVERSED, $postedJournal->fresh()->status);
        $this->assertNotNull($postedJournal->fresh()->reversed_by_journal_id);
    }

    public function test_accounting_period_manager_creates_and_transitions_periods(): void
    {
        $this->actingAs($this->financeUser);

        // Delete existing 2026 periods to test generator cleanly
        AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('fiscal_year', 2026)
            ->delete();

        // 1. Generate 12 fiscal periods
        Livewire::test(AccountingPeriodManager::class)
            ->assertOk()
            ->set('selectedFiscalYear', 2026)
            ->call('generateFiscalYearPeriods');

        $this->assertEquals(
            12,
            AccountingPeriod::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->id)
                ->where('fiscal_year', 2026)
                ->count()
        );

        /** @var AccountingPeriod $period */
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('period_code', '2026-01')
            ->firstOrFail();

        $this->assertEquals(AccountingPeriodStatusEnum::OPEN, $period->status);

        // 2. Soft close
        Livewire::test(AccountingPeriodManager::class)
            ->set('selectedFiscalYear', 2026)
            ->call('softClose', $period->id);

        $this->assertEquals(AccountingPeriodStatusEnum::SOFT_CLOSED, $period->fresh()->status);

        // 3. Reopen
        Livewire::test(AccountingPeriodManager::class)
            ->set('selectedFiscalYear', 2026)
            ->call('openReopenModal', $period->id)
            ->set('reopenReason', 'Pembukaan kembali untuk transaksi terlambat')
            ->call('confirmReopen')
            ->assertHasNoErrors();

        $this->assertEquals(AccountingPeriodStatusEnum::OPEN, $period->fresh()->status);

        // 4. Evaluate gates
        Livewire::test(AccountingPeriodManager::class)
            ->set('selectedFiscalYear', 2026)
            ->call('evaluateGates', $period->id)
            ->assertSet('showGatesModal', true);
    }

    public function test_bank_reconciliation_workbench_matches_and_calculates_difference(): void
    {
        $this->actingAs($this->financeUser);

        $cashAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('account_code', '1101')
            ->firstOrFail();

        /** @var CashBankAccount $bankAccount */
        $bankAccount = CashBankAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-001',
            'account_name' => 'BCA Rekening Operasional',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $cashAccount->id,
            'bank_name' => 'Bank Central Asia',
            'bank_account_number' => '1234567890',
            'currency_code' => 'IDR',
            'opening_balance' => '1000000.00',
            'current_balance' => '1000000.00',
            'is_active' => true,
        ]);

        // Start session
        Livewire::test(BankReconciliationWorkbench::class)
            ->assertOk()
            ->set('selectedAccountId', $bankAccount->id)
            ->call('openStartSessionModal')
            ->set('statementEndDate', now()->toDateString())
            ->set('statementEndingBalance', 1500000.0)
            ->set('sessionNotes', 'Rekonsiliasi BCA Awal Bulan')
            ->call('startNewSession')
            ->assertHasNoErrors();

        /** @var BankReconciliationSession $session */
        $session = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('cash_bank_account_id', $bankAccount->id)
            ->firstOrFail();

        $this->assertEquals(BankReconciliationSessionStatusEnum::IN_PROGRESS, $session->status);

        /** @var BankStatement $statement */
        $statement = BankStatement::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'cash_bank_account_id' => $bankAccount->id,
            'statement_number' => 'STMT-001',
            'statement_date' => now()->toDateString(),
            'opening_balance' => '1000000.00',
            'closing_balance' => '1500000.00',
            'status' => 'OPEN',
        ]);

        // Create 1 statement line and 1 cash bank transaction
        /** @var BankStatementLine $statementLine */
        $statementLine = BankStatementLine::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'bank_statement_id' => $statement->id,
            'cash_bank_account_id' => $bankAccount->id,
            'line_number' => 1,
            'value_date' => now()->toDateString(),
            'credit_amount' => '500000.00',
            'debit_amount' => '0.00',
            'running_balance' => '1500000.00',
            'description' => 'Transfer Masuk Pelanggan PT Sukses',
            'match_status' => BankStatementLineMatchStatusEnum::UNMATCHED,
            'line_fingerprint_hash' => hash('sha256', 'test_fingerprint_stmt_1'),
        ]);

        /** @var CashBankTransaction $cashTxn */
        $cashTxn = CashBankTransaction::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'cash_bank_account_id' => $bankAccount->id,
            'transaction_number' => 'TXN-'.uniqid(),
            'transaction_date' => now()->toDateString(),
            'transaction_type' => CashBankTransactionTypeEnum::RECEIPT,
            'amount' => '500000.00',
            'running_balance_after' => '1500000.00',
            'description' => 'Transfer Masuk Pelanggan PT Sukses',
            'idempotency_key' => 'idemp_txn_'.uniqid(),
            'status' => CashBankTransactionStatusEnum::POSTED,
            'is_reconciled' => false,
        ]);

        // Manual match
        Livewire::test(BankReconciliationWorkbench::class)
            ->set('selectedAccountId', $bankAccount->id)
            ->set('selectedSessionId', $session->id)
            ->set('selectedStatementLineId', $statementLine->id)
            ->set('selectedTransactionId', $cashTxn->id)
            ->call('manualMatch')
            ->assertHasNoErrors();

        $this->assertEquals(BankStatementLineMatchStatusEnum::MATCHED, $statementLine->fresh()->match_status);
        $this->assertTrue((bool) $cashTxn->fresh()->is_reconciled);

        $this->assertDatabaseHas('bank_reconciliation_matches', [
            'tenant_id' => $this->tenant->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $statementLine->id,
            'cash_bank_transaction_id' => $cashTxn->id,
            'matched_amount' => '500000.00',
        ]);
    }
}
