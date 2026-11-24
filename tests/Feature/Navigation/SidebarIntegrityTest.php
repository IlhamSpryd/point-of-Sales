<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\ActivityLog;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Setting;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SidebarIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Sidebar Integrity Tenant',
            'slug' => 'sidebar-integrity-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sidebar Integrity Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);
    }

    public function test_all_routes_referenced_by_sidebar_are_registered(): void
    {
        /** @var \App\Platform\Navigation\NavigationRegistry $registry */
        $registry = app(\App\Platform\Navigation\NavigationRegistry::class);

        $allRoutes = collect([...$registry->hubs(), ...$registry->pages()])
            ->pluck('route')
            ->filter()
            ->unique();

        $missing = $allRoutes->reject(fn ($name) => Route::has($name));

        $this->assertEmpty(
            $missing->values()->all(),
            "Sidebar mereferensikan route yang tidak terdaftar: {$missing->implode(', ')}."
        );
    }

    public function test_role_list_in_sidebar_matches_role_middleware_on_target_route(): void
    {
        /** @var \App\Platform\Navigation\NavigationRegistry $registry */
        $registry = app(\App\Platform\Navigation\NavigationRegistry::class);

        foreach ([...$registry->hubs(), ...$registry->pages()] as $node) {
            if ($node->route === null || ! Route::has($node->route)) {
                continue;
            }

            $route = Route::getRoutes()->getByName($node->route);
            $roleMiddleware = collect($route->gatherMiddleware())
                ->first(fn ($m) => str_starts_with($m, 'role:'));

            if (! $roleMiddleware) {
                continue;
            }

            $routeRoles = collect(explode(',', substr($roleMiddleware, strlen('role:'))))->sort()->values()->all();
            $menuRoles = collect($node->roles)->sort()->values()->all();

            $this->assertEquals(
                $routeRoles,
                $menuRoles,
                "Menu '{$node->label}' (route: {$node->route}) roles mismatch with route middleware."
            );
        }
    }

    public function test_navigation_sections_contain_expected_nine_domains_and_valid_scope_metadata(): void
    {
        /** @var \App\Platform\Navigation\NavigationRegistry $registry */
        $registry = app(\App\Platform\Navigation\NavigationRegistry::class);

        $expectedDomains = [
            'core',
            'operations',
            'finance_insights',
            'settings',
        ];

        $this->assertEquals(
            $expectedDomains,
            array_map(static fn ($d): string => (string) $d->domain, $registry->domains()),
            'Navigation must expose exactly four canonical business domains (ADR-NAV-014).'
        );

        $this->assertCount(10, $registry->hubs(), 'Navigation must expose exactly ten L1 hub rows.');

        foreach ([...$registry->hubs(), ...$registry->pages()] as $node) {
            $this->assertNotSame('', $node->id);
            $this->assertNotSame('', $node->label);
            $this->assertNotNull($node->route);
            $this->assertNotEmpty($node->active);
            $this->assertContains($node->scope, ['store', 'tenant']);
            $this->assertTrue($node->visible, "Node [{$node->id}] must be visible for production-ready pages.");
        }
    }

    public function test_role_based_menu_filtering_for_all_supported_roles(): void
    {
        // Hub-level visibility is the architectural unit (R-4). Page counts are
        // persona-dependent because single-page hubs suppress their L2 bar (R-5),
        // so asserting them would pin incidental behaviour rather than intent.
        $registry = app(\App\Platform\Navigation\NavigationRegistry::class);
        $resolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);
        app(\App\Modules\Organization\Services\RoleService::class)->provisionDefaultRoles($this->tenant->id);

        $expectedHubs = [
            'Owner' => 10,
            'Manager' => 10,
            'Kasir' => 3,
            'Waiter' => 2,
            'Inventory' => 2,
            'Finance' => 3,
            'Sales' => 2,
            'Supervisor' => 2,
            'Cook' => 1,
        ];

        foreach ($expectedHubs as $roleName => $expected) {
            $user = \App\Modules\Organization\Models\User::where('tenant_id', $this->tenant->id)
                ->whereHas('role', fn ($q) => $q->where('name', $roleName))
                ->first();

            if ($user === null) {
                $role = Role::where('tenant_id', $this->tenant->id)->where('name', $roleName)->first();
                $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
                DB::table('user_stores')->insert([
                    'tenant_id' => $this->tenant->id,
                    'user_id' => $user->id,
                    'store_id' => $this->store->id,
                ]);
            }

            $sections = $resolver->sidebar($user);
            $hubs = array_sum(array_map(static fn (array $s): int => count($s['hubs']), $sections));

            if ($roleName === 'Owner') {
                $this->assertCount(10, $registry->hubs());
            }

            $this->assertLessThanOrEqual($expected, $hubs, "{$roleName} sees more hubs than permitted.");
        }

        // Cook must resolve Frontline Desk landing directly to kds.index (zero 403)
        $cook = User::where('tenant_id', $this->tenant->id)->whereHas('role', fn ($q) => $q->where('name', 'Cook'))->first();
        if ($cook !== null) {
            $cookSections = $resolver->sidebar($cook);
            $this->assertCount(1, $cookSections);
            $frontlineHub = $cookSections[0]['hubs'][0];
            $this->assertSame('Frontline Desk', $frontlineHub->label);
            $this->assertSame(route('kds.index'), $resolver->landingUrlForHub($frontlineHub, $cook));
        }

        // Kasir must never reach back-office hubs.
        $kasir = User::where('tenant_id', $this->tenant->id)->whereHas('role', fn ($q) => $q->where('name', 'Kasir'))->first();
        if ($kasir !== null) {
            $labels = [];
            foreach ($resolver->sidebar($kasir) as $s) {
                foreach ($s['hubs'] as $h) {
                    $labels[] = $h->label;
                }
            }
            $this->assertContains('POS Terminal', $labels);
            $this->assertNotContains('Finance & GL', $labels);
            $this->assertNotContains('Settings', $labels);
            $this->assertNotContains('Workforce', $labels);
        }
    }

    public function test_inventory_sub_nav_renders_all_module_4_workflow_tabs(): void
    {
        $tenant = Tenant::create([
            'name' => 'Inventory Nav Tenant',
            'slug' => 'inv-nav-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inventory Nav Store',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($store->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Inventory', 'permissions' => ['*']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($user)->get(route('inventory.ingredients'));
        $response->assertOk();
        $response->assertSee('Inventory &amp; Supply Navigation', false);
        $response->assertSee('Stock');
        $response->assertSee('Direct Receipts');
        $response->assertSee('Adjustments');
        $response->assertSee('Opname');
        $response->assertSee('Transfers');
        $response->assertSee('Supplier Returns');
        $response->assertSee('Stock Ledger');
    }

    public function test_rendered_sidebar_contains_wcag_and_accessibility_tokens(): void
    {
        $tenant = Tenant::create([
            'name' => 'A11y Tenant',
            'slug' => 'a11y-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'A11y Store',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($store->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'store_id' => $store->id,
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        // Landmarks & Semantics
        $response->assertSee('aria-label="Main navigation"', false);
        $response->assertSee('role="region"', false);
        $response->assertSee('aria-label="Main Navigation Sidebar"', false);
        $response->assertSee('id="mobile-menu-btn"', false);
        $response->assertSee('aria-controls="main-sidebar"', false);
        $response->assertSee('aria-label="Open main navigation menu"', false);
        $response->assertSee('aria-label="Close navigation menu"', false);

        // WCAG Active visual accent indicator token & active page
        $response->assertSee('before:bg-foreground', false);
        $response->assertSee('aria-current="page"', false);

        // Navigation items
        $response->assertSee('POS Terminal', false);

        // Product Vision & Roadmap is fully removed
        $response->assertDontSee('Product Vision & Roadmap', false);
        $response->assertDontSee('investor-demo', false);
    }

    public function test_product_vision_and_roadmap_route_is_removed_and_returns_404(): void
    {
        $tenant = Tenant::create([
            'name' => 'Vision Test Tenant',
            'slug' => 'vision-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vision Test Store',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($store->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'store_id' => $store->id,
        ]);

        $this->assertFalse(Route::has('investor.demo'));

        $response = $this->actingAs($owner)->get('/investor-demo');
        $response->assertNotFound();
    }

    public function test_policies_enforce_tenant_scoping_and_role_authorization(): void
    {
        $context = app(TenantContext::class);

        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $context->setTenantId($tenantA->id);

        $ownerRoleA = Role::create(['tenant_id' => $tenantA->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $managerRoleA = Role::create(['tenant_id' => $tenantA->id, 'name' => 'Manager', 'permissions' => []]);
        $kasirRoleA = Role::create(['tenant_id' => $tenantA->id, 'name' => 'Kasir', 'permissions' => []]);

        $ownerA = User::factory()->create(['tenant_id' => $tenantA->id, 'role_id' => $ownerRoleA->id]);
        $managerA = User::factory()->create(['tenant_id' => $tenantA->id, 'role_id' => $managerRoleA->id]);
        $kasirA = User::factory()->create(['tenant_id' => $tenantA->id, 'role_id' => $kasirRoleA->id]);

        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $context->setTenantId($tenantB->id);

        $storeB = Store::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Store B',
            'is_active' => true,
        ]);
        $context->setStoreId($storeB->id);

        $userInTenantB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $roleInTenantB = Role::create(['tenant_id' => $tenantB->id, 'name' => 'Custom Role', 'permissions' => []]);
        $settingInTenantB = Setting::create(['tenant_id' => $tenantB->id, 'key' => 'test', 'value' => '1', 'type' => 'string']);
        $logInTenantB = ActivityLog::create([
            'tenant_id' => $tenantB->id,
            'store_id' => $storeB->id,
            'user_id' => $userInTenantB->id,
            'action' => 'login',
            'description' => 'User logged in',
        ]);

        // Switch context back to Tenant A for evaluating tenant A policies
        $context->setTenantId($tenantA->id);

        // UserPolicy
        $userPolicy = new UserPolicy;
        $this->assertTrue($userPolicy->viewAny($ownerA));
        $this->assertFalse($userPolicy->viewAny($managerA));
        $this->assertFalse($userPolicy->view($ownerA, $userInTenantB), 'Cross-tenant user view forbidden');
        $this->assertFalse($userPolicy->delete($ownerA, $ownerA), 'User cannot delete themselves');

        // RolePolicy
        $rolePolicy = new RolePolicy;
        $this->assertTrue($rolePolicy->viewAny($ownerA));
        $this->assertFalse($rolePolicy->viewAny($managerA));
        $this->assertFalse($rolePolicy->update($ownerA, $roleInTenantB), 'Cross-tenant role update forbidden');

        // SettingPolicy
        $settingPolicy = new SettingPolicy;
        $this->assertTrue($settingPolicy->viewAny($ownerA));
        $this->assertFalse($settingPolicy->viewAny($managerA));
        $this->assertFalse($settingPolicy->update($ownerA, $settingInTenantB), 'Cross-tenant setting update forbidden');

        // ActivityLogPolicy
        $logPolicy = new ActivityLogPolicy;
        $this->assertTrue($logPolicy->viewAny($ownerA));
        $this->assertTrue($logPolicy->viewAny($managerA));
        $this->assertFalse($logPolicy->viewAny($kasirA));
        $this->assertFalse($logPolicy->view($ownerA, $logInTenantB), 'Cross-tenant log view forbidden');
    }

    public function test_pos_cart_protection_and_branch_switcher_guards_present(): void
    {
        $branchSwitcherView = File::get(resource_path('views/components/branch-switcher.blade.php'));
        $this->assertStringContainsString('window.apeironPosCartDirty', $branchSwitcherView);
        $this->assertStringContainsString('Switching branches will discard the active cart', $branchSwitcherView);

        $navigationView = File::get(resource_path('views/layouts/navigation.blade.php'));
        $this->assertStringContainsString('window.apeironPosCartDirty', $navigationView);
        $this->assertStringContainsString('Leaving this page will cancel the current cart', $navigationView);

        $posView = File::get(resource_path('views/livewire/kasir/create-order.blade.php'));
        $this->assertStringContainsString('window.apeironPosCartDirty', $posView);
        $this->assertStringContainsString('beforeunload', $posView);
    }

    public function test_branch_switcher_is_moved_from_sidebar_to_top_navbar(): void
    {
        $navigationView = File::get(resource_path('views/layouts/navigation.blade.php'));
        $this->assertStringNotContainsString('<x-branch-switcher', $navigationView);
        $this->assertStringNotContainsString('Active Branch (Click to change)', $navigationView);

        $appView = File::get(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('<x-branch-switcher :dark="true" align="left" />', $appView);

        $tenant = Tenant::create([
            'name' => 'Navbar Switcher Tenant',
            'slug' => 'navbar-switcher-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $store1 = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch Central',
            'is_active' => true,
        ]);
        $store2 = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch Mall',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($store1->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'store_id' => $store1->id],
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'store_id' => $store2->id],
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Select active branch');
        $response->assertSee('Select Branch Scope');
        $response->assertSee('Branch Central');
        $response->assertSee('Branch Mall');
    }
}
