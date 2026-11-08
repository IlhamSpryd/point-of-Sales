<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErpSidebarReachabilityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $managerUser;

    private User $kasirUser;

    private User $salesUser;

    private User $financeUser;

    private User $cookUser;

    private User $baristaUser;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'ERP Reachability Tenant',
            'slug' => 'erp-reach-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ERP Reachability Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $managerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'permissions' => ['*']]);
        $kasirRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Kasir', 'permissions' => ['*']]);
        $salesRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales', 'permissions' => ['*']]);
        $financeRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Finance', 'permissions' => ['*']]);
        $cookRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cook', 'permissions' => ['*']]);
        $baristaRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Barista', 'permissions' => ['*']]);

        $this->ownerUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $ownerRole->id]);
        $this->managerUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $managerRole->id]);
        $this->kasirUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $kasirRole->id]);
        $this->salesUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $salesRole->id]);
        $this->financeUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $financeRole->id]);
        $this->cookUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $cookRole->id]);
        $this->baristaUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $baristaRole->id]);

        foreach ([$this->ownerUser, $this->managerUser, $this->kasirUser, $this->salesUser, $this->financeUser, $this->cookUser, $this->baristaUser] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    public function test_all_sidebar_menu_routes_exist_in_laravel_routing_table(): void
    {
        $sections = config('navigation.sections');
        $this->assertNotEmpty($sections);

        foreach (collect($sections)->flatten(1) as $item) {
            $this->assertTrue(
                Route::has($item['route']),
                "Route '{$item['route']}' for sidebar item '{$item['label']}' is not registered."
            );
        }
    }

    public function test_owner_dashboard_sidebar_renders_all_navigation_pillars_and_module_entry_points(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));

        $response->assertOk();

        // Check key ERP module entry points in sidebar
        $response->assertSee('Purchasing');
        $response->assertSee('Customers &amp; CRM', false);
        $response->assertSee('Product Catalog');
        $response->assertSee('Raw Materials &amp; Stock', false);
        $response->assertSee('POS Terminal');
        $response->assertSee('Settings');
    }

    public function test_purchasing_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('purchasing.suppliers.index'));

        $response->assertOk();
        $response->assertSee('Suppliers');
        $response->assertSee('Purchase Orders (PO)');
        $response->assertSee('Goods Receipt (GRN)');
        $response->assertSee('Supplier Invoices (3-Way)');
        $response->assertSee('Purchase Returns');
    }

    public function test_customer_crm_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('Customer Directory');
        $response->assertSee('Customer Groups');
        $response->assertSee('B2B Credit Control');
        $response->assertSee('Loyalty, Vouchers &amp; Gift Cards', false);
    }

    public function test_sales_b2b_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('sales.orders.index'));

        $response->assertOk();
        $response->assertSee('Sales Orders (SO)');
        $response->assertSee('Quotations');
        $response->assertSee('Invoices &amp; Accounts Receivable (AR)', false);
        $response->assertSee('Returns &amp; Credit Notes', false);
    }

    public function test_finance_hub_is_reachable_by_owner(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('finance.index'));

        $response->assertOk();
        $response->assertSee('Finance & Accounting');
        $response->assertSee('Modul 7');
    }

    public function test_kds_is_reachable_by_kitchen_and_frontline_staff(): void
    {
        foreach ([$this->ownerUser, $this->managerUser, $this->kasirUser, $this->cookUser, $this->baristaUser] as $u) {
            $response = $this->actingAs($u)->get(route('kds.index'));
            $response->assertOk();
        }
    }

    public function test_all_sales_b2b_subpages_are_reachable(): void
    {
        $salesRoutes = [
            'sales.orders.index',
            'sales.quotations.index',
            'sales.invoices.index',
            'sales.returns.index',
        ];

        foreach ($salesRoutes as $route) {
            $this->actingAs($this->ownerUser)->get(route($route))->assertOk();
            $this->actingAs($this->salesUser)->get(route($route))->assertOk();
        }
    }

    public function test_all_finance_subpages_are_reachable(): void
    {
        $financeRoutes = [
            'finance.index',
            'finance.coa.index',
            'finance.journals.index',
            'finance.periods.index',
            'finance.reconciliation.index',
        ];

        foreach ($financeRoutes as $route) {
            $this->actingAs($this->ownerUser)->get(route($route))->assertOk();
            $this->actingAs($this->financeUser)->get(route($route))->assertOk();
        }
    }

    public function test_sales_and_finance_and_kitchen_roles_render_active_sidebar_items(): void
    {
        // Sales role sees B2B Sales Orders
        $salesResp = $this->actingAs($this->salesUser)->get(route('sales.orders.index'));
        $salesResp->assertOk();
        $salesResp->assertSee('B2B Sales Orders');

        // Finance role sees B2B Sales Orders and Finance Overview
        $finResp = $this->actingAs($this->financeUser)->get(route('finance.index'));
        $finResp->assertOk();
        $finResp->assertSee('Finance Overview');
        $finResp->assertSee('B2B Sales Orders');

        // Cook role sees KDS
        $cookResp = $this->actingAs($this->cookUser)->get(route('kds.index'));
        $cookResp->assertOk();
    }

    public function test_unauthorized_role_cannot_reach_restricted_modules(): void
    {
        // Kasir cannot reach Purchasing
        $response = $this->actingAs($this->kasirUser)->get(route('purchasing.suppliers.index'));
        $this->assertEquals(403, $response->status());

        // Kasir cannot reach Finance
        $response = $this->actingAs($this->kasirUser)->get(route('finance.index'));
        $this->assertEquals(403, $response->status());

        // Kasir cannot reach Stores management
        $response = $this->actingAs($this->kasirUser)->get(route('stores.index'));
        $this->assertEquals(403, $response->status());
    }

    public function test_home_controller_dispatches_all_canonical_and_custom_roles_without_dead_loop(): void
    {
        $this->actingAs($this->ownerUser)->get(route('home'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->managerUser)->get(route('home'))->assertRedirect(route('reports.sales'));
        $this->actingAs($this->kasirUser)->get(route('home'))->assertRedirect(route('transaction.create'));
        $this->actingAs($this->financeUser)->get(route('home'))->assertRedirect(route('finance.index'));
        $this->actingAs($this->salesUser)->get(route('home'))->assertRedirect(route('sales.orders.index'));
        $this->actingAs($this->cookUser)->get(route('home'))->assertRedirect(route('kds.index'));
        $this->actingAs($this->baristaUser)->get(route('home'))->assertRedirect(route('kds.index'));

        $auditorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Internal Auditor',
            'permissions' => ['finance.view', 'audit_logs.view'],
        ]);
        $auditorUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $auditorRole->id,
        ]);
        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $auditorUser->id,
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($auditorUser)->get(route('home'))->assertRedirect(route('finance.index'));
        $this->actingAs($auditorUser)->get(route('finance.index'))->assertOk();
        $this->actingAs($auditorUser)->get(route('activity-logs.index'))->assertOk();
    }

    public function test_rescued_orphan_routes_render_unified_module_sub_nav(): void
    {
        $promoResp = $this->actingAs($this->ownerUser)->get(route('pricing.promotions.index'));
        $promoResp->assertOk();
        $promoResp->assertSee('Promotions & Pricing Navigation');
        $promoResp->assertSee('Promotion Campaigns');
        $promoResp->assertSee('Vouchers &amp; Coupons', false);

        $voucherResp = $this->actingAs($this->ownerUser)->get(route('pricing.vouchers.index'));
        $voucherResp->assertOk();
        $voucherResp->assertSee('Promotions & Pricing Navigation');

        $discountResp = $this->actingAs($this->ownerUser)->get(route('discounts.index'));
        $discountResp->assertOk();
        $discountResp->assertSee('Promotions & Pricing Navigation');
    }

    public function test_owner_role_immutability_and_permission_normalization(): void
    {
        $roleService = app(\App\Modules\Users\Services\RoleService::class);
        $ownerRole = $this->ownerUser->role;

        // 1. Attempting to downgrade Owner permissions or deactivate Owner role preserves wildcard & active state
        $updatedOwner = $roleService->update($ownerRole, [
            'name' => 'Hacked Name',
            'is_active' => false,
            'permissions' => ['orders.view'],
        ]);
        $this->assertSame('Owner', $updatedOwner->name);
        $this->assertTrue((bool) $updatedOwner->is_active);
        $this->assertSame(['*'], $updatedOwner->permissions);

        // 2. Attempting to delete Owner role throws exception even if no users assigned
        $emptyOwnerRole = new Role([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);
        $this->expectException(\Exception::class);
        $roleService->delete($emptyOwnerRole);
    }

    public function test_role_service_normalizes_sequential_and_associative_permissions(): void
    {
        $roleService = app(\App\Modules\Users\Services\RoleService::class);

        $customRole = $roleService->store([
            'tenant_id' => $this->tenant->id,
            'role_code' => 'ROL-CUSTOM',
            'name' => 'Branch Lead',
            'permissions' => [
                'can_access_pos' => '1',
                'can_void_order' => '0',
                0 => 'inventory.view',
            ],
            'is_active' => true,
        ]);

        $this->assertContains('can_access_pos', $customRole->permissions);
        $this->assertContains('orders.create', $customRole->permissions);
        $this->assertContains('inventory.view', $customRole->permissions);
        $this->assertNotContains('can_void_order', $customRole->permissions);

        $staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $customRole->id,
        ]);
        $this->assertTrue($staff->hasPermission('orders.create'));
        $this->assertTrue($staff->hasPermission('inventory.view'));
        $this->assertFalse($staff->hasPermission('orders.void'));
    }
}
