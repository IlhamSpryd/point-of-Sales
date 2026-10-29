<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\TaxCalculationTypeEnum;
use App\Modules\Finance\Enums\TaxDirectionEnum;
use App\Modules\Finance\Enums\TaxTypeEnum;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\TaxConfigurationService;
use App\Modules\Finance\Services\TaxLedgerService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxInclusiveVsExclusiveCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected TaxConfigurationService $taxConfigService;

    protected TaxLedgerService $taxLedgerService;

    protected ChartOfAccountService $coaService;

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
    }

    public function test_tax_exclusive_calculation_and_ledger_recording(): void
    {
        $salesTaxAccount = $this->coaService->getAccountBySystemKey('TAX_OUTPUT_PPN', $this->tenant->id);
        $purchaseTaxAccount = $this->coaService->getAccountBySystemKey('TAX_INPUT_PPN', $this->tenant->id);

        $ppnExcl = $this->taxConfigService->createTaxCode([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN-EXCL',
            'name' => 'PPN 11% Eksklusif',
            'tax_type' => TaxTypeEnum::VAT_PPN,
            'calculation_type' => TaxCalculationTypeEnum::EXCLUSIVE,
            'rate_percent' => '11.0000',
            'effective_from' => '2026-01-01',
            'sales_tax_account_id' => $salesTaxAccount->id,
            'purchase_tax_account_id' => $purchaseTaxAccount->id,
        ], $this->user);

        // Kasus: DPP = 1,000,000.00
        $result = $this->taxConfigService->calculateTax($ppnExcl, '1000000.00', now()->toDateString());

        $this->assertEquals('1000000.00', $result['tax_base_amount']);
        $this->assertEquals('110000.00', $result['tax_amount']);
        $this->assertEquals('1110000.00', $result['gross_amount']);

        // Catat Output Tax (Penjualan)
        $outEntry = $this->taxLedgerService->recordTaxEntry([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $ppnExcl->id,
            'tax_direction' => TaxDirectionEnum::OUTPUT_TAX,
            'transaction_date' => now()->toDateString(),
            'tax_base_amount' => $result['tax_base_amount'],
            'applied_rate' => $result['applied_rate'],
            'tax_amount' => $result['tax_amount'],
            'counterparty_name' => 'Pelanggan Retail',
        ], $this->user);

        $this->assertEquals('110000.00', $outEntry->tax_amount);
        $this->assertEquals(TaxDirectionEnum::OUTPUT_TAX, $outEntry->tax_direction);

        // Catat Input Tax (Pembelian)
        $inEntry = $this->taxLedgerService->recordTaxEntry([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $ppnExcl->id,
            'tax_direction' => TaxDirectionEnum::INPUT_TAX,
            'transaction_date' => now()->toDateString(),
            'tax_base_amount' => $result['tax_base_amount'],
            'applied_rate' => $result['applied_rate'],
            'tax_amount' => $result['tax_amount'],
            'counterparty_name' => 'Pemasok Bahan',
        ], $this->user);

        $this->assertEquals('110000.00', $inEntry->tax_amount);
        $this->assertEquals(TaxDirectionEnum::INPUT_TAX, $inEntry->tax_direction);
    }

    public function test_tax_inclusive_calculation_guarantees_sum_equality(): void
    {
        $salesTaxAccount = $this->coaService->getAccountBySystemKey('TAX_OUTPUT_PPN', $this->tenant->id);

        $ppnIncl = $this->taxConfigService->createTaxCode([
            'tenant_id' => $this->tenant->id,
            'code' => 'PPN-INCL',
            'name' => 'PPN 11% Inklusif (Harga Sudah Termasuk Pajak)',
            'tax_type' => TaxTypeEnum::VAT_PPN,
            'calculation_type' => TaxCalculationTypeEnum::INCLUSIVE,
            'rate_percent' => '11.0000',
            'effective_from' => '2026-01-01',
            'sales_tax_account_id' => $salesTaxAccount->id,
        ], $this->user);

        // Kasus 1: Nilai Pas 111,000.00 -> DPP = 100,000.00, Tax = 11,000.00
        $res1 = $this->taxConfigService->calculateTax($ppnIncl, '111000.00', now()->toDateString());
        $this->assertEquals('100000.00', $res1['tax_base_amount']);
        $this->assertEquals('11000.00', $res1['tax_amount']);
        $this->assertEquals('111000.00', $res1['gross_amount']);
        $this->assertEquals('111000.00', bcadd($res1['tax_base_amount'], $res1['tax_amount'], 2));

        // Kasus 2: Nilai Ganjil 10,000.00 (Harga Retail)
        // DPP = 10000 / 1.11 = 9009.009... => 9009.01
        // Tax = 10000 - 9009.01 = 990.99
        $res2 = $this->taxConfigService->calculateTax($ppnIncl, '10000.00', now()->toDateString());
        $this->assertEquals('9009.01', $res2['tax_base_amount']);
        $this->assertEquals('990.99', $res2['tax_amount']);
        $this->assertEquals('10000.00', $res2['gross_amount']);
        // Pastikan Invarian FIN-M7-01: DPP + Tax == Gross tepat tanpa selisih sen
        $this->assertEquals('10000.00', bcadd($res2['tax_base_amount'], $res2['tax_amount'], 2));
    }

    public function test_local_pb1_restaurant_tax_separation(): void
    {
        // Pajak Daerah Restoran PB1 10%
        $pb1Code = $this->taxConfigService->createTaxCode([
            'tenant_id' => $this->tenant->id,
            'code' => 'PB1-RESTO',
            'name' => 'Pajak Restoran PB1 10%',
            'tax_type' => TaxTypeEnum::LOCAL_PB1,
            'calculation_type' => TaxCalculationTypeEnum::EXCLUSIVE,
            'rate_percent' => '10.0000',
            'effective_from' => '2026-01-01',
        ], $this->user);

        $res = $this->taxConfigService->calculateTax($pb1Code, '250000.00', now()->toDateString());

        $this->assertEquals('250000.00', $res['tax_base_amount']);
        $this->assertEquals('25000.00', $res['tax_amount']);
        $this->assertEquals('275000.00', $res['gross_amount']);

        $entry = $this->taxLedgerService->recordTaxEntry([
            'tenant_id' => $this->tenant->id,
            'tax_code_id' => $pb1Code->id,
            'tax_direction' => TaxDirectionEnum::OUTPUT_TAX,
            'transaction_date' => now()->toDateString(),
            'tax_base_amount' => $res['tax_base_amount'],
            'applied_rate' => $res['applied_rate'],
            'tax_amount' => $res['tax_amount'],
            'notes' => 'Pajak Daerah Menu F&B Meja 05',
        ], $this->user);

        $this->assertEquals(TaxTypeEnum::LOCAL_PB1, $entry->taxCode->tax_type);
        $this->assertEquals('25000.00', $entry->tax_amount);
    }
}
