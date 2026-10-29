<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\CashBankAccountService;
use App\Modules\Finance\Services\CashBankTransactionService;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBankTransferAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected CashBankAccountService $cbaService;

    protected CashBankTransactionService $txnService;

    protected ChartOfAccountService $coaService;

    protected CashBankAccount $safeAccount;

    protected CashBankAccount $bankAccount;

    protected CashBankAccount $drawerAccount;

    protected CashBankAccount $pgAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

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

        // 1. Akun Brankas Toko (STORE_SAFE)
        $safeCoa = $this->coaService->getAccountBySystemKey('STORE_SAFE', $this->tenant->id);
        $this->safeAccount = $this->cbaService->createAccount([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'SAFE-01',
            'account_name' => 'Brankas Toko Utama',
            'account_type' => CashBankAccountTypeEnum::STORE_SAFE,
            'gl_account_id' => $safeCoa->id,
            'opening_balance' => '10000000.00',
        ], $this->user);

        $equityCoa = $this->coaService->getAccountBySystemKey('OPENING_BALANCE_EQUITY', $this->tenant->id);
        app(JournalEntryService::class)->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: now()->toDateString(),
            idempotencyKey: 'setup:safe_opening_balance',
            lines: [
                new JournalLineDto(
                    accountId: (int) $safeCoa->id,
                    debitAmount: '10000000.00',
                    creditAmount: '0.00',
                    description: 'Saldo Awal Brankas Toko'
                ),
                new JournalLineDto(
                    accountId: (int) $equityCoa->id,
                    debitAmount: '0.00',
                    creditAmount: '10000000.00',
                    description: 'Ekuitas Saldo Awal'
                ),
            ],
            notes: 'Saldo Awal Brankas Toko',
            actor: $this->user
        );

        // 2. Akun Bank Utama (BANK_ACCOUNT)
        $bankCoa = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = $this->cbaService->createAccount([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BANK-BCA-01',
            'account_name' => 'BCA Operasional',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankCoa->id,
            'bank_name' => 'Bank Central Asia',
            'bank_account_number' => '1234567890',
            'opening_balance' => '0.00',
        ], $this->user);

        // 3. Akun Kliring Laci Kasir (POS_DRAWER_CLEARING)
        $drawerCoa = $this->coaService->getAccountBySystemKey('CASH_DRAWER_CLEARING', $this->tenant->id);
        $this->drawerAccount = $this->cbaService->createAccount([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'DRAWER-01',
            'account_name' => 'Kasir 1 Drawer Clearing',
            'account_type' => CashBankAccountTypeEnum::POS_DRAWER_CLEARING,
            'gl_account_id' => $drawerCoa->id,
            'opening_balance' => '2500000.00',
        ], $this->user);

        // 4. Akun Kliring Payment Gateway (PAYMENT_GATEWAY_CLEARING)
        $pgCoa = $this->coaService->getAccountBySystemKey('PG_CLEARING', $this->tenant->id);
        $this->pgAccount = $this->cbaService->createAccount([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'PG-MIDTRANS-01',
            'account_name' => 'Midtrans / QRIS Clearing',
            'account_type' => CashBankAccountTypeEnum::PAYMENT_GATEWAY_CLEARING,
            'gl_account_id' => $pgCoa->id,
            'opening_balance' => '10000000.00',
        ], $this->user);
    }

    public function test_internal_transfer_updates_balances_creates_transactions_and_posts_gl_journal(): void
    {
        $transferAmount = '4000000.00';
        $result = $this->txnService->transferInternal(
            sourceAccount: $this->safeAccount,
            destinationAccount: $this->bankAccount,
            amount: $transferAmount,
            date: now()->toDateString(),
            description: 'Setoran Tunai Brankas ke Bank BCA',
            referenceNumber: 'TRF-TEST-001',
            actor: $this->user
        );

        $outTxn = $result['out_transaction'];
        $inTxn = $result['in_transaction'];
        $journal = $result['journal_entry'];

        // 1. Verifikasi saldo akun terupdate secara atomik
        $this->assertEquals('6000000.00', $this->cbaService->getAccountBalance($this->safeAccount));
        $this->assertEquals('4000000.00', $this->cbaService->getAccountBalance($this->bankAccount));

        // 2. Verifikasi transaksi keluar (TRANSFER_OUT)
        $this->assertEquals(CashBankTransactionTypeEnum::TRANSFER_OUT, $outTxn->transaction_type);
        $this->assertEquals('4000000.00', $outTxn->amount);
        $this->assertEquals('6000000.00', $outTxn->running_balance_after);
        $this->assertEquals($this->bankAccount->id, $outTxn->transfer_pair_account_id);
        $this->assertEquals(CashBankTransactionStatusEnum::POSTED, $outTxn->status);

        // 3. Verifikasi transaksi masuk (TRANSFER_IN)
        $this->assertEquals(CashBankTransactionTypeEnum::TRANSFER_IN, $inTxn->transaction_type);
        $this->assertEquals('4000000.00', $inTxn->amount);
        $this->assertEquals('4000000.00', $inTxn->running_balance_after);
        $this->assertEquals($this->safeAccount->id, $inTxn->transfer_pair_account_id);
        $this->assertEquals(CashBankTransactionStatusEnum::POSTED, $inTxn->status);

        // 4. Verifikasi Jurnal GL: Dr Bank, Cr Safe
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);
        $lines = $journal->lines;
        $this->assertCount(2, $lines);

        $debitLine = $lines->firstWhere('debit_amount', '4000000.00');
        $creditLine = $lines->firstWhere('credit_amount', '4000000.00');

        $this->assertNotNull($debitLine);
        $this->assertEquals($this->bankAccount->gl_account_id, $debitLine->account_id);
        $this->assertNotNull($creditLine);
        $this->assertEquals($this->safeAccount->gl_account_id, $creditLine->account_id);

        // 5. Verifikasi saldo GL sinkron
        $safeReconciled = $this->cbaService->reconcileWithGl($this->safeAccount);
        $bankReconciled = $this->cbaService->reconcileWithGl($this->bankAccount);

        $this->assertEquals('6000000.00', $safeReconciled['subledger_balance']);
        $this->assertEquals('6000000.00', $safeReconciled['gl_balance']);
        $this->assertTrue($safeReconciled['is_reconciled']);
        $this->assertEquals('4000000.00', $bankReconciled['gl_balance']);
        $this->assertEquals('4000000.00', $bankReconciled['subledger_balance']);
        $this->assertTrue($bankReconciled['is_reconciled']);

        // 6. Uji penolakan transfer jika saldo tidak cukup
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak mencukupi');
        $this->txnService->transferInternal(
            sourceAccount: $this->safeAccount,
            destinationAccount: $this->bankAccount,
            amount: '7000000.00', // Safe hanya punya 6,000,000
            date: now()->toDateString(),
            description: 'Transfer Gagal Karena Defisit',
            referenceNumber: 'TRF-FAIL-001',
            actor: $this->user
        );
    }

    public function test_pos_shift_deposit_transfers_from_drawer_clearing_to_safe(): void
    {
        $depositAmount = '2000000.00';
        $result = $this->txnService->recordPosShiftDeposit(
            drawerAccount: $this->drawerAccount,
            safeOrBankAccount: $this->safeAccount,
            amount: $depositAmount,
            date: now()->toDateString(),
            referenceNumber: 'SHIFT-DEP-001',
            actor: $this->user,
            shiftId: 99
        );

        $drawerTxn = $result['drawer_transaction'];
        $depositTxn = $result['deposit_transaction'];
        $journal = $result['journal_entry'];

        // Saldo laci berkurang dari 2,500,000 -> 500,000
        $this->assertEquals('500000.00', $this->cbaService->getAccountBalance($this->drawerAccount));
        // Saldo brankas bertambah dari 10,000,000 -> 12,000,000
        $this->assertEquals('12000000.00', $this->cbaService->getAccountBalance($this->safeAccount));

        $this->assertEquals(CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT, $drawerTxn->transaction_type);
        $this->assertEquals(CashBankTransactionTypeEnum::POS_SHIFT_DEPOSIT, $depositTxn->transaction_type);

        // Jurnal GL: Dr STORE_SAFE, Cr POS_DRAWER_CLEARING
        $lines = $journal->lines;
        $this->assertCount(2, $lines);

        $safeLine = $lines->firstWhere('account_id', $this->safeAccount->gl_account_id);
        $drawerLine = $lines->firstWhere('account_id', $this->drawerAccount->gl_account_id);

        $this->assertEquals('2000000.00', $safeLine->debit_amount);
        $this->assertEquals('2000000.00', $drawerLine->credit_amount);
    }

    public function test_payment_gateway_settlement_allocates_mdr_fee_and_deposits_net_to_bank(): void
    {
        $gross = '10000000.00';
        $mdr = '200000.00';
        $net = '9800000.00';

        $result = $this->txnService->recordPaymentGatewaySettlement(
            pgClearingAccount: $this->pgAccount,
            bankAccount: $this->bankAccount,
            grossAmount: $gross,
            mdrFeeAmount: $mdr,
            netAmount: $net,
            date: now()->toDateString(),
            referenceNumber: 'SETTLE-MIDTRANS-001',
            actor: $this->user
        );

        $bankTxn = $result['bank_transaction'];
        $pgTxn = $result['pg_transaction'];
        $journal = $result['journal_entry'];

        // Saldo PG Clearing habis (10,000,000 - 10,000,000 = 0.00)
        $this->assertEquals('0.00', $this->cbaService->getAccountBalance($this->pgAccount));
        // Saldo Bank bertambah bersih 9,800,000
        $this->assertEquals('9800000.00', $this->cbaService->getAccountBalance($this->bankAccount));

        $this->assertEquals(CashBankTransactionTypeEnum::PG_SETTLEMENT, $bankTxn->transaction_type);
        $this->assertEquals('9800000.00', $bankTxn->amount);

        // Jurnal GL 3 Baris: Dr Bank Net, Dr Beban MDR, Cr PG Clearing Gross
        $lines = $journal->lines;
        $this->assertCount(3, $lines);

        $mdrCoa = $this->coaService->getAccountBySystemKey('PAYMENT_GATEWAY_FEE', $this->tenant->id);

        $bankLine = $lines->firstWhere('account_id', $this->bankAccount->gl_account_id);
        $mdrLine = $lines->firstWhere('account_id', $mdrCoa->id);
        $pgLine = $lines->firstWhere('account_id', $this->pgAccount->gl_account_id);

        $this->assertEquals('9800000.00', $bankLine->debit_amount);
        $this->assertEquals('200000.00', $mdrLine->debit_amount);
        $this->assertEquals('10000000.00', $pgLine->credit_amount);

        // Validasi Invarian Gross !== Net + MDR
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-01');
        $this->txnService->recordPaymentGatewaySettlement(
            pgClearingAccount: $this->pgAccount,
            bankAccount: $this->bankAccount,
            grossAmount: '10000000.00',
            mdrFeeAmount: '200000.00',
            netAmount: '9500000.00', // Salah hitung (9,500,000 + 200,000 != 10,000,000)
            date: now()->toDateString(),
            referenceNumber: 'INVALID-SETTLE',
            actor: $this->user
        );
    }

    public function test_void_transfer_reverses_both_accounts_and_generates_gl_reversal(): void
    {
        $result = $this->txnService->transferInternal(
            sourceAccount: $this->safeAccount,
            destinationAccount: $this->bankAccount,
            amount: '2000000.00',
            date: now()->toDateString(),
            description: 'Transfer Yang Akan Dibatalkan',
            referenceNumber: 'TRF-VOID-001',
            actor: $this->user
        );

        $outTxn = $result['out_transaction'];
        $inTxn = $result['in_transaction'];

        $this->assertEquals('8000000.00', $this->cbaService->getAccountBalance($this->safeAccount));
        $this->assertEquals('2000000.00', $this->cbaService->getAccountBalance($this->bankAccount));

        // Eksekusi Pembatalan / Void
        $this->txnService->voidTransaction(
            transaction: $outTxn,
            actor: $this->user,
            reason: 'Salah transfer cabang'
        );

        // 1. Verifikasi saldo kedua akun kembali seperti semula
        $this->assertEquals('10000000.00', $this->cbaService->getAccountBalance($this->safeAccount));
        $this->assertEquals('0.00', $this->cbaService->getAccountBalance($this->bankAccount));

        // 2. Verifikasi status kedua transaksi menjadi REVERSED
        $this->assertEquals(CashBankTransactionStatusEnum::REVERSED, $outTxn->fresh()->status);
        $this->assertEquals(CashBankTransactionStatusEnum::REVERSED, $inTxn->fresh()->status);
        $this->assertEquals($this->user->id, $outTxn->fresh()->reversed_by_user_id);
        $this->assertEquals('Salah transfer cabang', $outTxn->fresh()->reversal_reason);

        // 3. Verifikasi Jurnal Pembalik GL tercipta
        $reversalJournal = JournalEntry::where('reversal_of_journal_id', $outTxn->journal_entry_id)->first();
        $this->assertNotNull($reversalJournal);
        $this->assertEquals(JournalStatusEnum::POSTED, $reversalJournal->status);
    }

    public function test_cannot_void_reconciled_transaction_invariant_fin_m7_12(): void
    {
        $result = $this->txnService->transferInternal(
            sourceAccount: $this->safeAccount,
            destinationAccount: $this->bankAccount,
            amount: '1000000.00',
            date: now()->toDateString(),
            description: 'Transfer Direkonsiliasi',
            referenceNumber: 'TRF-RECON-001',
            actor: $this->user
        );

        $outTxn = $result['out_transaction'];

        // Kunci transaksi sebagai telah direkonsiliasi
        $outTxn->is_reconciled = true;
        $outTxn->reconciled_at = now();
        $outTxn->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-12: Transaksi yang sudah direkonsiliasi tidak dapat dibatalkan.');

        $this->txnService->voidTransaction(
            transaction: $outTxn,
            actor: $this->user,
            reason: 'Coba membatalkan transaksi yang sudah direkonsiliasi'
        );
    }
}
