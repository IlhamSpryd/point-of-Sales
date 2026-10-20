<?php

namespace Tests\Feature\Catalog;

use App\Http\Requests\StoreProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\Context\TenantContext;
use App\Services\ProductService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Increment1CatalogEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $this->tenant = Tenant::create([
            'name' => 'Catalog Test Tenant',
            'subdomain' => 'catalog-test',
            'status' => 'active',
        ]);

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Branch',
            'address' => '123 Street',
            'phone' => '08123456789',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_category_factory_and_hierarchy(): void
    {
        $parent = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beverages',
            'category_code' => 'CAT-BEV',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $child = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $parent->id,
            'name' => 'Coffee',
            'category_code' => 'CAT-COF',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertTrue($parent->children->contains($child));
    }

    public function test_category_cannot_be_its_own_parent_via_service(): void
    {
        $category = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Food',
            'category_code' => 'CAT-FOOD',
        ]);

        $service = app(CategoryService::class);

        $this->expectException(ValidationException::class);
        $service->updateCategory($category, [
            'parent_id' => $category->id,
            'name' => 'Food Updated',
            'category_code' => 'CAT-FOOD',
        ]);
    }

    public function test_brand_creation_and_product_relation(): void
    {
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apeiron Roast',
            'code' => 'BRD-AR',
            'is_active' => true,
        ]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_name' => 'Espresso Beans',
        ]);

        $this->assertEquals($brand->id, $product->brand->id);
        $this->assertTrue($brand->products->contains($product));
    }

    public function test_uom_and_conversion(): void
    {
        $baseUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gram',
            'code' => 'g',
            'precision_scale' => 2,
        ]);

        $packUom = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pack 500g',
            'code' => 'p500',
            'precision_scale' => 0,
        ]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'base_uom_id' => $baseUom->id,
        ]);

        $conversion = ProductUnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_uom_id' => $packUom->id,
            'to_uom_id' => $baseUom->id,
            'conversion_factor' => '500.000000',
        ]);

        $this->assertEquals('500.000000', $conversion->conversion_factor);
        $this->assertTrue($product->unitConversions->contains($conversion));
    }

    public function test_product_service_forces_track_inventory_for_service_types(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $service = app(ProductService::class);

        $product = $service->createProduct([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'SVC-CLEAN',
            'product_name' => 'Equipment Cleaning Service',
            'product_price' => 100000,
            'product_type' => 'service',
            'track_inventory' => true, // Attempt to track inventory on service
            'status' => 'active',
        ]);

        $this->assertFalse((bool) $product->track_inventory);
        $this->assertTrue((bool) $product->is_active);
    }

    public function test_product_service_syncs_is_active_and_status(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $service = app(ProductService::class);

        // When status is discontinued, is_active should be false
        $product = $service->createProduct([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_code' => 'PRD-DISC',
            'product_name' => 'Discontinued Product',
            'product_price' => 50000,
            'product_type' => 'standard',
            'status' => 'discontinued',
        ]);

        $this->assertFalse((bool) $product->is_active);

        // When updated to active, is_active becomes true
        $updated = $service->updateProduct($product, [
            'status' => 'active',
        ]);

        $this->assertTrue((bool) $updated->is_active);
    }

    public function test_product_code_unique_per_tenant_allows_same_code_different_tenant(): void
    {
        $context = app(TenantContext::class);

        $tenant2 = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Tenant 2',
                'subdomain' => 'tenant-2',
                'status' => 'active',
            ]);
        });

        $cat1 = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $p1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat1->id,
            'product_code' => 'SKU-001',
        ]);

        $cat2 = $context->runAs($tenant2->id, null, fn () => Category::factory()->create(['tenant_id' => $tenant2->id]));
        $p2 = $context->runAs($tenant2->id, null, fn () => Product::factory()->create([
            'tenant_id' => $tenant2->id,
            'category_id' => $cat2->id,
            'product_code' => 'SKU-001',
        ]));

        $this->assertNotEquals($p1->tenant_id, $p2->tenant_id);
        $this->assertEquals($p1->product_code, $p2->product_code);
    }

    public function test_category_hierarchy_cycle_is_rejected(): void
    {
        $service = app(CategoryService::class);

        $catA = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Root Cat',
        ]);

        $catB = Category::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Sub Cat',
            'parent_id' => $catA->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->updateCategory($catA, [
            'parent_id' => $catB->id,
            'category_name' => 'Root Cat Updated',
        ]);
    }

    public function test_barcode_unique_per_tenant(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'barcode' => '8991234567890',
        ]);

        $this->expectException(QueryException::class);
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'barcode' => '8991234567890',
        ]);
    }

    public function test_cross_tenant_validation_in_store_product_request(): void
    {
        $context = app(TenantContext::class);

        $tenant2 = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Foreign Tenant',
                'subdomain' => 'foreign-tenant',
                'status' => 'active',
            ]);
        });

        $foreignCategory = $context->runAs($tenant2->id, null, fn () => Category::factory()->create(['tenant_id' => $tenant2->id]));
        $foreignBrand = $context->runAs($tenant2->id, null, fn () => Brand::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Foreign Brand',
            'code' => 'BRD-FRN',
        ]));

        $rules = (new StoreProductRequest)->rules();

        $validator = Validator::make([
            'category_id' => $foreignCategory->id,
            'brand_id' => $foreignBrand->id,
            'product_name' => 'Cross Tenant Test',
            'product_price' => 10000,
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('category_id'));
        $this->assertTrue($validator->errors()->has('brand_id'));
    }
}
