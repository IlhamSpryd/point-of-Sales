<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosOrderCreationTest extends TestCase
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
            'name' => 'POS Order Test Tenant',
            'slug' => 'pos-order-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Order Test Store',
            'code' => 'ORD-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['*'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
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

    public function test_pos_checkout_deducts_authoritative_ingredient_stock_balances_for_bom_product(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $beans = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Arabica Roast',
            'sku' => 'ING-ARA-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0, // Legacy mirror is 0
            'cost_per_unit' => 200,
            'is_active' => true,
        ]);

        $milk = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fresh Milk',
            'sku' => 'ING-MLK-'.uniqid(),
            'unit' => 'ml',
            'current_stock' => 0, // Legacy mirror is 0
            'cost_per_unit' => 25,
            'is_active' => true,
        ]);

        $latte = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Caffe Latte',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 35000,
            'stock' => 0,
        ]);

        $latte->ingredients()->attach([
            $beans->id => ['quantity_required' => 18.0000, 'tenant_id' => $this->tenant->id],
            $milk->id => ['quantity_required' => 200.0000, 'tenant_id' => $this->tenant->id],
        ]);

        // Assortment available in store
        DB::table('store_product_assortments')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $latte->id],
            ['is_available' => true, 'allowed_channels' => json_encode(['pos']), 'updated_at' => now()]
        );

        // Store authoritative balances
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $beans->id],
            ['quantity' => 180.000000, 'baseline_quantity' => 180.000000, 'updated_at' => now()]
        );
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $milk->id],
            ['quantity' => 1000.000000, 'baseline_quantity' => 1000.000000, 'updated_at' => now()]
        );

        $payload = [
            'store_id' => $this->store->id,
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_id' => $latte->id,
                    'quantity' => 2,
                    'price' => 35000,
                    'selected_modifiers' => [],
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'idempotency_key' => 'idemp-bom-'.uniqid(),
        ];

        $transactionService = app(TransactionService::class);
        $order = $transactionService->createTransaction($payload, $this->cashier->id);

        $this->assertNotNull($order);
        $this->assertSame('paid', $order->order_status->value);

        // Verify ingredient stock balances deducted:
        // Beans: 180 - (2 * 18) = 144
        $beansBal = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $beans->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(144.0, (float) $beansBal, 0.0001);

        // Milk: 1000 - (2 * 200) = 600
        $milkBal = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $milk->id)
            ->value('quantity');
        $this->assertEqualsWithDelta(600.0, (float) $milkBal, 0.0001);
    }

    public function test_pos_checkout_rejects_order_if_store_ingredient_stock_balance_is_insufficient(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $beans = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Single Origin Beans',
            'sku' => 'ING-SO-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0,
            'cost_per_unit' => 250,
            'is_active' => true,
        ]);

        $pourover = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'V60 Pour Over',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 45000,
            'stock' => 0,
        ]);

        $pourover->ingredients()->attach([
            $beans->id => ['quantity_required' => 15.0000, 'tenant_id' => $this->tenant->id],
        ]);

        DB::table('store_product_assortments')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $pourover->id],
            ['is_available' => true, 'allowed_channels' => json_encode(['pos']), 'updated_at' => now()]
        );

        // Store balance only has 10g, but 1 portion needs 15g
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $beans->id],
            ['quantity' => 10.000000, 'baseline_quantity' => 10.000000, 'updated_at' => now()]
        );

        $payload = [
            'store_id' => $this->store->id,
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_id' => $pourover->id,
                    'quantity' => 1,
                    'price' => 45000,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'idempotency_key' => 'idemp-fail-'.uniqid(),
        ];

        $this->expectException(ValidationException::class);
        app(TransactionService::class)->createTransaction($payload, $this->cashier->id);
    }
}
