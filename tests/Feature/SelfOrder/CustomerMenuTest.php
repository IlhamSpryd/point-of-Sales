<?php

declare(strict_types=1);

namespace Tests\Feature\SelfOrder;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Products\Services\MenuCacheService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerMenuTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'SelfOrder Menu Tenant',
            'slug' => 'selforder-menu-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SelfOrder Menu Store',
            'code' => 'SOM-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Specialty Coffee',
        ]);
    }

    public function test_bom_products_with_zero_retail_stock_are_available_in_catalog(): void
    {
        $beans = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'House Blend Beans',
            'sku' => 'ING-HB-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0,
            'cost_per_unit' => 180,
            'is_active' => true,
        ]);

        $americano = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Iced Americano',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 30000,
            'stock' => 0, // Zero direct stock
        ]);

        $americano->ingredients()->attach([
            $beans->id => ['quantity_required' => 16.0000, 'tenant_id' => $this->tenant->id],
        ]);

        // Verify availableForOrder scope includes this BOM product
        $available = Product::availableForOrder()->where('id', $americano->id)->exists();
        $this->assertTrue($available, 'Product with BOM ingredients must pass scopeAvailableForOrder even when stock is 0');

        // Store authoritative balances
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $beans->id],
            ['quantity' => 160.000000, 'baseline_quantity' => 160.000000, 'updated_at' => now()]
        );

        $menuService = app(MenuCacheService::class);
        $menuService->flush($this->tenant->id, $this->store->id);

        $categories = $menuService->getCatalog();
        $cat = $categories->firstWhere('id', $this->category->id);
        $this->assertNotNull($cat);

        $prod = $cat->products->firstWhere('id', $americano->id);
        $this->assertNotNull($prod, 'BOM product must be listed in MenuCacheService catalog');
        $this->assertTrue($prod->is_fulfillable, 'BOM product must have is_fulfillable=true when ingredient balances are sufficient');
        $this->assertSame(10, $prod->available_portions);
    }

    public function test_bom_product_with_empty_ingredients_shows_unfulfillable_in_catalog(): void
    {
        $beans = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rare Gesha Beans',
            'sku' => 'ING-GSH-'.uniqid(),
            'unit' => 'g',
            'current_stock' => 0,
            'cost_per_unit' => 500,
            'is_active' => true,
        ]);

        $gesha = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Gesha Filter',
            'product_type' => 'standard',
            'track_inventory' => true,
            'status' => 'active',
            'is_active' => true,
            'product_price' => 85000,
            'stock' => 0,
        ]);

        $gesha->ingredients()->attach([
            $beans->id => ['quantity_required' => 15.0000, 'tenant_id' => $this->tenant->id],
        ]);

        // Branch balance has 0g
        DB::table('ingredient_stock_balances')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $beans->id],
            ['quantity' => 0.000000, 'baseline_quantity' => 0.000000, 'updated_at' => now()]
        );

        $menuService = app(MenuCacheService::class);
        $menuService->flush($this->tenant->id, $this->store->id);

        $categories = $menuService->getCatalog();
        $cat = $categories->firstWhere('id', $this->category->id);
        $prod = $cat->products->firstWhere('id', $gesha->id);

        $this->assertNotNull($prod, 'Product is sellable so it must remain in catalog (not hidden)');
        $this->assertFalse($prod->is_fulfillable, 'Product must be marked is_fulfillable=false when ingredients are 0');
        $this->assertSame(0, $prod->available_portions);
    }
}
