<?php

declare(strict_types=1);

namespace Tests\Feature\Domain3;

use App\Livewire\AsyncExportButton;
use App\Livewire\ChannelMappingManager;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\ShiftManager;
use App\Models\ChannelProductMapping;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Domain3ConsolidatedRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $storeA;
    protected Store $storeB;
    protected Role $ownerRole;
    protected Role $managerRole;
    protected Role $cashierRole;
    protected Role $waiterRole;
    protected User $owner;
    protected User $manager;
    protected User $cashier;
    protected User $waiter;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Remediation Test Tenant',
            'slug' => 'remediation-test-tenant',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama',
            'is_active' => true,
        ]);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Kedua',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $this->ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->managerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager',
            'permissions' => ['orders.*', 'inventory.*', 'reports.*', 'shifts.*'],
        ]);

        $this->cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'shifts.create'],
        ]);

        $this->waiterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Waiter',
            'permissions' => ['orders.view'],
        ]);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->managerRole->id,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
        ]);

        $this->waiter = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->waiterRole->id,
        ]);

        foreach ([$this->owner, $this->manager, $this->cashier, $this->waiter] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->storeA->id,
            ]);
        }
    }

    /**
     * CF-02: ChannelMappingManager Otorisasi & IDOR Cross-Tenant.
     */
    public function test_channel_mapping_manager_authorization_and_cross_tenant_protection(): void
    {
        // 1. Waiter and Cashier rejected with 403
        Livewire::actingAs($this->waiter)
            ->test(ChannelMappingManager::class)
            ->assertStatus(403);

        Livewire::actingAs($this->cashier)
            ->test(ChannelMappingManager::class)
            ->assertStatus(403);

        // 2. Manager and Owner allowed
        Livewire::actingAs($this->manager)
            ->test(ChannelMappingManager::class)
            ->assertOk();

        // 3. IDOR: Cannot delete mapping of another tenant
        $tenantRival = Tenant::create([
            'name' => 'Tenant Rival',
            'slug' => 'tenant-rival',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($tenantRival->id);
        $storeRival = Store::create([
            'tenant_id' => $tenantRival->id,
            'name' => 'Store Rival',
            'is_active' => true,
        ]);
        app(TenantContext::class)->setStoreId($storeRival->id);

        $categoryRival = \App\Models\Category::create([
            'tenant_id' => $tenantRival->id,
            'store_id' => $storeRival->id,
            'category_name' => 'Rival Category',
        ]);

        $productRival = Product::create([
            'tenant_id' => $tenantRival->id,
            'store_id' => $storeRival->id,
            'category_id' => $categoryRival->id,
            'product_name' => 'Rival Coffee',
            'product_price' => 20000,
            'is_active' => true,
        ]);
        $rivalMapping = ChannelProductMapping::create([
            'tenant_id' => $tenantRival->id,
            'provider' => 'grabfood',
            'external_product_id' => 'RIVAL-101',
            'product_id' => $productRival->id,
        ]);

        // Switch back to Tenant A
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Manager of Tenant A attempts to delete rivalMapping
        Livewire::actingAs($this->manager)
            ->test(ChannelMappingManager::class)
            ->call('deleteMapping', $rivalMapping->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('channel_product_mappings', [
            'id' => $rivalMapping->id,
            'tenant_id' => $tenantRival->id,
        ]);
    }

    /**
     * CF-02: ShiftManager::closeShift() otorisasi server-side.
     */
    public function test_shift_manager_close_shift_authorization(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        // Waiter is rejected with 403 when trying to access or close
        Livewire::actingAs($this->waiter)
            ->test(ShiftManager::class)
            ->assertStatus(403);

        // Cashier can view and close their active shift
        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->assertOk()
            ->set('closing_balance', 100000)
            ->call('closeShift')
            ->assertHasNoErrors();

        $this->assertEquals('closed', $shift->fresh()->status);
    }

    /**
     * CF-01: CreateOrder menghitung pajak dan total berdasarkan Setting::get('tax_rate') dinamis.
     */
    public function test_create_order_tax_and_rounding_reads_dynamic_setting(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $category = \App\Models\Category::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'category_name' => 'Tea Category',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'category_id' => $category->id,
            'product_name' => 'Specialty Tea',
            'product_price' => 50000,
            'is_active' => true,
        ]);

        // Set dynamic tax_rate = 0.10 (10%)
        Setting::set('tax_rate', '0.10');
        Setting::set('rounding_value', '1');

        $component = Livewire::actingAs($this->cashier)
            ->test(CreateOrder::class)
            ->call('addToCartDirectly', $product->id);

        // Subtotal = 50,000; Tax (10%) = 5,000; Total = 55,000
        $this->assertEquals(50000, $component->get('subtotal'));
        $this->assertEquals(5000, $component->get('taxAmount'));
        $this->assertEquals(55000, $component->get('totalAmount'));
        $this->assertEquals('10', $component->get('taxRatePercent'));
    }

    /**
     * CF-07 & D3-013: IngredientManager saldo multi-cabang & pencegahan double-write.
     */
    public function test_ingredient_manager_stock_integrity_and_branch_scoping(): void
    {
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi Arabica',
            'unit' => 'gram',
            'cost_per_unit' => 200,
            'reorder_level' => 100,
            'current_stock' => 500,
            'is_active' => true,
        ]);

        // In store A, quantity is 500
        $initialBalance = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('quantity');
        $this->assertEquals(500, (float) $initialBalance);

        // 1. Negative quantity on purchase_receipt must be rejected
        Livewire::actingAs($this->manager)
            ->test(IngredientManager::class)
            ->call('adjustStock', $ingredient->id)
            ->set('adjustType', 'purchase_receipt')
            ->set('adjustQuantity', -50)
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustQuantity']);

        // 2. Positive adjustment +100 must increment exactly +100 once (no double-write)
        Livewire::actingAs($this->manager)
            ->test(IngredientManager::class)
            ->call('adjustStock', $ingredient->id)
            ->set('adjustType', 'purchase_receipt')
            ->set('adjustQuantity', 100)
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        $newBalance = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->storeA->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('quantity');

        // MUST be exactly 600, NOT 700 (double-write would make it 700)
        $this->assertEquals(600, (float) $newBalance);

        // 3. Excessive waste that exceeds current balance is rejected
        Livewire::actingAs($this->manager)
            ->test(IngredientManager::class)
            ->call('adjustStock', $ingredient->id)
            ->set('adjustType', 'waste')
            ->set('adjustQuantity', 700)
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustQuantity']);
    }

    /**
     * CF-11: ModifierManager memvalidasi otorisasi dan memanggil MenuCacheService::flush().
     */
    public function test_modifier_manager_calls_menu_cache_flush(): void
    {
        // Spy on MenuCacheService
        $mock = $this->createMock(MenuCacheService::class);
        $mock->expects($this->atLeastOnce())->method('flush');
        $this->app->instance(MenuCacheService::class, $mock);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ukuran Gelas',
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        Livewire::actingAs($this->manager)
            ->test(ModifierManager::class)
            ->call('createModifier', $group->id)
            ->set('modifierForm.name', 'Large 24oz')
            ->set('modifierForm.extra_price', 5000)
            ->set('modifierForm.is_default', false)
            ->set('modifierForm.ingredients', [])
            ->call('saveModifier')
            ->assertHasNoErrors();
    }

    /**
     * CF-10: OrderPolicy::sameStore memeriksa relasi user_stores pivot.
     */
    public function test_order_policy_verifies_user_stores_pivot(): void
    {
        $policy = new OrderPolicy();

        // Create an order in store A
        $orderA = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-STORE-A',
            'order_status' => \App\Enums\OrderStatus::Paid,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Create an order in store B
        app(TenantContext::class)->setStoreId($this->storeB->id);
        $orderB = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-STORE-B',
            'order_status' => \App\Enums\OrderStatus::Paid,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Cashier has user_stores relation to Store A only
        $this->assertTrue($policy->view($this->cashier, $orderA));
        $this->assertFalse($policy->view($this->cashier, $orderB));

        // Owner can access both stores
        $this->assertTrue($policy->view($this->owner, $orderA));
        $this->assertTrue($policy->view($this->owner, $orderB));
    }

    /**
     * CF-13 & D3-012: AsyncExportButton memvalidasi rentang tanggal dan merender @error('range').
     */
    public function test_async_export_button_validates_dates_and_renders_range_error(): void
    {
        // Invalid range: startDate > endDate
        Livewire::actingAs($this->manager)
            ->test(AsyncExportButton::class, [
                'startDate' => '2026-10-15',
                'endDate' => '2026-10-10',
            ])
            ->call('requestExport')
            ->assertHasErrors(['endDate']);

        // Range > 366 days should be rejected by ReportService and render @error('range') in HTML
        Livewire::actingAs($this->manager)
            ->test(AsyncExportButton::class, [
                'startDate' => '2024-01-01',
                'endDate' => '2026-01-01',
            ])
            ->call('requestExport')
            ->assertHasErrors(['range'])
            ->assertSee('366 hari');
    }

    /**
     * D3-011: Memverifikasi tidak ada skrip CDN Alpine.js eksternal di seluruh resources/views/.
     */
    public function test_no_external_alpine_cdn_in_blade_views(): void
    {
        $viewsPath = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsPath));
        $violatingFiles = [];

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $content = file_get_contents($file->getPathname());
                if (str_contains($content, 'cdn.jsdelivr.net/npm/alpinejs')) {
                    $violatingFiles[] = str_replace($viewsPath . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertEmpty(
            $violatingFiles,
            'Ditemukan skrip Alpine CDN eksternal pada view berikut: ' . implode(', ', $violatingFiles)
        );
    }
}
