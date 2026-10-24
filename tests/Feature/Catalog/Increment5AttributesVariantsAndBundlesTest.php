<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BundleComponentExpanderService;
use App\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use App\Services\ProductService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Increment5AttributesVariantsAndBundlesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private User $userA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        // Setup Tenant A
        $this->tenantA = Tenant::create([
            'name' => 'Inc5 Tenant Alpha',
            'subdomain' => 'inc5-alpha',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha 1',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $role = Role::firstOrCreate([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
        ], [
            'role_code' => 'OWNER',
            'is_active' => true,
        ]);

        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $role->id,
        ]);

        // Setup Tenant B (untuk pengujian cross-tenant rejection)
        $this->tenantB = Tenant::create([
            'name' => 'Inc5 Tenant Beta',
            'subdomain' => 'inc5-beta',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $this->actingAs($this->userA);
    }

    public function test_product_attributes_and_values_isolated_by_tenant(): void
    {
        // 1. Buat Atribut di Tenant A
        $attrSize = ProductAttribute::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Ukuran',
            'code' => 'SIZE',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $valS = ProductAttributeValue::create([
            'tenant_id' => $this->tenantA->id,
            'attribute_id' => $attrSize->id,
            'value' => 'Small',
            'code' => 'S',
            'sort_order' => 1,
        ]);

        $valM = ProductAttributeValue::create([
            'tenant_id' => $this->tenantA->id,
            'attribute_id' => $attrSize->id,
            'value' => 'Medium',
            'code' => 'M',
            'sort_order' => 2,
        ]);

        $this->assertCount(2, $attrSize->values);
        $this->assertSame('Small', $attrSize->values->first()->value);

        // 2. Tenant B membuat atribut dengan nama yang sama ("Ukuran") -> Diizinkan karena terisolasi per tenant
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);

        $attrTenantB = ProductAttribute::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Ukuran',
            'code' => 'SIZE',
        ]);

        $this->assertNotNull($attrTenantB);
        $this->assertNotEquals($attrSize->id, $attrTenantB->id);

        $context->setTenantId($this->tenantA->id);
    }

    public function test_product_variants_creation_with_attributes_and_price_override(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $attrSize = ProductAttribute::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Size',
        ]);
        $valM = ProductAttributeValue::create([
            'tenant_id' => $this->tenantA->id,
            'attribute_id' => $attrSize->id,
            'value' => 'M',
        ]);
        $valL = ProductAttributeValue::create([
            'tenant_id' => $this->tenantA->id,
            'attribute_id' => $attrSize->id,
            'value' => 'L',
        ]);

        $productService = app(ProductService::class);

        // Buat produk dengan varian via ProductService
        $product = $productService->store([
            'category_id' => $category->id,
            'product_name' => 'Kaos Polos Apeiron',
            'product_price' => 100000,
            'has_variants' => true,
            'product_type' => 'standard',
            'status' => 'active',
            'variants' => [
                [
                    'variant_name' => 'Kaos Polos - M',
                    'sku' => 'KP-M',
                    'barcode' => '899001',
                    'price_override' => 110000,
                    'standard_cost' => 50000,
                    'attributes' => [
                        ['attribute_id' => $attrSize->id, 'attribute_value_id' => $valM->id],
                    ],
                ],
                [
                    'variant_name' => 'Kaos Polos - L',
                    'sku' => 'KP-L',
                    'barcode' => '899002',
                    'price_override' => 125000,
                    'standard_cost' => 55000,
                    'attributes' => [
                        ['attribute_id' => $attrSize->id, 'attribute_value_id' => $valL->id],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $product->variants);

        $varM = $product->variants()->where('sku', 'KP-M')->first();
        $this->assertNotNull($varM);
        $this->assertEquals(110000, (float) $varM->price_override);
        $this->assertSame('Kaos Polos - M', $varM->variant_name);
        $this->assertCount(1, $varM->attributeValues);
        $this->assertSame('M', $varM->attributeValues->first()->value);

        $varL = $product->variants()->where('sku', 'KP-L')->first();
        $this->assertNotNull($varL);
        $this->assertEquals(125000, (float) $varL->price_override);
    }

    public function test_catalog_price_resolver_service_resolves_variant_prices(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 50000, // Base price
            'has_variants' => true,
        ]);

        $var1 = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $product->id,
            'variant_name' => 'Varian Khusus',
            'sku' => 'VK-01',
            'price_override' => 65000, // Variant price override
        ]);

        $var2 = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $product->id,
            'variant_name' => 'Varian Standar',
            'sku' => 'VS-02',
            'price_override' => null, // Mengikuti base price
        ]);

        $priceResolver = app(CatalogPriceResolverService::class);

        // 1. Tanpa cabang:
        // Variant 1 -> 65000
        $this->assertEquals(65000, $priceResolver->resolvePrice($product, null, $var1->id));
        // Variant 2 -> 50000 (fallback ke base price)
        $this->assertEquals(50000, $priceResolver->resolvePrice($product, null, $var2->id));

        // 2. Cabang memiliki store_price_override untuk Varian 1 sebesar 70000
        StoreProductAssortment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'product_variant_id' => $var1->id,
            'is_available' => true,
            'store_price_override' => 70000,
        ]);

        $this->assertEquals(70000, $priceResolver->resolvePrice($product, $this->storeA->id, $var1->id));
    }

    public function test_product_bundle_items_creation_as_sales_bom(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $comp1 = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Ayam Goreng Krispi',
            'product_price' => 20000,
            'product_type' => 'standard',
        ]);

        $comp2 = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Es Teh Manis',
            'product_price' => 5000,
            'product_type' => 'standard',
        ]);

        $productService = app(ProductService::class);

        $bundleProduct = $productService->store([
            'category_id' => $category->id,
            'product_name' => 'Paket Puas Ayam + Es Teh',
            'product_price' => 22000,
            'product_type' => 'bundle',
            'status' => 'active',
            'bundle_items' => [
                [
                    'component_product_id' => $comp1->id,
                    'quantity' => 1.0,
                    'cost_allocation_ratio' => 0.80,
                ],
                [
                    'component_product_id' => $comp2->id,
                    'quantity' => 1.0,
                    'cost_allocation_ratio' => 0.20,
                ],
            ],
        ]);

        // Invarian ADR-04: Produk bundle otomatis track_inventory = false dan stock = 0
        $this->assertFalse($bundleProduct->track_inventory);
        $this->assertEquals(0, $bundleProduct->stock);
        $this->assertTrue($bundleProduct->isBundle());

        $this->assertCount(2, $bundleProduct->bundleItems);
        $item1 = $bundleProduct->bundleItems->first();
        $this->assertEquals($comp1->id, $item1->component_product_id);
        $this->assertEquals(1.0, (float) $item1->quantity);
    }

    public function test_bundle_component_expander_service_expands_components(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $compBurger = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'product_name' => 'Burger']);
        $compCola = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'product_name' => 'Cola']);

        $bundle = Product::factory()->bundle()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Combo Burger',
        ]);

        ProductBundleItem::create([
            'tenant_id' => $this->tenantA->id,
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $compBurger->id,
            'quantity' => 1.0,
        ]);

        ProductBundleItem::create([
            'tenant_id' => $this->tenantA->id,
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $compCola->id,
            'quantity' => 2.0,
        ]);

        $expander = app(BundleComponentExpanderService::class);
        $expanded = $expander->expand($bundle, multiplier: 3);

        $this->assertCount(2, $expanded);

        $burgerLine = $expanded->firstWhere('component_product_id', $compBurger->id);
        $this->assertEquals(3.0, $burgerLine['quantity']); // 1 * 3

        $colaLine = $expanded->firstWhere('component_product_id', $compCola->id);
        $this->assertEquals(6.0, $colaLine['quantity']); // 2 * 3
    }

    public function test_bundle_anti_cyclic_dag_validation_rejects_self_reference(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $bundle = Product::factory()->bundle()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Self Referencing Bundle',
        ]);

        $productService = app(ProductService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak boleh memasukkan dirinya sendiri');

        $productService->syncBundleItems($bundle, [
            ['component_product_id' => $bundle->id, 'quantity' => 1],
        ], $this->tenantA->id);
    }

    public function test_bundle_anti_cyclic_dag_validation_rejects_circular_chains(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $bundleA = Product::factory()->bundle()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'product_name' => 'Bundle A']);
        $bundleB = Product::factory()->bundle()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id, 'product_name' => 'Bundle B']);

        // A memasukkan B
        ProductBundleItem::create([
            'tenant_id' => $this->tenantA->id,
            'bundle_product_id' => $bundleA->id,
            'component_product_id' => $bundleB->id,
            'quantity' => 1.0,
        ]);

        $productService = app(ProductService::class);

        // B mencoba memasukkan A (Siklis: A -> B -> A)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Terdeteksi referensi bundel siklis');

        $productService->syncBundleItems($bundleB, [
            ['component_product_id' => $bundleA->id, 'quantity' => 1],
        ], $this->tenantA->id);
    }

    public function test_bundle_component_expander_service_checks_availability_in_store(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $comp1 = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Item Stok Banyak',
            'stock' => 10,
            'track_inventory' => true,
        ]);

        $comp2 = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_name' => 'Item Stok Sedikit',
            'stock' => 2,
            'track_inventory' => true,
        ]);

        // Set stock balances di cabang storeA
        DB::table('product_stock_balances')->where('tenant_id', $this->tenantA->id)->where('store_id', $this->storeA->id)->where('product_id', $comp1->id)->update(['quantity' => 10]);
        DB::table('product_stock_balances')->where('tenant_id', $this->tenantA->id)->where('store_id', $this->storeA->id)->where('product_id', $comp2->id)->update(['quantity' => 2]);

        $bundle = Product::factory()->bundle()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id]);
        ProductBundleItem::create(['tenant_id' => $this->tenantA->id, 'bundle_product_id' => $bundle->id, 'component_product_id' => $comp1->id, 'quantity' => 1]);
        ProductBundleItem::create(['tenant_id' => $this->tenantA->id, 'bundle_product_id' => $bundle->id, 'component_product_id' => $comp2->id, 'quantity' => 1]);

        $expander = app(BundleComponentExpanderService::class);

        // 1. Minta 2 unit bundel -> Harusnya tersedia (max 2)
        $avail2 = $expander->checkAvailability($bundle, $this->storeA->id, requestedQty: 2);
        $this->assertTrue($avail2['available']);
        $this->assertEquals(2, $avail2['max_quantity']);
        $this->assertEmpty($avail2['missing_components']);

        // 2. Minta 3 unit bundel -> Stok comp2 hanya 2 -> Tidak tersedia
        $avail3 = $expander->checkAvailability($bundle, $this->storeA->id, requestedQty: 3);
        $this->assertFalse($avail3['available']);
        $this->assertEquals(2, $avail3['max_quantity']);
        $this->assertCount(1, $avail3['missing_components']);
        $this->assertSame('Item Stok Sedikit', $avail3['missing_components'][0]['name']);
    }

    public function test_order_items_can_store_product_variant_id(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $product->id,
            'variant_name' => 'Size XL',
            'sku' => 'XL-001',
            'price_override' => 150000,
        ]);

        $order = Order::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'order_code' => 'ORD-INC5-001',
            'idempotency_key' => 'idemp-inc5-001',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'total_amount' => 150000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
            'order_price' => 150000,
            'order_subtotal' => 150000,
        ]);

        $this->assertNotNull($orderItem->fresh()->product_variant_id);
        $this->assertSame('Size XL', $orderItem->productVariant->variant_name);
    }

    public function test_cross_tenant_variant_and_bundle_rejection(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);

        $productTenantB = Product::factory()->create(['tenant_id' => $this->tenantB->id]);
        $variantTenantB = ProductVariant::create([
            'tenant_id' => $this->tenantB->id,
            'product_id' => $productTenantB->id,
            'variant_name' => 'Foreign Variant',
        ]);

        $context->setTenantId($this->tenantA->id);

        // 1. Tenant A mencoba membuat OrderItem dengan product_variant_id milik Tenant B
        $orderA = Order::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'order_code' => 'ORD-INC5-002',
            'idempotency_key' => 'idemp-inc5-002',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'total_amount' => 50000,
        ]);

        $productA = Product::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->expectException(QueryException::class);

        // Foreign key composite order_items_variant_composite_fk menolak karena tenant_id tidak cocok
        OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $orderA->id,
            'product_id' => $productA->id,
            'product_variant_id' => $variantTenantB->id,
            'qty' => 1,
            'order_price' => 50000,
            'order_subtotal' => 50000,
        ]);
    }
}
