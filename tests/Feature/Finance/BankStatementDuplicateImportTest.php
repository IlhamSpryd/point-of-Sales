<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Models\BankStatement;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Services\BankReconciliationService;
use App\Modules\Finance\Services\CashBankAccountService;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementDuplicateImportTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected BankReconciliationService $reconService;

    protected CashBankAccountService $cbaService;

    protected ChartOfAccountService $coaService;

    protected CashBankAccount $bankAccount;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);

        $this->reconService = app(BankReconciliationService::class);
        $this->cbaService = app(CashBankAccountService::class);
        $this->coaService = app(ChartOfAccountService::class);

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
            'account_code' => 'BCA-001',
            'account_name' => 'Bank BCA Giro Operasional',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankCoa->id,
            'bank_name' => 'BCA',
            'bank_account_number' => '888000111',
            'opening_balance' => '50000000.00',
        ], $this->user);
    }

    public function test_bank_statement_import_creates_statement_and_lines_with_fingerprint_hashes(): void
    {
        $linesData = [
            [
                'value_date' => '2026-10-01',
                'external_reference' => 'TXN-BCA-001',
                'debit_amount' => '0.00',
                'credit_amount' => '15000000.00',
                'running_balance' => '65000000.00',
                'description' => 'Transfer Masuk dari Pelanggan PT Sukses',
            ],
            [
                'value_date' => '2026-10-02',
                'external_reference' => 'TXN-BCA-002',
                'debit_amount' => '5000000.00',
                'credit_amount' => '0.00',
                'running_balance' => '60000000.00',
                'description' => 'Pembayaran Utang Supplier CV Berkah',
            ],
            [
                'value_date' => '2026-10-03',
                'external_reference' => 'TXN-BCA-003',
                'debit_amount' => '15000.00',
                'credit_amount' => '0.00',
                'running_balance' => '59985000.00',
                'description' => 'Biaya Administrasi Bulanan Bank BCA',
            ],
        ];

        $statement = $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $linesData,
            statementDate: '2026-10-03',
            statementNumber: 'STMT-BCA-202610-01',
            filePath: null,
            actor: $this->user,
            openingBalance: '50000000.00',
            closingBalance: '59985000.00'
        );

        // 1. Verifikasi Header Rekening Koran
        $this->assertInstanceOf(BankStatement::class, $statement);
        $this->assertEquals('OPEN', $statement->status);
        $this->assertEquals('50000000.00', $statement->opening_balance);
        $this->assertEquals('59985000.00', $statement->closing_balance);
        $this->assertEquals($this->user->id, $statement->imported_by_user_id);

        // 2. Verifikasi Baris Rekening Koran
        $dbLines = BankStatementLine::where('bank_statement_id', $statement->id)->get();
        $this->assertCount(3, $dbLines);

        foreach ($dbLines as $idx => $line) {
            $expected = $linesData[$idx];
            $this->assertEquals($idx + 1, $line->line_number);
            $this->assertEquals($expected['value_date'], $line->value_date->toDateString());
            $this->assertEquals($expected['external_reference'], $line->external_reference);
            $this->assertEquals($expected['debit_amount'], $line->debit_amount);
            $this->assertEquals($expected['credit_amount'], $line->credit_amount);
            $this->assertEquals($expected['running_balance'], $line->running_balance);
            $this->assertEquals(BankStatementLineMatchStatusEnum::UNMATCHED, $line->match_status);

            // Verifikasi fingerprint hash sha256 valid
            $expectedHash = $this->reconService->computeFingerprintHash(
                accountId: $this->bankAccount->id,
                valueDate: $expected['value_date'],
                debitAmount: $expected['debit_amount'],
                creditAmount: $expected['credit_amount'],
                reference: $expected['external_reference'],
                description: $expected['description']
            );
            $this->assertEquals($expectedHash, $line->line_fingerprint_hash);
        }
    }

    public function test_duplicate_bank_statement_import_is_rejected_by_fingerprint_gate(): void
    {
        $linesData = [
            [
                'value_date' => '2026-10-05',
                'external_reference' => 'TXN-BCA-101',
                'debit_amount' => '0.00',
                'credit_amount' => '2500000.00',
                'running_balance' => '52500000.00',
                'description' => 'Pembayaran Invoice AR #INV-001',
            ],
        ];

        // Impor pertama sukses
        $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $linesData,
            statementDate: '2026-10-05',
            statementNumber: 'STMT-BCA-BATCH-1',
            filePath: null,
            actor: $this->user
        );

        // Impor kedua dengan nomor dokumen berbeda tetapi memuat baris transaksi yang sama persis
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Baris rekening koran duplikat terdeteksi');

        $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $linesData,
            statementDate: '2026-10-05',
            statementNumber: 'STMT-BCA-BATCH-2',
            filePath: null,
            actor: $this->user
        );
    }

    public function test_duplicate_lines_within_same_import_payload_is_rejected(): void
    {
        $linesData = [
            [
                'value_date' => '2026-10-06',
                'external_reference' => 'TXN-SAME-01',
                'debit_amount' => '100000.00',
                'credit_amount' => '0.00',
                'running_balance' => '49900000.00',
                'description' => 'Biaya Materai',
            ],
            [
                'value_date' => '2026-10-06',
                'external_reference' => 'TXN-SAME-01',
                'debit_amount' => '100000.00',
                'credit_amount' => '0.00',
                'running_balance' => '49900000.00',
                'description' => 'Biaya Materai',
            ],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Terdapat baris mutasi ganda di dalam dokumen impor yang sama');

        $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $linesData,
            statementDate: '2026-10-06',
            statementNumber: 'STMT-DUP-IN-FILE',
            filePath: null,
            actor: $this->user
        );
    }

    public function test_csv_import_parses_content_correctly(): void
    {
        $csv = "date,reference,debit,credit,balance,description\n".
            "2026-10-10,TXN-CSV-01,0.00,5000000.00,55000000.00,Setoran Tunai CSV\n".
            "2026-10-11,TXN-CSV-02,25000.00,0.00,54975000.00,Biaya Transfer CSV\n";

        $statement = $this->reconService->importFromCsv(
            account: $this->bankAccount,
            csvContentOrPath: $csv,
            statementDate: '2026-10-11',
            statementNumber: 'STMT-CSV-001',
            actor: $this->user,
            openingBalance: '50000000.00',
            closingBalance: '54975000.00'
        );

        $this->assertCount(2, $statement->lines);
        $line1 = $statement->lines->firstWhere('external_reference', 'TXN-CSV-01');
        $line2 = $statement->lines->firstWhere('external_reference', 'TXN-CSV-02');

        $this->assertEquals('5000000.00', $line1->credit_amount);
        $this->assertEquals('25000.00', $line2->debit_amount);
    }

    public function test_multi_tenant_isolation_allows_identical_statement_for_different_tenant(): void
    {
        $linesData = [
            [
                'value_date' => '2026-10-15',
                'external_reference' => 'TXN-GLOBAL-01',
                'debit_amount' => '0.00',
                'credit_amount' => '10000000.00',
                'running_balance' => '60000000.00',
                'description' => 'Transfer Pembayaran Klien',
            ],
        ];

        // Tenant 1 impor
        $stmtTenant1 = $this->reconService->importStatement(
            account: $this->bankAccount,
            lines: $linesData,
            statementDate: '2026-10-15',
            statementNumber: 'STMT-T1-01',
            filePath: null,
            actor: $this->user
        );
        $this->assertNotNull($stmtTenant1);

        // Buat Tenant 2
        $tenant2 = Tenant::create(['name' => 'Tenant 2']);
        app(TenantContext::class)->setTenantId($tenant2->id);
        app(ChartOfAccountsSeeder::class)->seedForTenant($tenant2->id);

        $adminRole2 = Role::firstOrCreate(
            ['tenant_id' => $tenant2->id, 'name' => 'Owner'],
            ['is_admin' => true]
        );
        $userTenant2 = User::factory()->create([
            'tenant_id' => $tenant2->id,
            'role_id' => $adminRole2->id,
        ]);

        $bankCoaTenant2 = $this->coaService->getAccountBySystemKey('BANK_MAIN', $tenant2->id);
        $bankAccountTenant2 = $this->cbaService->createAccount([
            'tenant_id' => $tenant2->id,
            'account_code' => 'BCA-T2',
            'account_name' => 'BCA Tenant 2',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankCoaTenant2->id,
            'opening_balance' => '0.00',
        ], $userTenant2);

        // Tenant 2 mengimpor data yang sama -> wajib berhasil tanpa bentrok constraint uq_bsl_fingerprint
        $stmtTenant2 = $this->reconService->importStatement(
            account: $bankAccountTenant2,
            lines: $linesData,
            statementDate: '2026-10-15',
            statementNumber: 'STMT-T2-01',
            filePath: null,
            actor: $userTenant2
        );

        $this->assertNotNull($stmtTenant2);
        $this->assertEquals($tenant2->id, $stmtTenant2->tenant_id);
    }
}
