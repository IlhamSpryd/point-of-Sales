<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosInventoryLedgerDelegationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $cashier;

    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Ledger Delegation Test Tenant',
            'slug' => 'ledger-delegation-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Delegation Store',
            'code' => 'DEL-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cashier->id,
            'store_id' => $this->store->id,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverages',
        ]);
    }

    public function test_pos_checkout_retail_product_delegates_to_inventory_ledger_service(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Mineral Water 600ml',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 5000,
            'cost_price' => 3000,
            'stock' => 50,
        ]);

        DB::table('store_product_assortments')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $product->id],
            ['is_available' => true, 'allowed_channels' => json_encode(['pos']), 'updated_at' => now()]
        );

        $payload = [
            'store_id' => $this->store->id,
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'price' => 5000,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'idempotency_key' => 'idemp-retail-'.uniqid(),
        ];

        $service = app(TransactionService::class);
        $order = $service->createTransaction($payload, $this->cashier->id);

        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Paid, $order->order_status);

        // 1. Assert stock_movements entry exists with type sale_deduction
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => StockMovementType::SaleDeduction->value,
            'quantity' => -5,
        ]);

        // 2. Assert product_stock_balances was updated
        $this->assertDatabaseHas('product_stock_balances', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'quantity' => 45,
        ]);

        // 3. Assert legacy mirror column was updated
        $this->assertSame(45, (int) $product->fresh()->stock);

        // 4. Void order and assert restore compensation via InventoryLedgerService
        $service->voidOrder($order, 'Customer cancellation', $this->cashier->id);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => StockMovementType::RestoreCompensation->value,
            'quantity' => 5,
        ]);

        $this->assertSame(50, (int) $product->fresh()->stock);
    }

    public function test_pos_checkout_bom_product_delegates_to_inventory_ledger_service(): void
    {
        $teaLeaves = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Jasmine Tea Leaves',
            'sku' => 'ING-TEA-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 500,
            'cost_per_unit' => 150,
            'is_active' => true,
        ]);

        $tea = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Iced Jasmine Tea',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 18000,
            'stock' => 0,
        ]);

        $tea->ingredients()->attach([
            $teaLeaves->id => ['quantity_required' => 10.0000, 'tenant_id' => $this->tenant->id],
        ]);

        DB::table('store_product_assortments')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $tea->id],
            ['is_available' => true, 'allowed_channels' => json_encode(['pos']), 'updated_at' => now()]
        );

        $payload = [
            'store_id' => $this->store->id,
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_id' => $tea->id,
                    'quantity' => 3,
                    'price' => 18000,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'idempotency_key' => 'idemp-bom-'.uniqid(),
        ];

        $service = app(TransactionService::class);
        $order = $service->createTransaction($payload, $this->cashier->id);

        $this->assertNotNull($order);

        // 1. Assert ingredient_stock_movements entry exists
        $this->assertDatabaseHas('ingredient_stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'ingredient_id' => $teaLeaves->id,
            'order_id' => $order->id,
            'type' => IngredientStockMovementTypeEnum::SaleDeduction->value,
            'quantity' => -30.0000,
        ]);

        // 2. Assert ingredient_stock_balances was updated: 500 - 30 = 470
        $this->assertDatabaseHas('ingredient_stock_balances', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'ingredient_id' => $teaLeaves->id,
            'quantity' => 470.000000,
        ]);

        // 3. Assert legacy mirror was updated
        $this->assertEqualsWithDelta(470.0, (float) $teaLeaves->fresh()->current_stock, 0.001);

        // 4. Void order and verify BOM restoration
        $service->voidOrder($order, 'Wrong table order', $this->cashier->id);

        $this->assertDatabaseHas('ingredient_stock_movements', [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'ingredient_id' => $teaLeaves->id,
            'order_id' => $order->id,
            'type' => IngredientStockMovementTypeEnum::RestoreCompensation->value,
            'quantity' => 30.0000,
        ]);

        $this->assertEqualsWithDelta(500.0, (float) $teaLeaves->fresh()->current_stock, 0.001);
    }
}
