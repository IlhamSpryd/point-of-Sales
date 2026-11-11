<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Livewire\Finance\FixedAssetManager;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\DepreciationMethodEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class FixedAssetManagerUiAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;
    protected Company $company;
    protected Store $store;
    protected User $user;
    protected FixedAssetCategory $category;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->company = Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_code' => 'CMP-FA-AUDIT'],
            [
                'company_name' => 'PT Apeiron Asset Audit',
                'legal_name' => 'PT Apeiron Asset Audit Indonesia',
                'npwp' => '012345678901234',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $role = Role::firstOrCreate(
            ['name' => 'Finance'],
            ['description' => 'Finance Specialist', 'is_admin' => false]
        );

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        // Open Accounting Period for 2026-01
        \App\Models\AccountingPeriod::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-01',
            'period_number' => 1,
            'fiscal_year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'OPEN',
            'is_adjustment_period' => false,
        ]);

        // Bank Account for BANK_MAIN
        ChartOfAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1110-' . substr(uniqid(), -4),
            'account_name' => 'Bank Utama Operasional',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK,
            'normal_balance' => NormalBalanceEnum::DEBIT,
            'system_account_key' => 'BANK_MAIN',
            'is_active' => true,
            'is_reconciled' => false,
            'current_balance' => '100000000.00',
        ]);

        // Seed default posting rules including fixed_asset.capitalized
        (new \Database\Seeders\DefaultPostingRulesSeeder())->run($this->tenant->id);

        // Chart of Accounts for Fixed Assets
        $assetAccount = ChartOfAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1510-' . substr(uniqid(), -4),
            'account_name' => 'Peralatan & Mesin POS',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::FIXED_ASSET,
            'normal_balance' => NormalBalanceEnum::DEBIT,
            'is_active' => true,
            'is_reconciled' => false,
            'current_balance' => '0.00',
        ]);

        $accumDeprAccount = ChartOfAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '1590-' . substr(uniqid(), -4),
            'account_name' => 'Akumulasi Depresiasi Mesin',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::ACCUMULATED_DEPRECIATION,
            'normal_balance' => NormalBalanceEnum::CREDIT,
            'is_active' => true,
            'is_reconciled' => false,
            'current_balance' => '0.00',
        ]);

        $deprExpAccount = ChartOfAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'account_code' => '6210-' . substr(uniqid(), -4),
            'account_name' => 'Beban Penyusutan Mesin POS',
            'account_type' => AccountTypeEnum::EXPENSE,
            'account_subtype' => AccountSubtypeEnum::DEPRECIATION_EXPENSE,
            'normal_balance' => NormalBalanceEnum::DEBIT,
            'is_active' => true,
            'is_reconciled' => false,
            'current_balance' => '0.00',
        ]);

        $this->category = FixedAssetCategory::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'category_code' => 'CAT-POS-' . substr(uniqid(), -4),
            'category_name' => 'Terminal POS Hardware',
            'asset_gl_account_id' => $assetAccount->id,
            'accum_depr_gl_account_id' => $accumDeprAccount->id,
            'depr_expense_gl_account_id' => $deprExpAccount->id,
            'useful_life_months' => 36,
            'depreciation_method' => DepreciationMethodEnum::STRAIGHT_LINE,
            'is_active' => true,
        ]);
    }

    public function test_fixed_asset_page_route_is_accessible(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('finance.fixed-assets.index'));

        $response->assertStatus(200);
        $response->assertSee('Fixed Assets');
        $response->assertSee('Daftar Aset Baru');
    }

    public function test_fixed_asset_manager_livewire_can_create_and_activate_asset(): void
    {
        $code = 'FA-TEST-' . substr(uniqid(), -4);

        Livewire::actingAs($this->user)
            ->test(FixedAssetManager::class)
            ->call('openCreateModal')
            ->set('categoryId', $this->category->id)
            ->set('storeId', $this->store->id)
            ->set('assetCode', $code)
            ->set('assetName', 'Sunmi T2 Mini Smart POS')
            ->set('acquisitionDate', '2026-01-15')
            ->set('acquisitionCost', '6000000.00')
            ->set('salvageValue', '500000.00')
            ->set('usefulLifeMonths', 36)
            ->set('depreciationMethod', 'STRAIGHT_LINE')
            ->set('locationNote', 'Kasir Utama Lantai 1')
            ->call('saveAsset')
            ->assertHasNoErrors()
            ->assertSee('Sunmi T2 Mini Smart POS')
            ->assertSee($code);

        $asset = FixedAsset::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('asset_code', $code)
            ->first();

        $this->assertNotNull($asset);
        $this->assertEquals(FixedAssetStatusEnum::DRAFT, $asset->status);

        // Now activate the asset
        Livewire::actingAs($this->user)
            ->test(FixedAssetManager::class)
            ->call('activateAsset', $asset->id)
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertEquals(FixedAssetStatusEnum::ACTIVE, $asset->status);
        $this->assertGreaterThan(0, $asset->depreciationSchedules()->count());
    }

    public function test_fixed_asset_manager_search_and_filter(): void
    {
        $asset1 = FixedAsset::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'asset_code' => 'SRCH-ESPRESSO',
            'asset_name' => 'Mesin Espresso La Marzocco',
            'acquisition_date' => '2026-02-01',
            'acquisition_cost' => '120000000.00',
            'salvage_value' => '10000000.00',
            'useful_life_months' => 60,
            'depreciation_method' => DepreciationMethodEnum::STRAIGHT_LINE,
            'status' => FixedAssetStatusEnum::DRAFT,
            'accumulated_depreciation' => '0.00',
            'net_book_value' => '120000000.00',
        ]);

        $asset2 = FixedAsset::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'asset_code' => 'SRCH-GRINDER',
            'asset_name' => 'Coffee Grinder Mahlkonig EK43',
            'acquisition_date' => '2026-02-01',
            'acquisition_cost' => '35000000.00',
            'salvage_value' => '2000000.00',
            'useful_life_months' => 48,
            'depreciation_method' => DepreciationMethodEnum::STRAIGHT_LINE,
            'status' => FixedAssetStatusEnum::ACTIVE,
            'accumulated_depreciation' => '0.00',
            'net_book_value' => '35000000.00',
        ]);

        Livewire::actingAs($this->user)
            ->test(FixedAssetManager::class)
            ->set('search', 'Espresso')
            ->assertSee('Mesin Espresso La Marzocco')
            ->assertDontSee('Coffee Grinder Mahlkonig EK43')
            ->set('search', '')
            ->set('statusFilter', 'ACTIVE')
            ->assertSee('Coffee Grinder Mahlkonig EK43')
            ->assertDontSee('Mesin Espresso La Marzocco');
    }
}
