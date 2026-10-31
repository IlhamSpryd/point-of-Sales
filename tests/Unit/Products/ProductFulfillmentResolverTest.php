<?php

declare(strict_types=1);

namespace Tests\Unit\Products;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Inventory\Services\ProductFulfillmentResolverService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFulfillmentResolverTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Category $category;

    private ProductFulfillmentResolverService $resolver;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Fulfillment Test Tenant',
            'slug' => 'fulfillment-test-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fulfillment Main Store',
            'code' => 'FUL-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'General',
        ]);

        $this->resolver = app(ProductFulfillmentResolverService::class);
    }

    public function test_service_non_inventory_and_bundle_products_are_always_fulfillable(): void
    {
        $service = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Service Cleaning',
            'product_type' => 'service',
            'track_inventory' => false,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 50000,
        ]);

        $dto = $this->resolver->resolve($service, $this->store->id);
        $this->assertTrue($dto->isFulfillable);
        $this->assertNull($dto->availablePortions);
        $this->assertFalse($dto->hasBom);
    }

    public function test_standard_retail_product_resolves_against_product_stock_balances(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Retail Soda Can',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 15000,
            'stock' => 0,
        ]);

        // Initially no balance row -> should be out of stock (qty 0)
        $dto = $this->resolver->resolve($product, $this->store->id);
        $this->assertFalse($dto->isFulfillable);
        $this->assertSame(0, $dto->availablePortions);

        // Insert branch stock balance
        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => 25.000000,
                'baseline_quantity' => 25.000000,
                'updated_at' => now(),
            ]
        );

        $dto2 = $this->resolver->resolve($product, $this->store->id);
        $this->assertTrue($dto2->isFulfillable);
        $this->assertSame(25, $dto2->availablePortions);
    }

    public function test_bom_product_fulfillment_calculates_bottleneck_portions_using_bcmath(): void
    {
        $coffeeBean = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Coffee Beans Arabica',
            'sku' => 'ING-COFFEE-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0, // Legacy mirror is 0
            'cost_per_unit' => 200,
            'is_active' => true,
        ]);

        $freshMilk = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fresh Milk Diamond',
            'sku' => 'ING-MILK-'.uniqid(),
            'unit' => 'ml',
            'current_stock' => 0,
            'cost_per_unit' => 25,
            'is_active' => true,
        ]);

        $cappuccino = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Hot Cappuccino',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 38000,
            'stock' => 0,
        ]);

        // Recipe: 18g coffee beans, 150ml milk
        $cappuccino->ingredients()->attach([
            $coffeeBean->id => ['quantity_required' => 18.0000, 'tenant_id' => $this->tenant->id],
            $freshMilk->id => ['quantity_required' => 150.0000, 'tenant_id' => $this->tenant->id],
        ]);

        // Branch balances: 180g beans (=10 portions), 600ml milk (=4 portions) -> bottleneck is milk (4 portions)
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $coffeeBean->id],
            ['quantity' => 180.000000, 'baseline_quantity' => 180.000000, 'updated_at' => now()]
        );
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $freshMilk->id],
            ['quantity' => 600.000000, 'baseline_quantity' => 600.000000, 'updated_at' => now()]
        );

        $dto = $this->resolver->resolve($cappuccino, $this->store->id);
        $this->assertTrue($dto->isFulfillable);
        $this->assertSame(4, $dto->availablePortions);
        $this->assertTrue($dto->hasBom);
        $this->assertEmpty($dto->missingIngredients);
    }

    public function test_bom_product_is_not_fulfillable_if_any_ingredient_has_zero_portions(): void
    {
        $coffeeBean = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Coffee Beans',
            'sku' => 'ING-COF-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0,
            'cost_per_unit' => 200,
            'is_active' => true,
        ]);

        $espresso = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Single Espresso',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 25000,
            'stock' => 0,
        ]);

        $espresso->ingredients()->attach([
            $coffeeBean->id => ['quantity_required' => 18.0000, 'tenant_id' => $this->tenant->id],
        ]);

        // Branch balance has only 10g (less than required 18g)
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $coffeeBean->id],
            ['quantity' => 10.000000, 'baseline_quantity' => 10.000000, 'updated_at' => now()]
        );

        $dto = $this->resolver->resolve($espresso, $this->store->id);
        $this->assertFalse($dto->isFulfillable);
        $this->assertSame(0, $dto->availablePortions);
        $this->assertCount(1, $dto->missingIngredients);
        $this->assertSame('Coffee Beans', $dto->missingIngredients[0]['name']);
    }

    public function test_batch_resolution_sets_is_fulfillable_and_available_portions_properties(): void
    {
        $coffeeBean = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Robusta Beans',
            'sku' => 'ING-ROB-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0,
            'cost_per_unit' => 150,
            'is_active' => true,
        ]);

        $coffee = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Robusta Brew',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 20000,
            'stock' => 0,
        ]);

        $coffee->ingredients()->attach([
            $coffeeBean->id => ['quantity_required' => 20.0000, 'tenant_id' => $this->tenant->id],
        ]);

        $chips = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Cassava Chips',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 12000,
            'stock' => 0,
        ]);

        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $coffeeBean->id],
            ['quantity' => 100.000000, 'baseline_quantity' => 100.000000, 'updated_at' => now()]
        );

        DB::table('product_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'product_id' => $chips->id],
            ['quantity' => 12.000000, 'baseline_quantity' => 12.000000, 'updated_at' => now()]
        );

        $coffee->load('ingredients');
        $chips->load('ingredients');

        $this->resolver->resolveBatch([$coffee, $chips], $this->store->id);

        $this->assertTrue($coffee->is_fulfillable);
        $this->assertSame(5, $coffee->available_portions);

        $this->assertTrue($chips->is_fulfillable);
        $this->assertSame(12, $chips->available_portions);
    }
}
