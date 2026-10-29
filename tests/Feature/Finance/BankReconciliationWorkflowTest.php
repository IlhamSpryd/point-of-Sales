<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\BankReconciliationMatchTypeEnum;
use App\Modules\Finance\Enums\BankReconciliationSessionStatusEnum;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Models\BankReconciliationMatch;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Services\BankReconciliationService;
use App\Modules\Finance\Services\CashBankAccountService;
use App\Modules\Finance\Services\CashBankTransactionService;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected BankReconciliationService $reconService;

    protected CashBankAccountService $cbaService;

    protected CashBankTransactionService $txnService;

    protected ChartOfAccountService $coaService;

    protected CashBankAccount $bankAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->reconService = app(BankReconciliationService::class);
        $this->cbaService = app(CashBankAccountService::class);
        $this->txnService = app(CashBankTransactionService::class);
        $this->coaService = app(ChartOfAccountService::class);

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

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
        $this->actingAs($this->user);

        $bankCoa = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = $this->cbaService->createAccount([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-RECON-01',
            'account_name' => 'BCA Giro Operasional Rekonsiliasi',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankCoa->id,
            'bank_name' => 'BCA',
            'bank_account_number' => '999888777',
            'opening_balance' => '0.00',
        ], $this->user);
    }

    public function test_auto_matching_adjustment_entry_and_session_completion_gate(): void
    {
        // 1. Buat transaksi di buku kas/bank
        // Transaksi 1: Penerimaan 10,000,000
        $tx1 = $this->txnService->recordReceipt($this->bankAccount, [
            'amount' => '10000000.00',
            'transaction_date' => now()->startOfMonth()->toDateString(),
            'description' => 'Penerimaan Pelanggan Invoice #001',
            'reference_number' => 'REF-REC-01',
        ], $this->user);

        // Transaksi 2: Penerimaan 5,000,000
        $tx2 = $this->txnService->recordReceipt($this->bankAccount, [
            'amount' => '5000000.00',
            'transaction_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'description' => 'Penerimaan Pelanggan Invoice #002',
            'reference_number' => 'REF-REC-02',
        ], $this->user);

        // Transaksi 3: Pengeluaran 3,000,000
        $tx3 = $this->txnService->recordDisbursement($this->bankAccount, [
            'amount' => '3000000.00',
            'transaction_date' => now()->startOfMonth()->addDays(4)->toDateString(),
            'description' => 'Pembayaran Utang Supplier #001',
            'reference_number' => 'REF-PAY-01',
        ], $this->user);

        // Transaksi 4: Pengeluaran 1,000,000 (Belum cair / tidak ada di rekening koran periode ini)
        $txUncleared = $this->txnService->recordDisbursement($this->bankAccount, [
            'amount' => '1000000.00',
            'transaction_date' => now()->startOfMonth()->addDays(6)->toDateString(),
            'description' => 'Cek Gantung Belum Dicairkan',
            'reference_number' => 'REF-PAY-UNCLEARED',
        ], $this->user);

        // Saldo buku saat ini: 10M + 5M - 3M - 1M = 11,000,000.00
        $this->assertEquals('11000000.00', $this->cbaService->getAccountBalance($this->bankAccount));

        // 2. Impor Rekening Koran Bank
        // Mutasi bank memuat: Tx1 (+10M), Tx2 (+5M), Tx3 (-3M), dan Biaya Admin (-25K).
        // Saldo akhir rekening koran = 0 + 10M + 5M - 3M - 25K = 11,975,000.00
        $stmtLines = [
            [
                'value_date' => now()->startOfMonth()->toDateString(),
                'external_reference' => 'REF-REC-01',
                'debit_amount' => '0.00',
                'credit_amount' => '10000000.00',
                'running_balance' => '10000000.00',
                'description' => 'TRF CR REF-REC-01',
            ],
            [
                'value_date' => now()->startOfMonth()->addDays(2)->toDateString(),
                'external_reference' => 'REF-REC-02',
                'debit_amount' => '0.00',
                'credit_amount' => '5000000.00',
                'running_balance' => '15000000.00',
                'description' => 'TRF CR REF-REC-02',
            ],
            [
                'value_date' => now()->startOfMonth()->addDays(4)->toDateString(),
                'external_reference' => 'REF-PAY-01',
                'debit_amount' => '3000000.00',
                'credit_amount' => '0.00',
                'running_balance' => '12000000.00',
                'description' => 'TRF DB REF-PAY-01',
            ],
            [
                'value_date' => now()->startOfMonth()->addDays(5)->toDateString(),
                'external_reference' => 'ADM-FEE-OCT',
                'debit_amount' => '25000.00',
                'credit_amount' => '0.00',
                'running_balance' => '11975000.00',
                'description' => 'BIAYA ADM BANK OKTOBER',
            ],
        ];

        $statement = $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $stmtLines,
            statementDate: now()->startOfMonth()->addDays(10)->toDateString(),
            statementNumber: 'STMT-OCT-2026',
            filePath: null,
            actor: $this->user,
            openingBalance: '0.00',
            closingBalance: '11975000.00'
        );

        // 3. Mulai Sesi Rekonsiliasi Bank
        $session = $this->reconService->startSession(
            account: $this->bankAccount,
            endDate: now()->startOfMonth()->addDays(10)->toDateString(),
            statementEndingBalance: '11975000.00',
            actor: $this->user,
            notes: 'Rekonsiliasi Bank Oktober 2026'
        );

        $this->assertEquals(BankReconciliationSessionStatusEnum::IN_PROGRESS, $session->status);
        $this->assertEquals('11975000.00', $session->unreconciled_difference); // Awalnya selisih 11.975M karena belum ada yg dicocokkan

        // 4. Jalankan Auto-Match
        $autoMatches = $this->reconService->autoMatch($session, $this->user);
        $this->assertCount(3, $autoMatches); // Tx1, Tx2, Tx3 otomatis cocok

        $this->assertTrue($tx1->fresh()->is_reconciled);
        $this->assertTrue($tx2->fresh()->is_reconciled);
        $this->assertTrue($tx3->fresh()->is_reconciled);
        $this->assertFalse($txUncleared->fresh()->is_reconciled); // Transaksi gantung tetap belum reconciled

        // Baris Biaya Admin masih UNMATCHED
        $feeLine = BankStatementLine::where('bank_statement_id', $statement->id)
            ->where('external_reference', 'ADM-FEE-OCT')
            ->first();
        $this->assertEquals(BankStatementLineMatchStatusEnum::UNMATCHED, $feeLine->match_status);

        // Cek selisih setelah auto-match:
        // Saldo yang telah dicocokkan = 0 + 10M + 5M - 3M = 12,000,000.00
        // Saldo rekening koran = 11,975,000.00
        // Selisih = 11,975,000 - 12,000,000 = -25,000.00
        $diff = $this->reconService->calculateUnreconciledDifference($session);
        $this->assertEquals('-25000.00', $diff);

        // 5. Uji Gerbang Penolakan Penyelesaian Sesi jika selisih != 0.00 (FIN-M7-11)
        try {
            $this->reconService->completeSession($session, $this->user);
            $this->fail('Seharusnya gagal menyelesaikan sesi jika selisih != 0.00.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('FIN-M7-11', $e->getMessage());
            $this->assertStringContainsString('Selisih wajib 0.00', $e->getMessage());
        }

        // 6. Buat Entri Penyesuaian Biaya Admin Bank langsung dari baris rekening koran
        $adjMatch = $this->reconService->createAdjustmentEntry(
            session: $session,
            statementLine: $feeLine,
            adjustmentType: CashBankTransactionTypeEnum::BANK_FEE->value,
            description: 'Penyesuaian Biaya Admin Rekening Koran',
            actor: $this->user
        );

        $this->assertEquals(BankReconciliationMatchTypeEnum::ADJUSTMENT_ENTRY, $adjMatch->match_type);
        $this->assertEquals(BankStatementLineMatchStatusEnum::ADJUSTED, $feeLine->fresh()->match_status);

        // Verifikasi transaksi buku penyesuaian tercipta dan otomatis direkonsiliasi
        $adjTxn = $adjMatch->transaction;
        $this->assertEquals(CashBankTransactionTypeEnum::BANK_FEE, $adjTxn->transaction_type);
        $this->assertEquals('25000.00', $adjTxn->amount);
        $this->assertTrue($adjTxn->is_reconciled);

        // Verifikasi selisih kini menjadi 0.00
        $diffAfterAdj = $this->reconService->calculateUnreconciledDifference($session);
        $this->assertEquals('0.00', $diffAfterAdj);

        // 7. Selesaikan Sesi Rekonsiliasi
        $completedSession = $this->reconService->completeSession($session, $this->user);
        $this->assertEquals(BankReconciliationSessionStatusEnum::COMPLETED, $completedSession->status);
        $this->assertEquals($this->user->id, $completedSession->completed_by_user_id);
        $this->assertNotNull($completedSession->completed_at);

        // 8. Buka Kembali (*Reopen*) Sesi
        $reopenedSession = $this->reconService->reopenSession($completedSession, $this->user, 'Audit ulang mutasi Q4');
        $this->assertEquals(BankReconciliationSessionStatusEnum::REOPENED, $reopenedSession->status);
        $this->assertStringContainsString('Audit ulang mutasi Q4', $reopenedSession->notes);
    }

    public function test_manual_matching_and_unmatching_workflow(): void
    {
        $tx = $this->txnService->recordReceipt($this->bankAccount, [
            'amount' => '7500000.00',
            'transaction_date' => now()->toDateString(),
            'description' => 'Penerimaan Invoice Proyek A',
            'reference_number' => 'PRJ-A-01',
        ], $this->user);

        $statement = $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: [
                [
                    'value_date' => now()->toDateString(),
                    'external_reference' => 'DIFF-REF-99', // Nomor referensi berbeda sehingga auto-match tidak dapat mencocokkan otomatis
                    'debit_amount' => '0.00',
                    'credit_amount' => '7500000.00',
                    'running_balance' => '7500000.00',
                    'description' => 'Transfer Klien PT Mandiri',
                ],
            ],
            statementDate: now()->toDateString(),
            statementNumber: 'STMT-MANUAL-01',
            filePath: null,
            actor: $this->user,
            openingBalance: '0.00',
            closingBalance: '7500000.00'
        );

        $line = $statement->lines->first();

        $session = $this->reconService->startSession(
            account: $this->bankAccount,
            endDate: now()->toDateString(),
            statementEndingBalance: '7500000.00',
            actor: $this->user
        );

        // Lakukan Pencocokan Manual
        $match = $this->reconService->manualMatch(
            session: $session,
            statementLine: $line,
            transaction: $tx,
            actor: $this->user
        );

        $this->assertInstanceOf(BankReconciliationMatch::class, $match);
        $this->assertEquals(BankReconciliationMatchTypeEnum::MANUAL_1_TO_1, $match->match_type);
        $this->assertEquals(BankStatementLineMatchStatusEnum::MATCHED, $line->fresh()->match_status);
        $this->assertTrue($tx->fresh()->is_reconciled);
        $this->assertEquals('0.00', $session->fresh()->unreconciled_difference);

        // Batalkan Pencocokan (*Unmatch*)
        $this->reconService->unmatch($match, $this->user);

        $this->assertEquals(BankStatementLineMatchStatusEnum::UNMATCHED, $line->fresh()->match_status);
        $this->assertFalse($tx->fresh()->is_reconciled);
        $this->assertNull(BankReconciliationMatch::find($match->id));
        $this->assertEquals('7500000.00', $session->fresh()->unreconciled_difference);
    }
}
