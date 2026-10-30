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

class Module3NavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Store $store;

    protected User $owner;

    protected User $manager;

    protected User $inventory;

    protected User $kasir;

    protected User $waiter;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $this->tenant = Tenant::create([
            'name' => 'Module 3 Nav Tenant',
            'slug' => 'module-3-nav-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Module 3 Nav Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $roleOwner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $roleManager = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'permissions' => ['dashboard', 'inventory.*']]);
        $roleInventory = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Inventory', 'permissions' => ['inventory.*']]);
        $roleKasir = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Kasir', 'permissions' => ['orders.*']]);
        $roleWaiter = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Waiter', 'permissions' => ['orders.view']]);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $roleOwner->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $roleManager->id]);
        $this->inventory = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $roleInventory->id]);
        $this->kasir = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $roleKasir->id]);
        $this->waiter = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $roleWaiter->id]);

        foreach ([$this->owner, $this->manager, $this->inventory, $this->kasir, $this->waiter] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    public function test_all_module_3_catalog_routes_exist(): void
    {
        $expectedRoutes = [
            'products.index',
            'products.create',
            'products.export',
            'categories.index',
            'categories.create',
            'categories.export',
            'brands.index',
            'units-of-measure.index',
            'inventory.modifiers',
            'tax-classes.index',
            'assortments.index',
            'price-lists.index',
            'price-lists.create',
        ];

        foreach ($expectedRoutes as $routeName) {
            $this->assertTrue(Route::has($routeName), "Route '{$routeName}' is not registered.");
        }
    }

    public function test_authorized_users_can_access_module_3_navigation_entry_points(): void
    {
        $endpoints = [
            route('products.index'),
            route('categories.index'),
            route('brands.index'),
            route('units-of-measure.index'),
            route('inventory.modifiers'),
            route('tax-classes.index'),
            route('assortments.index'),
            route('price-lists.index'),
        ];

        // Owner, Manager, and Inventory must have 200 OK access
        foreach ([$this->owner, $this->manager, $this->inventory] as $user) {
            foreach ($endpoints as $url) {
                $response = $this->actingAs($user)->get($url);
                $this->assertEquals(
                    200,
                    $response->status(),
                    "User {$user->role->name} failed to access {$url} (Status: {$response->status()})"
                );
            }
        }
    }

    public function test_unauthorized_users_are_forbidden_from_module_3_catalog_endpoints(): void
    {
        $endpoints = [
            route('products.index'),
            route('categories.index'),
            route('brands.index'),
            route('units-of-measure.index'),
            route('inventory.modifiers'),
            route('tax-classes.index'),
            route('assortments.index'),
            route('price-lists.index'),
        ];

        // Kasir and Waiter must receive 403 Forbidden
        foreach ([$this->kasir, $this->waiter] as $user) {
            foreach ($endpoints as $url) {
                $response = $this->actingAs($user)->get($url);
                $this->assertEquals(
                    403,
                    $response->status(),
                    "User {$user->role->name} should be forbidden from {$url} (Status: {$response->status()})"
                );
            }
        }
    }

    public function test_sub_nav_and_sidebar_active_state_synchronization(): void
    {
        $this->actingAs($this->owner);

        // 1. Visit Products index -> Sidebar 'Produk' is active, Sub-nav 'Produk' is active
        $response = $this->get(route('products.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('before:bg-[#202020]', false); // Sidebar active accent

        // 2. Visit Brands index -> Sidebar 'Produk' is active, Sub-nav 'Merek' is active
        $response = $this->get(route('brands.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false); // Sidebar active accent on parent section

        // 3. Visit Units of Measure index -> Sidebar 'Produk' is active, Sub-nav 'Satuan Ukur' is active
        $response = $this->get(route('units-of-measure.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false);

        // 4. Visit Tax Classes index -> Sidebar 'Produk' is active, Sub-nav 'Kelas Pajak' is active
        $response = $this->get(route('tax-classes.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false);

        // 5. Visit Assortments index -> Sidebar 'Produk' is active, Sub-nav 'Asortimen Cabang' is active
        $response = $this->get(route('assortments.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false);

        // 6. Visit Price Lists index -> Sidebar 'Produk' is active, Sub-nav 'Daftar Harga' is active
        $response = $this->get(route('price-lists.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false);

        // 7. Visit Categories index -> Sidebar 'Kategori' is active, Sub-nav 'Kategori' is active
        $response = $this->get(route('categories.index'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
        $response->assertSee('before:bg-[#202020]', false);

        // 8. Visit Modifiers manager -> Sidebar 'Varian & Modifier' is active, Sub-nav is present
        $response = $this->get(route('inventory.modifiers'));
        $response->assertOk();
        $response->assertSee('Product Catalog Navigation');
    }

    public function test_sub_nav_wcag_and_touch_target_attributes(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get(route('products.index'));
        $response->assertOk();

        // WCAG and touch target tokens
        $response->assertSee('aria-label="Product Catalog Navigation"', false);
        $response->assertSee('min-h-[40px]', false);
        $response->assertSee('wire:navigate', false);
        $response->assertSee('window.apeironPosCartDirty', false);
    }
}
