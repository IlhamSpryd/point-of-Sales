<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\TaxCalculationTypeEnum;
use App\Modules\Finance\Enums\TaxTypeEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\TaxCode;
use App\Modules\Finance\Models\TaxLedgerEntry;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\ExpenseService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\Finance\Services\TaxConfigurationService;
use App\Policies\ExpensePolicy;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseWorkflowAndSodTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $submitter;

    protected User $approver;

    protected AccountingPeriod $period;

    protected ExpenseService $expenseService;

    protected ChartOfAccountService $coaService;

    protected TaxConfigurationService $taxConfigService;

    protected JournalEntryService $journalService;

    protected ExpenseCategory $category;

    protected TaxCode $ppnCode;

    protected CashBankAccount $bankAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->expenseService = app(ExpenseService::class);
        $this->coaService = app(ChartOfAccountService::class);
        $this->taxConfigService = app(TaxConfigurationService::class);
        $this->journalService = app(JournalEntryService::class);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('m'),
            'status' => AccountingPeriodStatusEnum::OPEN,
            'is_reconciled' => false,
        ]);

        $staffRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Staff'],
            ['is_admin' => false]
        );

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'FinanceManager'],
            ['is_admin' => true]
        );

        $this->submitter = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $staffRole->id,
        ]);

        $this->approver = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        // Kategori beban utilitas terhubung ke akun 6103 (EXPENSE_UTILITIES)
        $utilAccount = $this->coaService->getAccountBySystemKey('EXPENSE_UTILITIES', $this->tenant->id);
        $this->category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'UTIL',
            'category_name' => 'Biaya Listrik, Air & Utilitas',
            'expense_gl_account_id' => $utilAccount->id,
            'is_active' => true,
        ]);

        // Pajak PPN 11%
        $this->ppnCode = $this->taxConfigService->createTaxCode([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN-IN',
            'name' => 'PPN Masukan Standar',
            'tax_type' => TaxTypeEnum::VAT_PPN,
            'calculation_type' => TaxCalculationTypeEnum::EXCLUSIVE,
            'rate_percent' => '11.0000',
            'effective_from' => '2026-01-01',
            'purchase_tax_account_id' => $this->coaService->getAccountBySystemKey('TAX_INPUT_PPN', $this->tenant->id)->id,
        ]);

        // Akun Bank BCA
        $bankGlAccount = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-001',
            'account_name' => 'Bank BCA Operasional',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGlAccount->id,
            'bank_name' => 'BCA',
            'bank_account_number' => '888001122',
            'currency_code' => 'IDR',
            'opening_balance' => '50000000.00',
            'current_balance' => '50000000.00',
            'is_active' => true,
        ]);
    }

    public function test_draft_creation_and_submission(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Tagihan Listrik dan Air Gedung Toko',
            'lines' => [
                [
                    'description' => 'Listrik PLN Bulan Berjalan',
                    'amount' => '1000000.00',
                    'tax_code_id' => $this->ppnCode->id,
                ],
                [
                    'description' => 'Air PDAM Bulan Berjalan',
                    'amount' => '500000.00',
                    'tax_code_id' => null,
                ],
            ],
        ], $this->submitter);

        $this->assertEquals(ExpenseStatusEnum::DRAFT, $expense->status);
        $this->assertEquals('1500000.00', $expense->subtotal_amount);
        $this->assertEquals('110000.00', $expense->tax_amount);
        $this->assertEquals('1610000.00', $expense->total_amount);
        $this->assertCount(2, $expense->lines);

        // Submit
        $submitted = $this->expenseService->submit($expense, $this->submitter);
        $this->assertEquals(ExpenseStatusEnum::SUBMITTED, $submitted->status);
        $this->assertEquals($this->submitter->id, $submitted->submitted_by_user_id);
        $this->assertNotNull($submitted->submitted_at);
    }

    public function test_sod_enforcement_blocks_self_approval(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Beban Operasional', 'amount' => '500000.00'],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);

        // Uji kebijakan Policy
        $policy = new ExpensePolicy;
        $this->assertFalse($policy->approve($this->submitter, $expense), 'Policy must disallow self-approval by submitter.');

        // Uji Domain Service Guard
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Separation of Duties (SoD)');

        $this->expenseService->approve($expense, $this->submitter);
    }

    public function test_authorized_approval_creates_accrual_journal_and_tax_ledger(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                [
                    'description' => 'Listrik Kantor',
                    'amount' => '2000000.00',
                    'tax_code_id' => $this->ppnCode->id,
                ],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);

        // Setujui oleh approver yang berbeda
        $this->actingAs($this->approver);
        $approved = $this->expenseService->approve($expense, $this->approver, 'Disetujui untuk dibayarkan');

        $this->assertEquals(ExpenseStatusEnum::APPROVED, $approved->status);
        $this->assertEquals($this->approver->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->accrual_journal_id);

        // Verifikasi Jurnal Akrual
        $journal = $approved->accrualJournal;
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        $lines = $journal->lines;
        $this->assertCount(3, $lines);

        // 1. Debit Beban Utilitas (6103) = 2,000,000.00
        $utilLine = $lines->firstWhere('account_id', $this->category->expense_gl_account_id);
        $this->assertNotNull($utilLine);
        $this->assertEquals('2000000.00', (string) $utilLine->debit_amount);
        $this->assertEquals('0.00', (string) $utilLine->credit_amount);

        // 2. Debit PPN Masukan (1180) = 220,000.00
        $taxAccount = $this->coaService->getAccountBySystemKey('TAX_INPUT_PPN', $this->tenant->id);
        $taxLine = $lines->firstWhere('account_id', $taxAccount->id);
        $this->assertNotNull($taxLine);
        $this->assertEquals('220000.00', (string) $taxLine->debit_amount);
        $this->assertEquals('0.00', (string) $taxLine->credit_amount);

        // 3. Kredit Beban Akrual (2104) = 2,220,000.00
        $accruedAccount = $this->coaService->getAccountBySystemKey('ACCRUED_EXPENSE', $this->tenant->id);
        $accruedLine = $lines->firstWhere('account_id', $accruedAccount->id);
        $this->assertNotNull($accruedLine);
        $this->assertEquals('0.00', (string) $accruedLine->debit_amount);
        $this->assertEquals('2220000.00', (string) $accruedLine->credit_amount);

        // Verifikasi Buku Pembantu Pajak (tax_ledger_entries)
        $taxLedger = TaxLedgerEntry::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('journal_entry_id', $approved->accrual_journal_id)
            ->first();

        $this->assertNotNull($taxLedger);
        $this->assertEquals('220000.00', (string) $taxLedger->tax_amount);
        $this->assertEquals('2000000.00', (string) $taxLedger->tax_base_amount);
    }

    public function test_payment_deducts_cash_and_posts_settlement_journal(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Servis AC Toko', 'amount' => '1500000.00'],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);
        $this->expenseService->approve($expense, $this->approver);

        // Bayar beban menggunakan akun Bank BCA
        $this->actingAs($this->approver);
        $paidExpense = $this->expenseService->recordPayment(
            $expense,
            $this->bankAccount,
            now()->toDateString(),
            $this->approver
        );

        $this->assertEquals(ExpenseStatusEnum::PAID, $paidExpense->status);
        $this->assertNotNull($paidExpense->paid_at);
        $this->assertNotNull($paidExpense->payment_journal_id);
        $this->assertNotNull($paidExpense->cash_bank_transaction_id);

        // Cek Saldo Akun Bank BCA berkurang dari 50,000,000.00 -> 48,500,000.00
        $this->bankAccount->refresh();
        $this->assertEquals('48500000.00', (string) $this->bankAccount->current_balance);

        // Verifikasi Jurnal Pelunasan (PR-EXP-02)
        $paymentJournal = $paidExpense->paymentJournal;
        $this->assertEquals(JournalStatusEnum::POSTED, $paymentJournal->status);

        $lines = $paymentJournal->lines;
        $this->assertCount(2, $lines);

        // Debit ACCRUED_EXPENSE (2104) = 1,500,000.00
        $accruedAccount = $this->coaService->getAccountBySystemKey('ACCRUED_EXPENSE', $this->tenant->id);
        $debitLine = $lines->firstWhere('account_id', $accruedAccount->id);
        $this->assertNotNull($debitLine);
        $this->assertEquals('1500000.00', (string) $debitLine->debit_amount);

        // Credit BANK_MAIN = 1,500,000.00
        $creditLine = $lines->firstWhere('account_id', $this->bankAccount->gl_account_id);
        $this->assertNotNull($creditLine);
        $this->assertEquals('1500000.00', (string) $creditLine->credit_amount);
    }

    public function test_voiding_paid_expense_reverses_all_journals_and_restores_cash(): void
    {
        $this->actingAs($this->submitter);

        $expense = $this->expenseService->createDraft([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'expense_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Beban Perbaikan Pintu', 'amount' => '800000.00'],
            ],
        ], $this->submitter);

        $this->expenseService->submit($expense, $this->submitter);
        $this->expenseService->approve($expense, $this->approver);
        $this->expenseService->recordPayment($expense, $this->bankAccount, now()->toDateString(), $this->approver);

        // Saldo bank setelah bayar
        $this->bankAccount->refresh();
        $this->assertEquals('49200000.00', (string) $this->bankAccount->current_balance);

        // Batalkan (VOID)
        $voided = $this->expenseService->void($expense, $this->approver, 'Klaim beban dibatalkan');

        $this->assertEquals(ExpenseStatusEnum::VOIDED, $voided->status);

        // Saldo Bank BCA kembali utuh ke 50,000,000.00
        $this->bankAccount->refresh();
        $this->assertEquals('50000000.00', (string) $this->bankAccount->current_balance);

        // Jurnal Akrual dan Jurnal Pelunasan harus memiliki reversed_by_journal_id
        $accrual = $voided->accrualJournal()->first();
        $payment = $voided->paymentJournal()->first();

        $this->assertNotNull($accrual->reversed_by_journal_id, 'Accrual journal must be reversed.');
        $this->assertNotNull($payment->reversed_by_journal_id, 'Payment journal must be reversed.');
    }
}
