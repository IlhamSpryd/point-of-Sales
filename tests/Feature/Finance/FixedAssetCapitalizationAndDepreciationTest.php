<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Modules\Finance\Services\DepreciationService;
use App\Modules\Finance\Services\FixedAssetService;
use App\Services\Context\TenantContext;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FixedAssetCapitalizationAndDepreciationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $user;

    protected AccountingPeriod $period;

    protected FixedAssetService $assetService;

    protected DepreciationService $deprService;

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

        // Kategori Peralatan (1510 asset, 1519 akumulasi, 6106 beban)
        $assetAcc = $this->coaService->getAccountBySystemKey('FIXED_ASSET_EQUIPMENT', $this->tenant->id);
        $accumAcc = $this->coaService->getAccountBySystemKey('ACCUM_DEPR_EQUIPMENT', $this->tenant->id);
        $deprAcc = $this->coaService->getAccountBySystemKey('DEPRECIATION_EXPENSE', $this->tenant->id);

        $this->category = FixedAssetCategory::create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'EQP',
            'category_name' => 'Peralatan Toko & Komputer',
            'asset_gl_account_id' => $assetAcc->id,
            'accum_depr_gl_account_id' => $accumAcc->id,
            'depr_expense_gl_account_id' => $deprAcc->id,
            'useful_life_months' => 24,
            'is_active' => true,
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
            'currency_code' => 'IDR',
            'opening_balance' => '100000000.00',
            'current_balance' => '100000000.00',
            'is_active' => true,
        ]);
    }

    public function test_asset_registration_and_capitalization(): void
    {
        // 1. Registrasi Aset Baru (DRAFT)
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Mesin Espresso La Marzocco',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '24000000.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 24,
        ], $this->user);

        $this->assertEquals(FixedAssetStatusEnum::DRAFT, $asset->status);
        $this->assertEquals('24000000.00', (string) $asset->acquisition_cost);
        $this->assertEquals('24000000.00', (string) $asset->net_book_value);
        $this->assertEquals('0.00', (string) $asset->accumulated_depreciation);

        // 2. Kapitalisasi Aset (DRAFT -> ACTIVE)
        $capitalized = $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        $this->assertEquals(FixedAssetStatusEnum::ACTIVE, $capitalized->status);
        $this->assertNotNull($capitalized->acquisition_journal_id);

        // Verifikasi Jurnal Kapitalisasi (PR-FA-01)
        $journal = $capitalized->acquisitionJournal;
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        $lines = $journal->lines;
        $this->assertCount(2, $lines);

        // Debit FIXED_ASSET_EQUIPMENT (1510) = 24,000,000.00
        $debitLine = $lines->firstWhere('account_id', $this->category->asset_gl_account_id);
        $this->assertNotNull($debitLine);
        $this->assertEquals('24000000.00', (string) $debitLine->debit_amount);

        // Credit BANK_MAIN = 24,000,000.00
        $creditLine = $lines->firstWhere('account_id', $this->bankAccount->gl_account_id);
        $this->assertNotNull($creditLine);
        $this->assertEquals('24000000.00', (string) $creditLine->credit_amount);

        // Verifikasi Jadwal Penyusutan (24 baris, masing-masing 1,000,000.00, PENDING)
        $schedules = $capitalized->schedules;
        $this->assertCount(24, $schedules);
        $this->assertTrue($schedules->every(fn ($s) => $s->status === DepreciationScheduleStatusEnum::PENDING));
        $this->assertEquals('1000000.00', (string) $schedules->first()->depreciation_amount);
        $this->assertEquals('1000000.00', (string) $schedules->last()->depreciation_amount);
    }

    public function test_monthly_depreciation_posting_and_idempotency(): void
    {
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Server POS Utama',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '12000000.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 12,
        ], $this->user);

        $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        // Eksekusi depresiasi bulan berjalan
        $result = $this->deprService->postMonthlyDepreciation($this->period, $this->tenant->id, $this->user);

        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals('1000000.00', (string) $result['total_depreciation_amount']);

        // Periksa aset setelah depresiasi bulan ke-1
        $asset->refresh();
        $this->assertEquals('1000000.00', (string) $asset->accumulated_depreciation);
        $this->assertEquals('11000000.00', (string) $asset->net_book_value);

        // Verifikasi Jurnal Depresiasi (PR-FA-02)
        $schedule = $asset->schedules()->where('accounting_period_id', $this->period->id)->first();
        $this->assertEquals(DepreciationScheduleStatusEnum::POSTED, $schedule->status);
        $this->assertNotNull($schedule->journal_entry_id);

        $journal = $schedule->journalEntry;
        $this->assertEquals(JournalStatusEnum::POSTED, $journal->status);

        $lines = $journal->lines;
        $this->assertCount(2, $lines);

        // Debit DEPRECIATION_EXPENSE (6106) = 1,000,000.00
        $debitLine = $lines->firstWhere('account_id', $this->category->depr_expense_gl_account_id);
        $this->assertNotNull($debitLine);
        $this->assertEquals('1000000.00', (string) $debitLine->debit_amount);

        // Credit ACCUM_DEPR_EQUIPMENT (1519) = 1,000,000.00
        $creditLine = $lines->firstWhere('account_id', $this->category->accum_depr_gl_account_id);
        $this->assertNotNull($creditLine);
        $this->assertEquals('1000000.00', (string) $creditLine->credit_amount);

        // Uji Idempotensi: Eksekusi ulang pada periode yang sama tidak menghasilkan jurnal ganda
        $journalCountBefore = JournalEntry::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();

        $secondRun = $this->deprService->postMonthlyDepreciation($this->period, $this->tenant->id, $this->user);
        $this->assertEquals(0, $secondRun['total_processed']);

        $journalCountAfter = JournalEntry::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
        $this->assertEquals($journalCountBefore, $journalCountAfter);
    }

    public function test_straight_line_rounding_drift_absorption(): void
    {
        // Biaya 100.00 dibagi 3 bulan: 33.33 + 33.33 + 33.34 = 100.00
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Perangkat Uji Drift Sen',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '100.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 3,
        ], $this->user);

        $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        $schedules = $asset->schedules()->orderBy('id')->get();
        $this->assertCount(3, $schedules);

        $this->assertEquals('33.33', (string) $schedules[0]->depreciation_amount);
        $this->assertEquals('33.33', (string) $schedules[1]->depreciation_amount);
        $this->assertEquals('33.34', (string) $schedules[2]->depreciation_amount);

        $totalDepr = '0.00';
        foreach ($schedules as $s) {
            $totalDepr = bcadd($totalDepr, (string) $s->depreciation_amount, 2);
        }

        // Akumulasi penyusutan tepat sama dengan 100.00 tanpa drift sen
        $this->assertEquals('100.00', $totalDepr);
        $this->assertEquals('0.00', (string) $schedules[2]->net_book_value_after);
    }

    public function test_artisan_command_finance_run_depreciation(): void
    {
        $asset = $this->assetService->createAsset([
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'asset_name' => 'Mesin Pendingin Showcase',
            'acquisition_date' => now()->toDateString(),
            'acquisition_cost' => '6000000.00',
            'salvage_value' => '0.00',
            'useful_life_months' => 6,
        ], $this->user);

        $this->assetService->capitalize($asset, $this->user, $this->bankAccount->id);

        $exitCode = Artisan::call('finance:run-depreciation', [
            'tenant' => $this->tenant->id,
            '--period' => $this->period->period_code,
        ]);

        $this->assertEquals(0, $exitCode);

        $asset->refresh();
        $this->assertEquals('1000000.00', (string) $asset->accumulated_depreciation);
        $this->assertEquals('5000000.00', (string) $asset->net_book_value);
    }
}
