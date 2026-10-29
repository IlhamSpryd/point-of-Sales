<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\TaxCalculationTypeEnum;
use App\Modules\Finance\Enums\TaxDirectionEnum;
use App\Modules\Finance\Enums\TaxTypeEnum;
use App\Modules\Finance\Models\TaxCode;
use App\Modules\Finance\Models\TaxLedgerEntry;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\Finance\Services\TaxConfigurationService;
use App\Modules\Finance\Services\TaxLedgerService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxEffectiveDateAndSnapshotImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected TaxConfigurationService $taxConfigService;

    protected TaxLedgerService $taxLedgerService;

    protected ChartOfAccountService $coaService;

    protected JournalEntryService $journalService;

    protected TaxCode $ppnCode;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->taxConfigService = app(TaxConfigurationService::class);
        $this->taxLedgerService = app(TaxLedgerService::class);
        $this->coaService = app(ChartOfAccountService::class);
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

        // Siapkan Akun Pajak Keluaran (2101) & Pajak Masukan (1180)
        $salesTaxAccount = $this->coaService->getAccountBySystemKey('TAX_OUTPUT_PPN', $this->tenant->id);
        $purchaseTaxAccount = $this->coaService->getAccountBySystemKey('TAX_INPUT_PPN', $this->tenant->id);

        // Buat Kode Pajak PPN Standar
        $this->ppnCode = $this->taxConfigService->createTaxCode([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN',
            'name' => 'Pajak Pertambahan Nilai',
            'tax_type' => TaxTypeEnum::VAT_PPN,
            'calculation_type' => TaxCalculationTypeEnum::EXCLUSIVE,
            'rate_percent' => '11.0000',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'sales_tax_account_id' => $salesTaxAccount->id,
            'purchase_tax_account_id' => $purchaseTaxAccount->id,
        ], $this->user);
    }

    public function test_effective_date_rate_resolution(): void
    {
        // 1. Tambah tarif 12% yang mulai berlaku pada 2027-01-01
        $rate2027 = $this->taxConfigService->addTaxRate(
            code: $this->ppnCode,
            ratePercent: '12.0000',
            effectiveFrom: '2027-01-01',
            effectiveTo: null,
            actor: $this->user
        );

        // 2. Evaluasi transaksi tanggal 2026-10-15 -> Harus mendapat 11.0000%
        $rateOct2026 = $this->taxConfigService->resolveEffectiveRate($this->ppnCode, '2026-10-15');
        $this->assertNotNull($rateOct2026);
        $this->assertEquals('11.0000', $rateOct2026->rate_percent);

        // 3. Evaluasi transaksi tanggal 2027-02-01 -> Harus mendapat 12.0000%
        $rateFeb2027 = $this->taxConfigService->resolveEffectiveRate($this->ppnCode, '2027-02-01');
        $this->assertNotNull($rateFeb2027);
        $this->assertEquals('12.0000', $rateFeb2027->rate_percent);

        // 4. Hitung pajak untuk nominal 10,000,000 pada kedua tanggal
        $calc2026 = $this->taxConfigService->calculateTax($this->ppnCode, '10000000.00', '2026-10-15');
        $calc2027 = $this->taxConfigService->calculateTax($this->ppnCode, '10000000.00', '2027-02-01');

        $this->assertEquals('1100000.00', $calc2026['tax_amount']);
        $this->assertEquals('11100000.00', $calc2026['gross_amount']);

        $this->assertEquals('1200000.00', $calc2027['tax_amount']);
        $this->assertEquals('11200000.00', $calc2027['gross_amount']);
    }

    public function test_snapshot_immutability_and_trigger_enforcement(): void
    {
        // 1. Catat entri buku pajak historis pada tahun 2026
        $entry = $this->taxLedgerService->recordTaxEntry([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $this->ppnCode->id,
            'tax_direction' => TaxDirectionEnum::OUTPUT_TAX,
            'transaction_date' => '2026-10-20',
            'tax_base_amount' => '50000000.00',
            'applied_rate' => '11.0000',
            'tax_amount' => '5500000.00',
            'counterparty_name' => 'PT Pelanggan Utama',
            'counterparty_tax_id' => '01.234.567.8-901.000',
            'invoice_reference' => 'INV-2026-001',
        ], $this->user);

        $this->assertInstanceOf(TaxLedgerEntry::class, $entry);
        $this->assertEquals('11.0000', $entry->applied_rate);
        $this->assertEquals('5500000.00', $entry->tax_amount);

        // 2. Tambah tarif baru di masa depan
        $this->taxConfigService->addTaxRate(
            code: $this->ppnCode,
            ratePercent: '12.0000',
            effectiveFrom: '2027-01-01',
            actor: $this->user
        );

        // 3. Verifikasi entri historis sama sekali tidak berubah
        $freshEntry = $entry->fresh();
        $this->assertEquals('11.0000', $freshEntry->applied_rate);
        $this->assertEquals('5500000.00', $freshEntry->tax_amount);
        $this->assertEquals('50000000.00', $freshEntry->tax_base_amount);

        // 4. Verifikasi bahwa modifikasi (UPDATE) ditolak oleh trigger trg_tax_ledger_entries_before_update
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-09');

        \DB::table('tax_ledger_entries')
            ->where('id', $entry->id)
            ->update(['tax_amount' => '6000000.00']);
    }

    public function test_tax_ledger_delete_is_prevented_by_immutability_trigger(): void
    {
        $entry = $this->taxLedgerService->recordTaxEntry([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $this->ppnCode->id,
            'tax_direction' => TaxDirectionEnum::OUTPUT_TAX,
            'transaction_date' => '2026-10-22',
            'tax_base_amount' => '20000000.00',
            'applied_rate' => '11.0000',
            'tax_amount' => '2200000.00',
            'invoice_reference' => 'INV-2026-002',
        ], $this->user);

        // Verifikasi bahwa penghapusan (DELETE) ditolak oleh trigger trg_tax_ledger_entries_before_delete
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FIN-M7-09');

        \DB::table('tax_ledger_entries')
            ->where('id', $entry->id)
            ->delete();
    }

    public function test_tax_subledger_reconciles_with_gl_account_balance(): void
    {
        $arCoa = $this->coaService->getAccountBySystemKey('AR_CONTROL', $this->tenant->id);
        $salesCoa = $this->coaService->getAccountBySystemKey('SALES_RETAIL', $this->tenant->id);
        $ppnOutCoa = $this->coaService->getAccountBySystemKey('TAX_OUTPUT_PPN', $this->tenant->id);

        // 1. Buat Jurnal Penjualan dengan PPN Keluaran:
        // Dr Piutang 11,100,000
        // Cr Pendapatan 10,000,000
        // Cr PPN Keluaran 1,100,000
        $lines = [
            new JournalLineDto(
                accountId: (int) $arCoa->id,
                debitAmount: '11100000.00',
                creditAmount: '0.00',
                description: 'Penjualan Klien A'
            ),
            new JournalLineDto(
                accountId: (int) $salesCoa->id,
                debitAmount: '0.00',
                creditAmount: '10000000.00',
                description: 'Pendapatan Klien A'
            ),
            new JournalLineDto(
                accountId: (int) $ppnOutCoa->id,
                debitAmount: '0.00',
                creditAmount: '1100000.00',
                description: 'PPN Keluaran Faktur A'
            ),
        ];

        $journal = $this->journalService->createAndPostJournal(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            postingDate: now()->toDateString(),
            idempotencyKey: 'test:journal:tax_recon',
            lines: $lines,
            sourceModule: 'Sales',
            sourceType: 'customer_invoice',
            notes: 'Faktur Penjualan Klien A',
            actor: $this->user
        );

        // 2. Catat ke Buku Pembantu Pajak
        $this->taxLedgerService->recordTaxEntriesForJournal(
            journal: $journal,
            taxEntriesData: [
                [
                    'tax_code_id' => $this->ppnCode->id,
                    'tax_direction' => TaxDirectionEnum::OUTPUT_TAX,
                    'tax_base_amount' => '10000000.00',
                    'applied_rate' => '11.0000',
                    'tax_amount' => '1100000.00',
                    'counterparty_name' => 'Klien A',
                    'invoice_reference' => 'INV-TEST-A',
                ],
            ],
            actor: $this->user
        );

        // 3. Rekonsiliasi Buku Pembantu Pajak vs Saldo GL
        $recon = $this->taxLedgerService->reconcileWithGl(
            tenantId: $this->tenant->id,
            taxCodeId: $this->ppnCode->id
        );

        $this->assertEquals('1100000.00', $recon['output_tax_subledger']);
        $this->assertEquals('1100000.00', $recon['output_tax_gl']);
        $this->assertEquals('0.00', $recon['output_tax_diff']);
        $this->assertTrue($recon['is_reconciled']);
    }
}
