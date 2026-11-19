<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Platform\Navigation\NavigationRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Governance Law G-3 — backend authorization is authoritative.
 *
 * Custom roles resolve route access from permission codes, never from canonical
 * role names. This suite pins the exact access matrix BEFORE RoleMiddleware is
 * decoupled from the navigation config (RISK-NAV-02), so the refactor can be
 * proven behaviour-preserving.
 */
class CustomRoleAuthorizationEquivalenceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    /** @var array<string, User> */
    private array $users = [];

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Custom Role Tenant',
            'slug' => 'custom-role-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Custom Role Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $matrix = [
            // A finance auditor: may read the ledger, may not operate the till.
            'Internal Auditor' => ['finance.view', 'reports.analytics.view'],
            // A floor lead: may serve customers, may not touch the ledger.
            'Floor Lead' => ['orders.create', 'orders.view', 'customers.view', 'shifts.own'],
            // A stock clerk with purchasing authority.
            'Stock Clerk' => ['inventory.view', 'purchasing.view', 'products.view'],
            // A role with no permissions at all.
            'Read Only' => [],
        ];

        foreach ($matrix as $name => $permissions) {
            $role = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'permissions' => $permissions,
            ]);

            $user = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $role->id,
            ]);

            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'store_id' => $this->store->id,
            ]);

            $this->users[$name] = $user;
        }
    }

    // ── Allowed ────────────────────────────────────────────────────

    public function test_auditor_reaches_the_finance_ledger(): void
    {
        $this->actingAs($this->users['Internal Auditor'])
            ->get(route('finance.index'))
            ->assertOk();
    }

    public function test_custom_role_is_blocked_by_the_pos_component_guard(): void
    {
        $this->actingAs($this->users['Floor Lead'])
            ->get(route('transaction.create'))
            ->assertForbidden()
            ->assertSee('403');
    }

    /**
     * DOCUMENTED PRE-EXISTING LIMITATION (not introduced by ADR-NAV-006).
     *
     * Several Livewire components guard themselves by hard-coding canonical role
     * names and never consult the permission they were granted:
     *   - OrderHistory::boot()        -> ['Owner','Manager','Kasir','Supervisor',...]
     *   - IngredientManager::boot()   -> ['Owner','Manager','Inventory']
     *   - AdvancedAnalyticsDashboard   -> canonical role name check
     *   - CreateOrder::boot()         -> ['Owner','Manager','Kasir','Waiter']
     *
     * A custom role therefore passes RoleMiddleware but is rejected by the
     * component. Verified against the pre-change tree: every assertion below
     * fails identically before and after the middleware decoupling, so the
     * refactor is behaviour-preserving. Recorded as executable specification so
     * it cannot change silently; closing the gap is OQ-03.
     */
    public function test_custom_roles_are_still_blocked_by_component_level_role_checks(): void
    {
        $this->actingAs($this->users['Floor Lead'])
            ->get(route('orders.index'))
            ->assertForbidden();

        $this->actingAs($this->users['Stock Clerk'])
            ->get(route('inventory.ingredients'))
            ->assertForbidden();

        $this->actingAs($this->users['Internal Auditor'])
            ->get(route('reports.analytics'))
            ->assertForbidden();
    }

    public function test_stock_clerk_reaches_procurement(): void
    {
        $this->actingAs($this->users['Stock Clerk'])
            ->get(route('purchasing.orders.index'))
            ->assertOk();
    }

    // ── Denied ─────────────────────────────────────────────────────

    public function test_auditor_cannot_operate_the_pos(): void
    {
        $this->actingAs($this->users['Internal Auditor'])
            ->get(route('transaction.create'))
            ->assertForbidden();
    }

    public function test_floor_lead_cannot_reach_the_ledger(): void
    {
        $this->actingAs($this->users['Floor Lead'])
            ->get(route('finance.index'))
            ->assertForbidden();
    }

    public function test_stock_clerk_cannot_reach_payroll(): void
    {
        $this->actingAs($this->users['Stock Clerk'])
            ->get(route('hr.payroll.index'))
            ->assertForbidden();
    }

    public function test_read_only_role_reaches_nothing_operational(): void
    {
        foreach (['transaction.create', 'finance.index', 'inventory.ingredients', 'hr.employees.index'] as $route) {
            $this->actingAs($this->users['Read Only'])
                ->get(route($route))
                ->assertForbidden();
        }
    }

    // ── Middleware has no navigation dependency (G-3) ───────────────

    public function test_role_middleware_no_longer_reads_the_legacy_navigation_config(): void
    {
        $source = (string) file_get_contents(app_path('Http/Middleware/RoleMiddleware.php'));

        $this->assertStringNotContainsString(
            "config('navigation.sections'",
            $source,
            'RoleMiddleware must not read the legacy sidebar config (ADR-NAV-006).',
        );
        $this->assertStringNotContainsString(
            "config('navigation.sub_nav'",
            $source,
            'RoleMiddleware must not read the legacy sub-nav config (ADR-NAV-006).',
        );
    }

    public function test_registry_exposes_permissions_per_route(): void
    {
        $registry = app(NavigationRegistry::class);

        $this->assertContains('finance.view', $registry->permissionsForRoute('finance.index'));
        $this->assertContains('purchasing.invoice.view', $registry->permissionsForRoute('purchasing.invoices.index'));
        $this->assertContains('orders.create', $registry->permissionsForRoute('transaction.create'));
    }
}
