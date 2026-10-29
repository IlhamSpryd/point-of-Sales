<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use App\Modules\Finance\Enums\FixedAssetDisposalTypeEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\DepreciationService;
use App\Modules\Finance\Services\FixedAssetDisposalService;
use App\Modules\Finance\Services\FixedAssetService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetDisposalAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected FixedAssetService $assetService;

    protected DepreciationService $deprService;

    protected FixedAssetDisposalService $disposalService;

    protected ChartOfAccountService $coaService;

    protected FixedAssetCategory $category;

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

        $this->assetService = app(FixedAssetService::class);
        $this->deprService = app(DepreciationService::class);
        $this->disposalService = app(FixedAssetDisposalService::class);
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

        $this->actingAs($this->user);

        $assetAcc = $this->coaService->getAccountBySystemKey('FIXED_ASSET_EQUIPMENT', $this->tenant->id);
        $accumAcc = $this->coaService->getAccountBySystemKey('ACCUM_DEPR_EQUIPMENT', $this->tenant->id);
        $deprAcc = $this->coaService->getAccountBySystemKey('DEPRECIATION_EXPENSE', $this->tenant->id);

        $this->category = FixedAssetCategory::create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'VEH',
            'category_name' => 'Kendaraan Operasional',
            'asset_gl_account_id' => $assetAcc->id,
            'accum_depr_gl_account_id' => $accumAcc->id,
            'depr_expense_gl_account_id' => $deprAcc->id,
            'useful_life_months' => 10,
            'is_active' => true,
        ]);

        $bankGlAccount = $this->coaService->getAccountBySystemKey('BANK_MAIN', $this->tenant->id);
        $this->bankAccount = CashBankAccount::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'account_code' => 'BCA-001',
            'account_name' => 'Bank BCA Operasional',
            'account_type' => CashBankAccountTypeEnum::BANK_ACCOUNT,
            'gl_account_id' => $bankGlAccount->id,
            'bank_name' => 'BCA',
            'currency_code' => 'IDR',
            'opening_balance' => '20000000.00',
            'current_balance' => '20000000.00',
            'is_active' => true,
        ]);
    }

    public function test_asset_disposal_with_gain_on_sale(): void
    {
        // 1. Buat dan kapitalisasi aset: Biaya 10,000,000.00, umur 10 bulan
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Motor Delivery Honda Beat',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '10000000.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 10,
        ], $this->user);

        $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        // Susutkan 4 bulan (4,000,000.00) -> Nilai buku menjadi 6,000,000.00
        $asset->accumulated_depreciation = '4000000.00';
        $asset->net_book_value = '6000000.00';
        $asset->save();

        // 2. Jual aset seharga 7,500,000.00 (Laba = 7,500,000 - 6,000,000 = 1,500,000.00)
        $disposal = $this->disposalService->disposeAsset($asset, [
            'disposal_date' => now()->toDateString(),
            'disposal_type' => FixedAssetDisposalTypeEnum::SALE,
            'proceeds_amount' => '7500000.00',
            'cash_bank_account_id' => $this->bankAccount->id,
            'reason' => 'Dijual untuk peremajaan armada',
        ], $this->user);

        $this->assertEquals(FixedAssetStatusEnum::DISPOSED, $asset->fresh()->status);
        $this->assertEquals('7500000.00', (string) $disposal->proceeds_amount);
        $this->assertEquals('6000000.00', (string) $disposal->book_value_at_disposal);
        $this->assertEquals('1500000.00', (string) $disposal->gain_loss_amount);

        // Saldo Bank BCA bertambah dari 20,000,000.00 -> 27,500,000.00
        $this->bankAccount->refresh();
        $this->assertEquals('27500000.00', (string) $this->bankAccount->current_balance);

        // Sisa jadwal penyusutan pending terhapus
        $this->assertEquals(0, $asset->schedules()->where('status', DepreciationScheduleStatusEnum::PENDING)->count());

        // Verifikasi Jurnal Pelepasan (PR-FA-03)
        $journal = $disposal->journalEntry;
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        $lines = $journal->lines;

        // 1. Debit Bank BCA = 7,500,000.00
        $bankLine = $lines->firstWhere('account_id', $this->bankAccount->gl_account_id);
        $this->assertNotNull($bankLine);
        $this->assertEquals('7500000.00', (string) $bankLine->debit_amount);

        // 2. Debit ACCUM_DEPR_EQUIPMENT (1519) = 4,000,000.00
        $accumAcc = $this->coaService->getAccountBySystemKey('ACCUM_DEPR_EQUIPMENT', $this->tenant->id);
        $accumLine = $lines->firstWhere('account_id', $accumAcc->id);
        $this->assertNotNull($accumLine);
        $this->assertEquals('4000000.00', (string) $accumLine->debit_amount);

        // 3. Credit FIXED_ASSET_EQUIPMENT (1510) = 10,000,000.00
        $assetAcc = $this->coaService->getAccountBySystemKey('FIXED_ASSET_EQUIPMENT', $this->tenant->id);
        $assetLine = $lines->firstWhere('account_id', $assetAcc->id);
        $this->assertNotNull($assetLine);
        $this->assertEquals('10000000.00', (string) $assetLine->credit_amount);

        // 4. Credit GAIN_ON_ASSET_DISPOSAL (7103) = 1,500,000.00
        $gainAcc = $this->coaService->getAccountBySystemKey('GAIN_ON_ASSET_DISPOSAL', $this->tenant->id);
        $gainLine = $lines->firstWhere('account_id', $gainAcc->id);
        $this->assertNotNull($gainLine);
        $this->assertEquals('1500000.00', (string) $gainLine->credit_amount);

        // Keseimbangan Jurnal: Total Debit = 11,500,000.00 == Total Credit = 11,500,000.00
        $this->assertEquals('11500000.00', (string) $journal->total_debit);
        $this->assertEquals('11500000.00', (string) $journal->total_credit);
    }

    public function test_asset_disposal_with_loss_on_scrap(): void
    {
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Tablet Waiter Rusak',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '5000000.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 5,
        ], $this->user);

        $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        // Susutkan 2 bulan (2,000,000.00) -> Nilai buku menjadi 3,000,000.00
        $asset->accumulated_depreciation = '2000000.00';
        $asset->net_book_value = '3000000.00';
        $asset->save();

        // Scrap aset (Proceeds = 0.00, Rugi = 3,000,000.00)
        $disposal = $this->disposalService->disposeAsset($asset, [
            'disposal_date' => now()->toDateString(),
            'disposal_type' => FixedAssetDisposalTypeEnum::SCRAP,
            'proceeds_amount' => '0.00',
            'reason' => 'Perangkat tersiram air dan konslet total',
        ], $this->user);

        $this->assertEquals(FixedAssetStatusEnum::DISPOSED, $asset->fresh()->status);
        $this->assertEquals('0.00', (string) $disposal->proceeds_amount);
        $this->assertEquals('3000000.00', (string) $disposal->book_value_at_disposal);
        $this->assertEquals('-3000000.00', (string) $disposal->gain_loss_amount);

        // Verifikasi Jurnal Pelepasan (PR-FA-03)
        $journal = $disposal->journalEntry;
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        $lines = $journal->lines;

        // 1. Debit ACCUM_DEPR_EQUIPMENT (1519) = 2,000,000.00
        $accumAcc = $this->coaService->getAccountBySystemKey('ACCUM_DEPR_EQUIPMENT', $this->tenant->id);
        $accumLine = $lines->firstWhere('account_id', $accumAcc->id);
        $this->assertNotNull($accumLine);
        $this->assertEquals('2000000.00', (string) $accumLine->debit_amount);

        // 2. Debit LOSS_ON_ASSET_DISPOSAL (8103) = 3,000,000.00
        $lossAcc = $this->coaService->getAccountBySystemKey('LOSS_ON_ASSET_DISPOSAL', $this->tenant->id);
        $lossLine = $lines->firstWhere('account_id', $lossAcc->id);
        $this->assertNotNull($lossLine);
        $this->assertEquals('3000000.00', (string) $lossLine->debit_amount);

        // 3. Credit FIXED_ASSET_EQUIPMENT (1510) = 5,000,000.00
        $assetAcc = $this->coaService->getAccountBySystemKey('FIXED_ASSET_EQUIPMENT', $this->tenant->id);
        $assetLine = $lines->firstWhere('account_id', $assetAcc->id);
        $this->assertNotNull($assetLine);
        $this->assertEquals('5000000.00', (string) $assetLine->credit_amount);

        // Keseimbangan Jurnal: Total Debit = 5,000,000.00 == Total Credit = 5,000,000.00
        $this->assertEquals('5000000.00', (string) $journal->total_debit);
        $this->assertEquals('5000000.00', (string) $journal->total_credit);
    }
}
