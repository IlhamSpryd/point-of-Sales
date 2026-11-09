<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Models\ProductIngredient;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManufacturingStockLedgerIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Product $finishedProduct;
    private Ingredient $rawIngredient1;
    private Ingredient $rawIngredient2;
    private ManufacturingOrderService $service;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;
        $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'F&B Production',
        ]);

        // Finished Good: Cake Chocolate Deluxe (standard cost 50.000)
        $this->finishedProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Cake Chocolate Deluxe',
            'product_code' => 'CK-DELUXE-'.uniqid(),
            'product_price' => 120000.00,
            'standard_cost' => 50000.00,
            'product_type' => 'standard',
            'status' => 'active',
            'stock' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        // Raw Material 1: Cacao Powder (cost: 20.000 / kg, stock: 50 kg)
        $this->rawIngredient1 = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dark Cacao Powder',
            'unit' => 'kg',
            'current_stock' => 50.0000,
            'cost_per_unit' => 20000.00,
            'is_active' => true,
        ]);

        // Raw Material 2: Fresh Milk (cost: 15.000 / liter, stock: 100 liter)
        $this->rawIngredient2 = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Full Cream Fresh Milk',
            'unit' => 'liter',
            'current_stock' => 100.0000,
            'cost_per_unit' => 15000.00,
            'is_active' => true,
        ]);

        // Recipe BOM: 1 Cake requires 0.5 kg Cacao and 1.0 liter Milk
        ProductIngredient::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->finishedProduct->id,
            'ingredient_id' => $this->rawIngredient1->id,
            'quantity_required' => '0.5000',
        ]);

        ProductIngredient::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->finishedProduct->id,
            'ingredient_id' => $this->rawIngredient2->id,
            'quantity_required' => '1.0000',
        ]);

        // Inisialisasi saldo di store
        DB::table('ingredient_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'ingredient_id' => $this->rawIngredient1->id,
            ],
            [
                'quantity' => '50.0000',
                'average_unit_cost' => '20000.00',
                'last_unit_cost' => '20000.00',
                'updated_at' => now(),
            ]
        );

        DB::table('ingredient_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'ingredient_id' => $this->rawIngredient2->id,
            ],
            [
                'quantity' => '100.0000',
                'average_unit_cost' => '15000.00',
                'last_unit_cost' => '15000.00',
                'updated_at' => now(),
            ]
        );

        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->finishedProduct->id,
            ],
            [
                'quantity' => '0.0000',
                'average_unit_cost' => '50000.00',
                'last_unit_cost' => '50000.00',
                'updated_at' => now(),
            ]
        );

        $this->service = app(ManufacturingOrderService::class);
    }

    public function test_complete_production_records_append_only_movements_and_dispatches_financial_outbox(): void
    {
        // 1. Buat Work Order untuk 10 cakes
        $order = $this->service->createOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $this->finishedProduct->id,
            plannedQuantity: '10.0000',
            notes: 'Batch pesanan kue ulang tahun',
            userId: $this->user->id
        );

        $this->service->startProduction($order->id);

        // 2. Selesaikan produksi 10 cakes
        // Konsumsi: 10 * 0.5 = 5.0 kg Cacao (Rp 100.000)
        // Konsumsi: 10 * 1.0 = 10.0 liter Milk (Rp 150.000)
        // Total material cost: Rp 250.000
        $completedOrder = $this->service->completeProduction($order->id, '10.0000');

        $this->assertSame('COMPLETED', $completedOrder->status);
        $this->assertEquals('10.0000', (string) $completedOrder->produced_quantity);

        // 3. Verifikasi append-only audit trail ingredient_stock_movements
        $this->assertDatabaseHas('ingredient_stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'ingredient_id' => $this->rawIngredient1->id,
            'type' => IngredientStockMovementTypeEnum::AdjustmentOut->value,
            'quantity' => -5.0000,
        ]);

        $this->assertDatabaseHas('ingredient_stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'ingredient_id' => $this->rawIngredient2->id,
            'type' => IngredientStockMovementTypeEnum::AdjustmentOut->value,
            'quantity' => -10.0000,
        ]);

        // 4. Verifikasi append-only audit trail stock_movements (Finished Goods)
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->finishedProduct->id,
            'type' => StockMovementType::AdjustmentIn->value,
            'quantity' => 10,
        ]);

        // 5. Verifikasi Saldo Fisik Terkini
        $ing1Balance = (float) DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $this->rawIngredient1->id)
            ->value('quantity');
        $this->assertEquals(45.0, $ing1Balance);

        $ing2Balance = (float) DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $this->rawIngredient2->id)
            ->value('quantity');
        $this->assertEquals(90.0, $ing2Balance);

        $prodBalance = (float) DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->finishedProduct->id)
            ->value('quantity');
        $this->assertEquals(10.0, $prodBalance);

        // 6. Verifikasi Financial Event Outbox untuk Akuntansi Manufaktur & WIP
        $this->assertDatabaseHas('financial_event_outboxes', [
            'tenant_id' => $this->tenant->id,
            'event_type' => 'manufacturing.production_completed',
            'source_module' => 'manufacturing',
            'source_type' => 'manufacturing_order',
            'source_id' => $order->id,
            'source_number' => $order->order_number,
        ]);

        $outbox = DB::table('financial_event_outboxes')
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', 'manufacturing.production_completed')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($outbox);
        $payload = json_decode($outbox->payload, true);
        $this->assertSame($order->id, $payload['manufacturing_order_id']);
        $this->assertEquals(10.0, $payload['produced_quantity']);
        $this->assertEquals(250000.0, $payload['total_material_cost']);
        $this->assertCount(2, $payload['ingredients_consumed']);
    }

    public function test_cannot_complete_cancelled_order(): void
    {
        $order = $this->service->createOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $this->finishedProduct->id,
            plannedQuantity: '5.0000'
        );

        $this->service->cancelOrder($order->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot complete an already CANCELLED manufacturing order.');

        $this->service->completeProduction($order->id);
    }

    public function test_cannot_complete_with_zero_quantity(): void
    {
        $order = $this->service->createOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $this->finishedProduct->id,
            plannedQuantity: '5.0000'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Produced quantity must be greater than zero.');

        $this->service->completeProduction($order->id, '0.0000');
    }
}
