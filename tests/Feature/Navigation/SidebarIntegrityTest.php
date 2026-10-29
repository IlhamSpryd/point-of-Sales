<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SidebarIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_routes_referenced_by_sidebar_are_registered(): void
    {
        $allRoutes = collect(config('navigation.sections'))->flatten(1)->pluck('route');

        $missing = $allRoutes->reject(fn ($name) => Route::has($name));

        $this->assertEmpty(
            $missing->values()->all(),
            "Sidebar mereferensikan route yang tidak terdaftar: {$missing->implode(', ')}."
        );
    }

    public function test_role_list_in_sidebar_matches_role_middleware_on_target_route(): void
    {
        foreach (collect(config('navigation.sections'))->flatten(1) as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }

            $route = Route::getRoutes()->getByName($item['route']);
            $roleMiddleware = collect($route->gatherMiddleware())
                ->first(fn ($m) => str_starts_with($m, 'role:'));

            if (! $roleMiddleware) {
                continue;
            }

            $routeRoles = collect(explode(',', substr($roleMiddleware, strlen('role:'))))->sort()->values()->all();
            $menuRoles = collect($item['roles'])->sort()->values()->all();

            $this->assertEquals(
                $routeRoles,
                $menuRoles,
                "Menu '{$item['label']}' (route: {$item['route']}) roles mismatch with route middleware."
            );
        }
    }

    public function test_navigation_sections_contain_expected_six_pillars_and_valid_scope_metadata(): void
    {
        $sections = config('navigation.sections');

        $expectedGroups = [
            'Ikhtisar',
            'Kasir & Operasional',
            'Katalog & Resep',
            'Inventori & Suplai',
            'Analitik & Pelanggan',
            'Pengaturan & Tata Kelola',
        ];

        $this->assertEquals(
            $expectedGroups,
            array_keys($sections),
            'Navigation groups must match the 6-pillar Task-Oriented ERP Information Architecture.'
        );

        foreach (collect($sections)->flatten(1) as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('icon', $item);
            $this->assertArrayHasKey('route', $item);
            $this->assertArrayHasKey('active', $item);
            $this->assertArrayHasKey('roles', $item);
            $this->assertArrayHasKey('scope', $item);
            $this->assertContains(
                $item['scope'],
                ['store', 'tenant'],
                "Menu item '{$item['label']}' has invalid scope '{$item['scope']}'."
            );
        }
    }

    public function test_role_based_menu_filtering_for_all_supported_roles(): void
    {
        $sections = config('navigation.sections');

        // Helper to extract visible route names for a role
        $getVisibleRoutes = function (string $roleName) use ($sections): array {
            return collect($sections)
                ->flatten(1)
                ->filter(fn ($item) => in_array($roleName, $item['roles'], true))
                ->pluck('route')
                ->values()
                ->all();
        };

        // 1. Owner sees 20 operational items across 6 ERP pillars
        $ownerRoutes = $getVisibleRoutes('Owner');
        $this->assertCount(20, $ownerRoutes);

        // 2. Manager sees 16 items (all except stores, users, roles, settings)
        $managerRoutes = $getVisibleRoutes('Manager');
        $this->assertCount(16, $managerRoutes);
        $this->assertNotContains('stores.index', $managerRoutes);
        $this->assertNotContains('users.index', $managerRoutes);
        $this->assertNotContains('roles.index', $managerRoutes);
        $this->assertNotContains('settings.index', $managerRoutes);

        // 3. Kasir sees frontline operational items (POS, Pesanan, Shift)
        $kasirRoutes = $getVisibleRoutes('Kasir');
        $this->assertEqualsCanonicalizing(
            ['transaction.create', 'shifts.index', 'orders.index'],
            $kasirRoutes
        );

        // 4. Waiter sees orders
        $waiterRoutes = $getVisibleRoutes('Waiter');
        $this->assertEqualsCanonicalizing(
            ['orders.index'],
            $waiterRoutes
        );

        // 5. Inventory staff sees 7 catalog and inventory operations items
        $inventoryRoutes = $getVisibleRoutes('Inventory');
        $this->assertEqualsCanonicalizing(
            [
                'products.index',
                'price-lists.index',
                'assortments.index',
                'inventory.ingredients',
                'inventory.goods-receipts',
                'inventory.ledger',
                'purchasing.suppliers.index',
            ],
            $inventoryRoutes
        );
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
        $response->assertSee('Sub-navigasi Manajemen Inventori');
        $response->assertSee('Bahan Baku');
        $response->assertSee('Penerimaan Barang');
        $response->assertSee('Penyesuaian Stok');
        $response->assertSee('Stock Opname');
        $response->assertSee('Transfer Antarcabang');
        $response->assertSee('Retur Supplier');
        $response->assertSee('Riwayat Mutasi');
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
        $response->assertSee('aria-label="Navigasi utama"', false);
        $response->assertSee('role="region"', false);
        $response->assertSee('aria-label="Sidebar Navigasi Utama"', false);
        $response->assertSee('id="mobile-menu-btn"', false);
        $response->assertSee('aria-controls="main-sidebar"', false);
        $response->assertSee('aria-label="Buka menu navigasi utama"', false);
        $response->assertSee('aria-label="Tutup menu navigasi"', false);

        // WCAG Active visual accent indicator token & active page
        $response->assertSee('before:bg-[#202020]', false);
        $response->assertSee('aria-current="page"', false);

        // Scope badges
        $response->assertSee('Cabang', false);
        $response->assertSee('Tenant', false);
        $response->assertSee('Workstation', false);
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
        $this->assertStringContainsString('Pindah cabang akan membatalkan keranjang aktif', $branchSwitcherView);

        $navigationView = File::get(resource_path('views/layouts/navigation.blade.php'));
        $this->assertStringContainsString('window.apeironPosCartDirty', $navigationView);
        $this->assertStringContainsString('Pindah halaman akan membatalkan keranjang aktif', $navigationView);

        $posView = File::get(resource_path('views/livewire/kasir/create-order.blade.php'));
        $this->assertStringContainsString('window.apeironPosCartDirty', $posView);
        $this->assertStringContainsString('beforeunload', $posView);
    }
}
