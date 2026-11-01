<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Finance\AccountingPeriodManager;
use App\Livewire\Finance\ChartOfAccountManager;
use App\Livewire\Finance\JournalEntryManager;
use App\Livewire\HR\AttendanceMonitor;
use App\Livewire\HR\EmployeeManager;
use App\Livewire\HR\LeaveManager;
use App\Livewire\HR\PayrollBatchManager;
use App\Livewire\Inventory\GoodsReceiptManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Inventory\StockAdjustmentManager;
use App\Livewire\Inventory\StockOpnameManager;
use App\Livewire\Inventory\StockTransferManager;
use App\Livewire\Inventory\SupplierReturnManager;
use App\Livewire\Pricing\PromotionCampaignManager;
use App\Livewire\Pricing\VoucherManager;
use App\Livewire\Purchasing\GoodsReceivedNoteManager;
use App\Livewire\Purchasing\PurchaseOrderManager;
use App\Livewire\Purchasing\PurchaseReturnManager;
use App\Livewire\Purchasing\SupplierInvoiceManager;
use App\Livewire\Sales\QuotationManager;
use App\Livewire\Sales\SalesOrderManager;
use App\Models\Role;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class LivewireComponentsAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $unauthorizedUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $context->runAs($this->tenantA->id, $this->storeA->id, function () {
            $unauthRole = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'RestrictedStaff', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1, 'permissions' => []]
            );

            $this->unauthorizedUser = User::create([
                'name' => 'Restricted User A',
                'email' => 'restricted@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'email_verified_at' => now(),
                'tenant_id' => $this->tenantA->id,
                'role_id' => $unauthRole->id,
                'is_active' => 1,
            ]);

            $this->unauthorizedUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);
        });
    }

    public function test_unauthorized_user_cannot_mutate_finance_components(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->unauthorizedUser);

        // JournalEntryManager
        Livewire::test(JournalEntryManager::class)
            ->call('openWorkbenchModal')
            ->assertForbidden();

        // ChartOfAccountManager
        Livewire::test(ChartOfAccountManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // AccountingPeriodManager
        Livewire::test(AccountingPeriodManager::class)
            ->call('generateFiscalYearPeriods')
            ->assertForbidden();

        Livewire::test(AccountingPeriodManager::class)
            ->call('softClose', 999)
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_mutate_hr_components(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->unauthorizedUser);

        // PayrollBatchManager
        Livewire::test(PayrollBatchManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // EmployeeManager
        Livewire::test(EmployeeManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // LeaveManager
        Livewire::test(LeaveManager::class)
            ->call('approve', 999)
            ->assertForbidden();

        // AttendanceMonitor
        Livewire::test(AttendanceMonitor::class)
            ->call('openCorrectionModal', 999)
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_mutate_inventory_components(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->unauthorizedUser);

        // StockOpnameManager
        Livewire::test(StockOpnameManager::class)
            ->assertForbidden();

        // StockTransferManager
        Livewire::test(StockTransferManager::class)
            ->assertForbidden();

        // StockAdjustmentManager
        Livewire::test(StockAdjustmentManager::class)
            ->assertForbidden();

        // SupplierReturnManager
        Livewire::test(SupplierReturnManager::class)
            ->assertForbidden();

        // GoodsReceiptManager
        Livewire::test(GoodsReceiptManager::class)
            ->assertForbidden();

        // ModifierManager
        Livewire::test(ModifierManager::class)
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_mutate_purchasing_components(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->unauthorizedUser);

        // SupplierInvoiceManager
        Livewire::test(SupplierInvoiceManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // GoodsReceivedNoteManager
        Livewire::test(GoodsReceivedNoteManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // PurchaseOrderManager
        Livewire::test(PurchaseOrderManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // PurchaseReturnManager
        Livewire::test(PurchaseReturnManager::class)
            ->call('openCreateModal')
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_mutate_pricing_and_sales_components(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->unauthorizedUser);

        // PromotionCampaignManager
        Livewire::test(PromotionCampaignManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // VoucherManager
        Livewire::test(VoucherManager::class)
            ->call('openCreateModal')
            ->assertForbidden();

        // SalesOrderManager
        Livewire::test(SalesOrderManager::class)
            ->assertForbidden();

        // QuotationManager
        Livewire::test(QuotationManager::class)
            ->assertForbidden();
    }
}
