<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Modules\Reports\Services\Analytics\InventoryAnalyticsService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave7InventoryAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected InventoryAnalyticsService $inventoryService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->inventoryService = app(InventoryAnalyticsService::class);
    }

    protected function setStockBalance(int $tenantId, int $storeId, int $productId, float $quantity, float $avgCost): void
    {
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $productId,
            ],
            [
                'quantity' => $quantity,
                'average_unit_cost' => $avgCost,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * T-RBI-04: Inventory Turnover Rate & DSI Calculation with multi-tenant isolation.
     */
    public function test_t_rbi_04_turnover_rate_and_dsi_calculation_with_isolation(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $start = Carbon::parse('2026-09-01');
        $end = Carbon::parse('2026-09-30'); // 30 hari

        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Fast Goods',
            'slug' => 'fast-goods-' . Str::random(5),
            'status' => 'ACTIVE',
        ]);

        // Product A1: Standard cost 50.000, price 100.000, stock 100 unit -> Valuation 5.000.000
        $productA1 = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Robusta 1kg',
            'product_code' => 'ROB-1KG',
            'product_price' => 100000.00,
            'standard_cost' => 50000.00,
            'stock' => 100,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $productA1->id, 100.0, 50000.0);

        // Sales for Tenant A in September: 60 units * 50.000 standard cost = 3.000.000 COGS
        $orderA = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-INV-A-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 6000000.00,
            'subtotal_amount' => 6000000.00,
            'order_date' => '2026-09-15',
            'created_at' => '2026-09-15 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $orderA->id,
            'product_id' => $productA1->id,
            'qty' => 60,
            'order_price' => 100000.00,
            'order_subtotal' => 6000000.00,
        ]);

        // Tenant B: Order to test isolation
        $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            $catB = Category::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Goods B',
                'slug' => 'goods-b-' . Str::random(5),
                'status' => 'ACTIVE',
            ]);
            $prodB = Product::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'category_id' => $catB->id,
                'product_name' => 'Tenant B Product',
                'product_code' => 'TB-PROD',
                'product_price' => 200000.00,
                'standard_cost' => 100000.00,
                'stock' => 50,
                'status' => 'active',
                'is_active' => true,
            ]);
            $this->setStockBalance($this->tenantB->id, $this->storeB->id, $prodB->id, 50.0, 100000.0);

            $ordB = Order::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'user_id' => $this->userBBarista->id,
                'order_code' => 'ORD-INV-B-1',
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 10000000.00,
                'subtotal_amount' => 10000000.00,
                'order_date' => '2026-09-16',
                'created_at' => '2026-09-16 10:00:00',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
            OrderItem::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'order_id' => $ordB->id,
                'product_id' => $prodB->id,
                'qty' => 50,
                'order_price' => 200000.00,
                'order_subtotal' => 10000000.00,
            ]);
        });

        // Run as Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $res = $this->inventoryService->getInventoryTurnoverRate($start, $end, $this->storeA->id);

        // Period days = 30
        $this->assertEquals(30, $res['period_days']);
        // COGS = 60 * 50.000 = 3.000.000
        $this->assertEquals('3000000.00', $res['cogs_amount']);
        // Average inventory (100 * 50.000 = 5.000.000)
        $this->assertEquals('5000000.00', $res['average_inventory_value']);
        // Turnover ratio = 3.000.000 / 5.000.000 = 0.60
        $this->assertEquals('0.60', $res['turnover_ratio']);
        // DSI = 30 / 0.60 = 50.00 days
        $this->assertEquals('50.00', $res['days_sales_of_inventory']);
        $this->assertEquals('SLOW_MOVING', $res['velocity_category']);
    }

    /**
     * T-RBI-05: Stock Aging buckets (0-30, 31-60, 61-90, over_90).
     */
    public function test_t_rbi_05_stock_aging_categorizes_correctly(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Aging Category',
            'slug' => 'aging-cat-' . Str::random(5),
            'status' => 'ACTIVE',
        ]);

        $now = Carbon::now();

        // Fresh product (10 days old -> bucket 0_30)
        $freshProd = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Fresh Item',
            'product_code' => 'SKU-FRESH',
            'product_price' => 50000.00,
            'standard_cost' => 30000.00,
            'stock' => 10,
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $freshProd->id)->update(['created_at' => $now->copy()->subDays(10)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $freshProd->id, 10.0, 30000.0);

        // Normal product (45 days old -> bucket 31_60)
        $normalProd = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Normal Item',
            'product_code' => 'SKU-NORMAL',
            'product_price' => 100000.00,
            'standard_cost' => 60000.00,
            'stock' => 5,
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $normalProd->id)->update(['created_at' => $now->copy()->subDays(45)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $normalProd->id, 5.0, 60000.0);

        // Slow product (75 days old -> bucket 61_90)
        $slowProd = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Slow Item',
            'product_code' => 'SKU-SLOW',
            'product_price' => 40000.00,
            'standard_cost' => 20000.00,
            'stock' => 20,
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $slowProd->id)->update(['created_at' => $now->copy()->subDays(75)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $slowProd->id, 20.0, 20000.0);

        // Critical / Obsolete product (120 days old -> bucket over_90)
        $obsoleteProd = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Obsolete Item',
            'product_code' => 'SKU-OBSOLETE',
            'product_price' => 200000.00,
            'standard_cost' => 150000.00,
            'stock' => 2,
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $obsoleteProd->id)->update(['created_at' => $now->copy()->subDays(120)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $obsoleteProd->id, 2.0, 150000.0);

        $res = $this->inventoryService->getStockAging($this->storeA->id);

        $this->assertArrayHasKey('buckets', $res);
        $this->assertEquals(1, $res['buckets']['0_30']['item_count']);
        $this->assertEquals(1, $res['buckets']['31_60']['item_count']);
        $this->assertEquals(1, $res['buckets']['61_90']['item_count']);
        $this->assertEquals(1, $res['buckets']['over_90']['item_count']);

        // Values:
        // 0_30: 10 * 30.000 = 300.000
        $this->assertEquals('300000.00', $res['buckets']['0_30']['total_value']);
        // 31_60: 5 * 60.000 = 300.000
        $this->assertEquals('300000.00', $res['buckets']['31_60']['total_value']);
        // 61_90: 20 * 20.000 = 400.000
        $this->assertEquals('400000.00', $res['buckets']['61_90']['total_value']);
        // over_90: 2 * 150.000 = 300.000
        $this->assertEquals('300000.00', $res['buckets']['over_90']['total_value']);

        // Total valuation: 300k + 300k + 400k + 300k = 1.300.000
        $this->assertEquals('1300000.00', $res['total_stock_value']);
    }

    /**
     * T-RBI-06: Dead stock detection identifies items with positive stock and zero sales in N days.
     */
    public function test_t_rbi_06_dead_stock_identifies_stagnant_inventory(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dead Stock Test Category',
            'slug' => 'dead-stock-cat-' . Str::random(5),
            'status' => 'ACTIVE',
        ]);

        $now = Carbon::now();

        // 1. Active product: Sold 5 days ago -> NOT dead stock
        $activeProd = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Active Item',
            'product_code' => 'ACTIVE-01',
            'product_price' => 50000.00,
            'standard_cost' => 25000.00,
            'stock' => 10,
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $activeProd->id)->update(['created_at' => $now->copy()->subDays(60)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $activeProd->id, 10.0, 25000.0);

        $activeOrder = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-ACTIVE-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 50000.00,
            'subtotal_amount' => 50000.00,
            'order_date' => $now->copy()->subDays(5)->toDateString(),
            'created_at' => $now->copy()->subDays(5)->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $activeOrder->id,
            'product_id' => $activeProd->id,
            'qty' => 1,
            'order_price' => 50000.00,
            'order_subtotal' => 50000.00,
        ]);

        // 2. Dead product A: Created 45 days ago, NO sales at all -> DEAD STOCK
        $deadProdA = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Stagnant Item 45D',
            'product_code' => 'DEAD-45D',
            'product_price' => 80000.00,
            'standard_cost' => 40000.00,
            'stock' => 8, // 8 * 40.000 = 320.000 tied up
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $deadProdA->id)->update(['created_at' => $now->copy()->subDays(45)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $deadProdA->id, 8.0, 40000.0);

        // 3. Dead product B: Last sold 100 days ago -> CRITICAL DEAD STOCK (> 90 days)
        $deadProdB = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Stagnant Item 100D',
            'product_code' => 'DEAD-100D',
            'product_price' => 120000.00,
            'standard_cost' => 60000.00,
            'stock' => 4, // 4 * 60.000 = 240.000 tied up
            'status' => 'active',
            'is_active' => true,
        ]);
        DB::table('products')->where('id', $deadProdB->id)->update(['created_at' => $now->copy()->subDays(120)]);
        $this->setStockBalance($this->tenantA->id, $this->storeA->id, $deadProdB->id, 4.0, 60000.0);

        $oldOrder = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-OLD-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 120000.00,
            'subtotal_amount' => 120000.00,
            'order_date' => $now->copy()->subDays(100)->toDateString(),
            'created_at' => $now->copy()->subDays(100)->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $oldOrder->id,
            'product_id' => $deadProdB->id,
            'qty' => 1,
            'order_price' => 120000.00,
            'order_subtotal' => 120000.00,
        ]);

        // Evaluate dead stock with threshold = 30 days
        $deadStock = $this->inventoryService->getDeadStock(30, $this->storeA->id);

        $this->assertEquals(2, $deadStock['dead_stock_count']);
        // Total tied up: 320.000 + 240.000 = 560.000
        $this->assertEquals('560000.00', $deadStock['total_dead_stock_value']);

        $productIds = array_column($deadStock['items'], 'product_id');
        $this->assertContains($deadProdA->id, $productIds);
        $this->assertContains($deadProdB->id, $productIds);
        $this->assertNotContains($activeProd->id, $productIds);

        // Check recommendations
        foreach ($deadStock['items'] as $item) {
            if ($item['product_id'] === $deadProdB->id) {
                $this->assertEquals('CLEARANCE_DISCOUNT_OR_RETURN', $item['recommended_action']);
            }
            if ($item['product_id'] === $deadProdA->id) {
                $this->assertEquals('BUNDLE_PROMOTION', $item['recommended_action']);
            }
        }
    }
}
