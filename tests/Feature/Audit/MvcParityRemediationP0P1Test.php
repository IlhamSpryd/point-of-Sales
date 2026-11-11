<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockOpnameStatusEnum;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kds\Board as KdsBoard;
use App\Livewire\Inventory\StockOpnameManager;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\FNB\Services\SplitBillService;
use App\Modules\Integration\Logistics\DTOs\ShippingRateRequestDto;
use App\Modules\Integrations\Logistics\Services\Logistics3PlAggregatorService;
use App\Modules\Products\Services\BundleComponentExpanderService;
use App\Modules\Sales\Services\TransactionService;
use App\Modules\Settings\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class MvcParityRemediationP0P1Test extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $managerUser;
    private User $staffUser;
    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $roleManager = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Store Manager', 'is_admin' => false]
        );

        $roleCashier = Role::firstOrCreate(
            ['name' => 'Cashier'],
            ['description' => 'Store Cashier', 'is_admin' => false]
        );

        $this->managerUser = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->managerUser->update([
            'role_id' => $roleManager->id,
            'store_id' => $this->store->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);
        $this->managerUser->stores()->sync([
            $this->store->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->staffUser = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Counter',
            'email' => 'counter.' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $roleCashier->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'F&B & Retail Audit',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * TASK-P0-04: Verifikasi seluruh rute tidak mengarah ke method Controller yang tidak ada.
     */
    public function test_no_routes_point_to_missing_controller_methods(): void
    {
        $routes = Route::getRoutes()->getRoutes();
        $missing = [];

        foreach ($routes as $route) {
            $action = $route->getActionName();
            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action);
                if (class_exists($class) && ! method_exists($class, $method)) {
                    $missing[] = "{$action} for URI {$route->uri()}";
                }
            }
        }

        $this->assertEmpty($missing, 'Ditemukan rute yang mengarah ke method Controller kosong: ' . implode(', ', $missing));
    }

    /**
     * TASK-P0-01: Verifikasi Barcode Scanning & Variant Picker di Kasir POS.
     */
    public function test_barcode_scanner_and_variant_picker_in_pos(): void
    {
        $this->actingAs($this->managerUser);

        // Buat produk dengan varian ritel
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kemeja Katun Pria',
            'product_code' => 'KMT-001',
            'sku' => 'KMT-M',
            'barcode' => '899100000001',
            'product_price' => 150000,
            'is_active' => true,
            'track_inventory' => true,
        ]);

        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Ukuran L - Navy',
            'sku' => 'KMT-NAVY-L',
            'barcode' => '899988877711',
            'price_override' => 165000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Buat Shift aktif
        Shift::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->managerUser->id,
            'active_cashier_id' => $this->managerUser->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 500000,
        ]);

        // 1. Scan barcode varian langsung
        Livewire::test(CreateOrder::class)
            ->call('scanBarcode', '899988877711')
            ->assertSet('cart.0.product_id', $product->id)
            ->assertSet('cart.0.product_variant_id', $variant->id);

        // 2. Klik produk yang memiliki varian membuka modal variant picker
        Livewire::test(CreateOrder::class)
            ->call('addToCartDirectly', $product->id)
            ->assertSet('selectingVariantProductId', $product->id)
            ->call('selectVariant', $variant->id)
            ->assertSet('selectingVariantProductId', null)
            ->assertSet('cart.0.product_variant_id', $variant->id);
    }

    /**
     * TASK-P0-02: Verifikasi KDS Board menampilkan pesanan dine-in open tab (belum bayar).
     */
    public function test_kds_board_displays_open_tab_dine_in_orders(): void
    {
        $this->actingAs($this->managerUser);

        $table = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja Audit 05',
            'table_code' => 'T-AUD-05',
            'capacity' => 4,
            'operational_status' => 'occupied',
            'is_active' => true,
        ]);

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Nasi Goreng Spesial',
            'product_code' => 'NGS-001',
            'product_price' => 35000,
            'is_active' => true,
            'kitchen_station' => 'kitchen',
        ]);

        // Buat Order DINE-IN PENDING (Open Tab / Belum Bayar)
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->managerUser->id,
            'idempotency_key' => 'idem_' . uniqid(),
            'order_code' => 'ORD-OPEN-' . uniqid(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending->value,
            'table_id' => $table->id,
            'order_amount' => 35000,
            'subtotal_amount' => 35000,
            'order_date' => now(),
        ]);

        $item = OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'qty' => 1,
            'unit_price' => 35000,
            'subtotal' => 35000,
            'preparation_status' => 'pending',
        ]);

        Livewire::test(KdsBoard::class)
            ->assertSee($order->order_code)
            ->assertSee('Meja Audit 05')
            ->assertSee('OPEN TAB');
    }

    /**
     * TASK-P0-03: Verifikasi Dekomposisi Pemotongan Stok Komponen Bundel & Restorasinya.
     */
    public function test_bundle_decomposition_deducts_and_restores_components(): void
    {
        $this->actingAs($this->managerUser);

        // Komponen 1: Burger
        $comp1 = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Burger Sapi Komponen',
            'product_code' => 'CMP-BRG',
            'product_price' => 30000,
            'product_cost' => 15000,
            'standard_cost' => 15000,
            'is_active' => true,
            'track_inventory' => true,
            'product_type' => 'standard',
        ]);

        // Komponen 2: Es Teh
        $comp2 = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Es Teh Komponen',
            'product_code' => 'CMP-TEH',
            'product_price' => 8000,
            'product_cost' => 2000,
            'standard_cost' => 2000,
            'is_active' => true,
            'track_inventory' => true,
            'product_type' => 'standard',
        ]);

        // Berikan saldo stok awal ke komponen di cabang
        DB::table('product_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $comp1->id],
            ['quantity' => 10, 'updated_at' => now()]
        );
        DB::table('product_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $comp2->id],
            ['quantity' => 10, 'updated_at' => now()]
        );

        // Produk Bundel / Combo Meal
        $bundle = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Paket Combo Hemat Burger',
            'product_code' => 'BND-CB01',
            'product_price' => 35000,
            'is_active' => true,
            'track_inventory' => false,
            'product_type' => 'bundle',
        ]);

        ProductBundleItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $comp1->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        ProductBundleItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $comp2->id,
            'quantity' => 1,
            'sort_order' => 2,
        ]);

        // Verifikasi ekspansi komponen
        $expander = app(BundleComponentExpanderService::class);
        $components = $expander->expand($bundle, 2);
        $this->assertCount(2, $components);
        $this->assertEquals(2.0, $components[0]['quantity']);

        // Verifikasi ketersediaan di cabang
        $avail = $expander->checkAvailability($bundle, $this->store->id, 2);
        $this->assertTrue($avail['available']);

        // Eksekusi penjualan pesanan melalui TransactionService
        $shift = Shift::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->managerUser->id,
            'active_cashier_id' => $this->managerUser->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 500000,
        ]);

        $txService = app(TransactionService::class);
        $order = $txService->createTransaction([
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_id' => $bundle->id,
                    'quantity' => 2,
                    'notes' => 'Tanpa acar',
                    'options' => [],
                ],
            ],
            'payment_method' => PaymentMethod::Cash->value,
            'cash_received' => 100000,
        ], $this->managerUser->id);

        $this->assertInstanceOf(Order::class, $order);

        // Verifikasi stok komponen berkurang (10 - 2 = 8)
        $qty1 = DB::table('product_stock_balances')->where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('product_id', $comp1->id)->value('quantity');
        $qty2 = DB::table('product_stock_balances')->where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('product_id', $comp2->id)->value('quantity');
        $this->assertEquals(8, (int) $qty1);
        $this->assertEquals(8, (int) $qty2);

        // Eksekusi Void dan verifikasi stok komponen dikembalikan
        $txService->voidOrder($order, 'Audit cancellation test', $this->managerUser->id);

        $restoredQty1 = DB::table('product_stock_balances')->where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('product_id', $comp1->id)->value('quantity');
        $restoredQty2 = DB::table('product_stock_balances')->where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('product_id', $comp2->id)->value('quantity');
        $this->assertEquals(10, (int) $restoredQty1);
        $this->assertEquals(10, (int) $restoredQty2);
    }

    /**
     * TASK-P1-01: Verifikasi Endpoint Pindah Meja (Move Table) & Gabung Meja (Merge Tables).
     */
    public function test_table_move_and_merge_controller_endpoints(): void
    {
        $this->actingAs($this->managerUser);

        $tableA = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja A1',
            'table_code' => 'T-A1-' . uniqid(),
            'capacity' => 2,
            'operational_status' => 'occupied',
            'is_active' => true,
        ]);

        $tableB = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja B2',
            'table_code' => 'T-B2-' . uniqid(),
            'capacity' => 4,
            'operational_status' => 'available',
            'is_active' => true,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->managerUser->id,
            'idempotency_key' => 'idem_' . uniqid(),
            'order_code' => 'ORD-MOVE-' . uniqid(),
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'table_id' => $tableA->id,
            'table_number' => $tableA->table_name,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'order_date' => now(),
        ]);

        // 1. Pindah Meja A1 -> Meja B2
        $responseMove = $this->post(route('tables.move', $tableA->id), [
            'target_table_id' => $tableB->id,
        ]);
        $responseMove->assertRedirect(route('tables.index'));

        $this->assertEquals($tableB->id, $order->fresh()->table_id);
        $this->assertEquals('available', $tableA->fresh()->operational_status);
        $this->assertEquals('occupied', $tableB->fresh()->operational_status);

        // 2. Gabung Meja (Merge Tables)
        $responseMerge = $this->post(route('tables.merge', $tableB->id), [
            'secondary_table_ids' => [$tableA->id],
        ]);
        $responseMerge->assertRedirect(route('tables.index'));
        $this->assertEquals('occupied', $tableB->fresh()->operational_status);
    }

    /**
     * TASK-P1-01: Verifikasi Integrasi Split Bill di Kasir POS.
     */
    public function test_split_bill_integration_in_pos(): void
    {
        $this->actingAs($this->managerUser);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->managerUser->id,
            'idempotency_key' => 'idem_' . uniqid(),
            'order_code' => 'ORD-SPLIT-' . uniqid(),
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'order_amount' => 100000,
            'subtotal_amount' => 100000,
            'order_date' => now(),
        ]);

        $sampleProduct = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Menu Resto Audit',
            'product_code' => 'MRA-001',
            'product_price' => 50000,
            'is_active' => true,
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $sampleProduct->id,
            'product_name_snapshot' => 'Steak Daging',
            'qty' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $sampleProduct->id,
            'product_name_snapshot' => 'Minuman Jus',
            'qty' => 2,
            'unit_price' => 20000,
            'subtotal' => 40000,
        ]);

        // Jalankan Split Bill via Livewire POS
        Livewire::test(CreateOrder::class)
            ->call('openSplitBillModal', $order->id)
            ->assertSet('showSplitBillModal', true)
            ->assertSet('splitBillOrderId', $order->id)
            ->set('splitBillMode', 'equal')
            ->set('splitBillAmounts', [50000, 50000])
            ->call('executeSplitBill')
            ->assertSet('showSplitBillModal', false);

        // Verifikasi bahwa order induk telah dipecah
        $subOrders = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('source_reference', $order->order_code)
            ->get();

        $this->assertCount(2, $subOrders);
        $this->assertEquals(50000, (int) $subOrders[0]->order_amount);
        $this->assertEquals(50000, (int) $subOrders[1]->order_amount);
    }

    /**
     * TASK-P1-04: Verifikasi Blind Count Mode pada Stock Opname.
     */
    public function test_blind_count_mode_in_stock_opname(): void
    {
        // 1. Manager berhak melihat saldo ekspektasi sistem
        $this->actingAs($this->managerUser);
        $managerComponent = new StockOpnameManager();
        $this->assertTrue($managerComponent->canViewExpectedStock());

        // 2. Staff counter (non-manager) tidak berhak melihat saldo ekspektasi (Blind Count)
        $this->actingAs($this->staffUser);
        $staffComponent = new StockOpnameManager();
        $this->assertFalse($staffComponent->canViewExpectedStock());
    }

    /**
     * TASK-P1-04: Verifikasi Kalkulator Ongkir 3PL di Layanan Logistik.
     */
    public function test_logistics_3pl_rate_calculator(): void
    {
        $aggregator = app(Logistics3PlAggregatorService::class);
        $request = new ShippingRateRequestDto(
            originPostalCode: '10110',
            originCity: 'Jakarta Pusat',
            destinationPostalCode: '40115',
            destinationCity: 'Bandung',
            weightGrams: 1000
        );

        $rates = $aggregator->getAllRateOptionsFlat($request);
        $this->assertNotEmpty($rates);
        $this->assertGreaterThan(0, (float) $rates[0]->cost);
    }
}
