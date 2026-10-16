<?php

declare(strict_types=1);

namespace Tests\Feature\Domain3;

use App\Enums\ExportTaskStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\PreparationStatus;
use App\Events\WaiterCalled;
use App\Livewire\AsyncExportButton;
use App\Livewire\ChannelMappingManager;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\ShiftManager;
use App\Models\Category;
use App\Models\ChannelProductMapping;
use App\Models\ExportTask;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\CartService;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use App\Services\ReportService;
use App\Services\TransactionService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
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

        $categoryRival = Category::create([
            'tenant_id' => $tenantRival->id,
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

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
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
        $policy = new OrderPolicy;

        // Create an order in store A
        $orderA = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-STORE-A',
            'order_status' => OrderStatus::Paid,
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
            'order_status' => OrderStatus::Paid,
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
                    $violatingFiles[] = str_replace($viewsPath.'/', '', $file->getPathname());
                }
            }
        }

        $this->assertEmpty(
            $violatingFiles,
            'Ditemukan skrip Alpine CDN eksternal pada view berikut: '.implode(', ', $violatingFiles)
        );
    }

    /**
     * D3-011 / CF-13: Isolasi keranjang CartService antar meja, toko, dan tenant.
     */
    public function test_cart_service_session_isolation_across_tables_and_stores(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $table1 = Table::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'table_code' => 'T01',
            'table_name' => 'Meja 1',
            'capacity' => 4,
            'secure_token' => 'token-table-1',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $table2 = Table::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'table_code' => 'T02',
            'table_name' => 'Meja 2',
            'capacity' => 4,
            'secure_token' => 'token-table-2',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Espresso Meja 1',
            'product_price' => 20000,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Latte Meja 2',
            'product_price' => 30000,
            'is_active' => true,
        ]);

        $cartService = app(CartService::class);

        // Simulasi Pelanggan 1 di Meja 1
        Session::put('current_tenant_id', $this->tenant->id);
        Session::put('current_store_id', $this->storeA->id);
        Session::put('current_table_id', $table1->id);

        $cartService->addItem($productA, [], 2);
        $this->assertEquals(2, $cartService->getTotalQty());
        $this->assertEquals(40000, $cartService->getSubtotal());

        // Pastikan TIDAK ADA dual-write ke key global 'customer_cart'
        $this->assertFalse(Session::has('customer_cart'));

        // Simulasi snapshot di Meja 1
        $cartService->snapshot();
        $this->assertFalse(Session::has('customer_cart_last'));
        $this->assertTrue(Session::has('customer_cart_last_'.$cartService->getSessionKey()));

        // Berpindah ke Meja 2 di browser yang sama
        Session::put('current_table_id', $table2->id);

        // Meja 2 HARUS KOSONG (tidak boleh mewarisi isi meja 1 via fallback global)
        $this->assertEquals(0, $cartService->getTotalQty());
        $this->assertEmpty($cartService->getItems());

        // Restore snapshot di Meja 2 harus gagal karena snapshot milik Meja 1
        $this->assertFalse($cartService->restoreSnapshot());

        // Tambah item di Meja 2
        $cartService->addItem($productB, [], 1);
        $this->assertEquals(1, $cartService->getTotalQty());
        $this->assertEquals(30000, $cartService->getSubtotal());

        // Kembali ke Meja 1: isi Meja 1 masih tetap 2 item
        Session::put('current_table_id', $table1->id);
        $this->assertEquals(2, $cartService->getTotalQty());
        $this->assertEquals(40000, $cartService->getSubtotal());

        // Clear Meja 1 tidak menghapus Meja 2
        $cartService->clear();
        $this->assertEquals(0, $cartService->getTotalQty());

        Session::put('current_table_id', $table2->id);
        $this->assertEquals(1, $cartService->getTotalQty());
        $this->assertEquals(30000, $cartService->getSubtotal());
    }

    /**
     * D3-009 / CF-11: Mode konsolidasi OrderHistory dapat me-load relasi dan men-void pesanan cabang kedua.
     */
    public function test_owner_consolidated_order_history_eager_loads_and_voids_other_store_orders(): void
    {
        $context = app(TenantContext::class);
        $context->setStoreId($this->storeB->id);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kategori B',
        ]);

        $tableB = Table::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'table_code' => 'TB-01',
            'table_name' => 'Meja B01',
            'capacity' => 2,
            'secure_token' => 'token-store-b',
            'is_active' => true,
            'operational_status' => 'available',
        ]);

        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Cabang B',
            'product_price' => 25000,
            'is_active' => true,
        ]);

        // Buat Order di Store B
        $orderB = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'user_id' => $this->owner->id,
            'table_id' => $tableB->id,
            'order_code' => 'ORD-STORE-B-TEST',
            'order_status' => OrderStatus::Paid,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'order_id' => $orderB->id,
            'product_id' => $productB->id,
            'qty' => 2,
            'order_price' => 25000,
            'order_subtotal' => 50000,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'order_id' => $orderB->id,
            'amount' => 50000,
            'payment_method' => PaymentMethod::Cash,
            'status' => PaymentStatusEnum::Captured,
        ]);

        // Login sebagai Owner dan set mode konsolidasi 'all'
        Session::put('active_store_id', 'all');

        $component = Livewire::actingAs($this->owner)
            ->test(OrderHistory::class)
            ->call('viewDetail', $orderB->id);

        $selected = $component->get('selectedOrder');
        $this->assertNotNull($selected);
        $this->assertEquals($orderB->id, $selected->id);
        $this->assertNotEmpty($selected->orderItems);
        $this->assertCount(1, $selected->orderItems);
        $this->assertNotEmpty($selected->payments);
        $this->assertNotNull($selected->table);
        $this->assertEquals('Meja B01', $selected->table->table_name);

        // Void pesanan cabang kedua oleh Owner
        $component->call('voidOrder', $orderB->id, 'Pembatalan pesanan cabang B valid')
            ->assertHasNoErrors();

        $this->assertEquals(OrderStatus::Void, $orderB->fresh()->order_status);
    }

    /**
     * D3-004 / CF-09: OrderPolicy::void mendukung permission orders.*, orders.void, dan void.approve.
     */
    public function test_order_policy_void_supports_wildcard_and_approve_permissions(): void
    {
        $policy = new OrderPolicy;

        $orderA = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-POLICY-VOID',
            'order_status' => OrderStatus::Paid,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Role dengan wildcard orders.*
        $roleWildcard = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor Wildcard',
            'permissions' => ['orders.*'],
        ]);
        $userWildcard = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleWildcard->id,
        ]);
        $userWildcard->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenant->id]);

        // Role dengan void.approve
        $roleApprove = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lead Void Approve',
            'permissions' => ['void.approve'],
        ]);
        $userApprove = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleApprove->id,
        ]);
        $userApprove->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenant->id]);

        // Role kasir biasa tanpa hak void
        $this->assertFalse($policy->void($this->cashier, $orderA));

        // Pengguna dengan orders.* dan void.approve diizinkan
        $this->assertTrue($policy->void($userWildcard, $orderA));
        $this->assertTrue($policy->void($userApprove, $orderA));
    }

    /**
     * D3-002 / CF-03: IngredientManager::saveAdjustment mengunci saldo dan menolak saldo negatif.
     */
    public function test_ingredient_manager_atomic_stock_balance_lock_and_prevents_negative(): void
    {
        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi Arabika',
            'unit' => 'gram',
            'cost_per_unit' => 200,
            'current_stock' => 100,
            'reorder_level' => 10,
        ]);

        // Percobaan waste melebihi saldo cabang (150 > 100) harus ditolak
        Livewire::actingAs($this->manager)
            ->test(IngredientManager::class)
            ->call('adjustStock', $ingredient->id)
            ->set('adjustType', 'waste')
            ->set('adjustQuantity', 150)
            ->set('adjustReason', 'Tumpah saat roasting')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustQuantity']);

        // Saldo stok tidak berubah
        $this->assertEquals(100, $ingredient->fresh()->current_stock);

        // Pengurangan yang valid (50 <= 100) harus sukses
        Livewire::actingAs($this->manager)
            ->test(IngredientManager::class)
            ->call('adjustStock', $ingredient->id)
            ->set('adjustType', 'waste')
            ->set('adjustQuantity', 50)
            ->set('adjustReason', 'Uji coba kalibrasi')
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        $this->assertEquals(50, $ingredient->fresh()->current_stock);
    }

    /**
     * D3-010 / CF-12: MenuCacheService::flush() tanpa storeId menginvalidasi seluruh cabang tenant pada driver non-tag.
     */
    public function test_menu_cache_service_flush_clears_all_stores_when_store_id_is_null(): void
    {
        $menuCache = app(MenuCacheService::class);

        // Mock Cache::supportsTags() agar mengembalikan false untuk menguji cabang driver database/file
        Cache::shouldReceive('supportsTags')->andReturn(false);
        $mockStore = \Mockery::mock(Repository::class);

        $keyA1 = "pos:menu:{$this->tenant->id}:store:{$this->storeA->id}:catalog:v2";
        $keyA2 = "pos:menu:{$this->tenant->id}:store:{$this->storeA->id}:categories:v2";
        $keyA3 = "pos:menu:{$this->tenant->id}:store:{$this->storeA->id}:active-products:v2";
        $keyA4 = "pos:menu:{$this->tenant->id}:store:{$this->storeA->id}:display:v2";

        $keyB1 = "pos:menu:{$this->tenant->id}:store:{$this->storeB->id}:catalog:v2";
        $keyAll = "pos:menu:{$this->tenant->id}:all:catalog:v2";

        Cache::shouldReceive('store')->andReturn($mockStore);

        // Verifikasi bahwa forget dipanggil untuk cabang A, cabang B, dan key all
        $mockStore->shouldReceive('forget')->with($keyA1)->once();
        $mockStore->shouldReceive('forget')->with($keyA2)->once();
        $mockStore->shouldReceive('forget')->with($keyA3)->once();
        $mockStore->shouldReceive('forget')->with($keyA4)->once();
        $mockStore->shouldReceive('forget')->with($keyB1)->atLeast()->once();
        $mockStore->shouldReceive('forget')->with($keyAll)->atLeast()->once();
        $mockStore->shouldReceive('forget')->zeroOrMoreTimes();

        // Flush seluruh tenant tanpa spesifikasi storeId
        $menuCache->flush($this->tenant->id, null);
    }

    /**
     * D3-001 / CF-01: TransactionService::calculateOrderTotals tidak DivisionByZero jika rounding_value = 0.
     */
    public function test_calculate_order_totals_zero_rounding_and_zero_tax(): void
    {
        Setting::set('rounding_value', 0);
        Setting::set('rounding_behavior', 'ROUND_NEAREST');
        Setting::set('tax_rate', 0.0);

        $txService = app(TransactionService::class);
        $totals = $txService->calculateOrderTotals(25500, 0);

        $this->assertEquals(0, $totals['tax_amount']);
        $this->assertEquals(25500, $totals['total_amount']);

        // Uji pula ketika rounding_behavior = NONE
        Setting::set('rounding_behavior', 'NONE');
        $totalsNone = $txService->calculateOrderTotals(25500, 0);
        $this->assertEquals(25500, $totalsNone['total_amount']);
    }

    /**
     * CF-15: AsyncExportButton mengizinkan retry jika task sebelumnya gagal.
     */
    public function test_async_export_button_retry_after_failure(): void
    {
        $component = Livewire::actingAs($this->manager)
            ->test(AsyncExportButton::class, [
                'startDate' => now()->subDay()->toDateString(),
                'endDate' => now()->toDateString(),
            ])
            ->call('requestExport')
            ->assertHasNoErrors();

        $firstTaskId = $component->get('taskId');
        $this->assertNotNull($firstTaskId);

        // Simulasikan task gagal di latar belakang
        ExportTask::find($firstTaskId)->update([
            'status' => ExportTaskStatus::Failed,
            'error_message' => 'Simulated background failure',
        ]);

        // Percobaan request export kembali harus berhasil membuat task baru
        $component->call('requestExport')->assertHasNoErrors();

        $retryTaskId = $component->get('taskId');
        $this->assertNotNull($retryTaskId);
        $this->assertNotEquals($firstTaskId, $retryTaskId);
    }

    /**
     * CF-17: WaiterCalled hanya broadcast ke channel scoped dan channels.php menegakkan otorisasi.
     */
    public function test_waiter_called_channel_authorization_and_scoped_broadcast(): void
    {
        $eventScoped = new WaiterCalled(1, 'Meja 1', $this->tenant->id, $this->storeA->id);
        $channels = $eventScoped->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals(
            "private-tenant.{$this->tenant->id}.store.{$this->storeA->id}.waiter-calls",
            $channels[0]->name
        );

        $eventUnscoped = new WaiterCalled(1, 'Meja 1', null, null);
        $this->assertEmpty($eventUnscoped->broadcastOn());

        // Verifikasi callback otorisasi di routes/channels.php via reflection
        $broadcaster = Broadcast::connection();
        $ref = new \ReflectionProperty(Broadcaster::class, 'channels');
        $ref->setAccessible(true);
        $registeredChannels = $ref->getValue($broadcaster);

        $this->assertArrayHasKey('tenant.{tenantId}.store.{storeId}.waiter-calls', $registeredChannels);
        $channelCallback = $registeredChannels['tenant.{tenantId}.store.{storeId}.waiter-calls'];

        // Assign cashier to Store A
        $this->cashier->stores()->sync([$this->storeA->id => ['tenant_id' => $this->tenant->id]]);

        // Kasir Store A diizinkan di Store A
        $this->assertTrue((bool) $channelCallback($this->cashier, (string) $this->tenant->id, (string) $this->storeA->id));

        // Kasir Store A DITOLAK di channel Store B
        $this->assertFalse((bool) $channelCallback($this->cashier, (string) $this->tenant->id, (string) $this->storeB->id));

        // User dari tenant lain DITOLAK
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);
        $rivalUser = User::withoutEvents(function () use ($otherTenant) {
            return User::forceCreate([
                'tenant_id' => $otherTenant->id,
                'name' => 'Rival Staff',
                'email' => 'rival@other.test',
                'password' => bcrypt('password'),
            ]);
        });
        $this->assertFalse((bool) $channelCallback($rivalUser, (string) $this->tenant->id, (string) $this->storeA->id));

        // Owner diizinkan di seluruh store miliknya
        $this->assertTrue((bool) $channelCallback($this->owner, (string) $this->tenant->id, (string) $this->storeA->id));
        $this->assertTrue((bool) $channelCallback($this->owner, (string) $this->tenant->id, (string) $this->storeB->id));
    }

    /**
     * D3-012 / CF-14: Memverifikasi resources/js/app.js tidak memanggil Alpine.start() secara tanpa syarat.
     */
    public function test_app_js_does_not_unconditionally_start_alpine_in_head(): void
    {
        $appJsContent = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('DOMContentLoaded', $appJsContent);
        $this->assertStringContainsString('window.Livewire', $appJsContent);
        $this->assertStringNotContainsString('if (!window.Alpine) {', $appJsContent);
    }
}
