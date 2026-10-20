<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Device;
use App\Models\ParkedCart;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\User;
use App\Services\CartService;
use App\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use App\Services\MenuCacheService;
use App\Services\ProductService;
use App\Services\TransactionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Increment3AssortmentAndPosEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $storeA;

    private Store $storeB;

    private User $user;

    private Device $device;

    private Shift $shift;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $this->tenant = Tenant::create([
            'name' => 'Inc3 Test Tenant',
            'subdomain' => 'inc3-test',
            'status' => 'active',
        ]);

        $context->setTenantId($this->tenant->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Central A',
            'address' => 'Central St 1',
            'phone' => '0811111111',
            'is_active' => true,
        ]);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Uptown B',
            'address' => 'Uptown St 2',
            'phone' => '0822222222',
            'is_active' => true,
        ]);

        $context->setStoreId($this->storeA->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'device_name' => 'POS-Terminal-1',
            'device_code' => 'DEV-INC3-01',
            'device_type' => 'pos',
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->device->id,
            'user_id' => $this->user->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $this->actingAs($this->user);
    }

    public function test_branch_assortment_price_resolution_and_default_fallback(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Signature Coffee',
            'product_price' => 25000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // 1. Tanpa baris assortment: harga dasar dipakai (default fallback)
        $this->assertSame(25000.0, $resolver->resolvePrice($product, $this->storeA->id));
        $this->assertTrue($resolver->isAvailableInStore($product, $this->storeA->id));

        // 2. Buat override harga cabang A: 28000
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 28000.00,
        ]);

        $this->assertSame(28000.0, $resolver->resolvePrice($product, $this->storeA->id));
        // Cabang B tanpa override tetap 25000
        $this->assertSame(25000.0, $resolver->resolvePrice($product, $this->storeB->id));
    }

    public function test_branch_assortment_availability_and_channel_filtering(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Seasonal Gelato',
            'product_price' => 30000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // Tandai tidak tersedia di Cabang A
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => false,
            'allowed_channels' => ['dine_in'],
        ]);

        $this->assertFalse($resolver->isAvailableInStore($product, $this->storeA->id));
        // Cabang B tetap tersedia
        $this->assertTrue($resolver->isAvailableInStore($product, $this->storeB->id));

        // Buat assortment cabang B dengan channel terbatas (hanya 'takeaway')
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeB->id,
            'product_id' => $product->id,
            'is_available' => true,
            'allowed_channels' => ['takeaway'],
        ]);

        $this->assertTrue($resolver->isAvailableInStore($product, $this->storeB->id, 'takeaway'));
        $this->assertFalse($resolver->isAvailableInStore($product, $this->storeB->id, 'dine_in'));
    }

    public function test_menu_cache_service_filters_by_active_status_and_branch_availability(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $activeProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Active Tea',
            'product_price' => 15000,
            'is_active' => true,
            'status' => 'active',
            'stock' => 10,
        ]);

        $inactiveProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Inactive Pastry',
            'product_price' => 20000,
            'is_active' => false,
            'status' => 'inactive',
            'stock' => 10,
        ]);

        $discontinuedProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Discontinued Soda',
            'product_price' => 12000,
            'is_active' => true,
            'status' => 'discontinued',
            'stock' => 10,
        ]);

        // Override harga cabang A untuk Active Tea
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $activeProduct->id,
            'is_available' => true,
            'store_price_override' => 17500.00,
        ]);

        $menuCache = app(MenuCacheService::class);
        $menuCache->flush($this->tenant->id, $this->storeA->id);

        $catalog = $menuCache->getCatalog();
        $productsInCatalog = $catalog->first()->products;

        $this->assertTrue($productsInCatalog->contains('id', $activeProduct->id));
        $this->assertFalse($productsInCatalog->contains('id', $inactiveProduct->id));
        $this->assertFalse($productsInCatalog->contains('id', $discontinuedProduct->id));

        // Verifikasi harga resolved di cabang A
        $resolvedActiveProduct = $productsInCatalog->firstWhere('id', $activeProduct->id);
        $this->assertEquals(17500.00, $resolvedActiveProduct->product_price);
    }

    public function test_cart_service_rejects_inactive_or_unavailable_branch_products(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $unavailableProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Sold Out Cake',
            'product_price' => 35000,
            'is_active' => true,
            'status' => 'active',
            'stock' => 5,
        ]);

        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $unavailableProduct->id,
            'is_available' => false,
        ]);

        $cartService = app(CartService::class);

        // 1. Tolak penambahan produk yang di-nonaktifkan di cabang
        $this->expectException(ValidationException::class);
        $cartService->addItem($unavailableProduct, [], 1);
    }

    public function test_cart_service_uses_branch_override_price(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Premium Matcha',
            'product_price' => 30000,
            'is_active' => true,
            'status' => 'active',
            'stock' => 5,
        ]);

        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 34000.00,
        ]);

        $cartService = app(CartService::class);
        $item = $cartService->addItem($product, [], 1);

        $this->assertEquals(34000.00, $item['base_price']);
        $this->assertEquals(34000.00, $item['unit_price']);
    }

    public function test_transaction_service_rejects_inactive_or_branch_unavailable_products(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $inactiveProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Draft Item',
            'product_price' => 10000,
            'is_active' => false,
            'status' => 'draft',
        ]);

        $txService = app(TransactionService::class);

        $payload = [
            'store_id' => $this->storeA->id,
            'items' => [
                [
                    'product_id' => $inactiveProduct->id,
                    'quantity' => 1,
                ],
            ],
            'payment_method' => 'cash',
        ];

        $this->expectException(ValidationException::class);
        $txService->createTransaction($payload, $this->user->id);
    }

    public function test_service_and_non_inventory_products_bypass_stock_movements_during_checkout(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $serviceProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Delivery Service Fee',
            'product_type' => 'service',
            'product_price' => 15000,
            'track_inventory' => false,
            'stock' => 0,
            'is_active' => true,
            'status' => 'active',
        ]);

        $txService = app(TransactionService::class);

        $initialMovementCount = DB::table('stock_movements')->count();

        $payload = [
            'store_id' => $this->storeA->id,
            'items' => [
                [
                    'product_id' => $serviceProduct->id,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 40000,
        ];

        $order = $txService->createTransaction($payload, $this->user->id);

        $this->assertNotNull($order->id);
        $this->assertEquals(30000.00, (float) $order->subtotal_amount);

        // Verifikasi tidak ada stock_movements yang tercipta untuk produk service
        $newMovementCount = DB::table('stock_movements')->count();
        $this->assertSame($initialMovementCount, $newMovementCount);
    }

    public function test_parked_cart_revalidation_flags_status_and_assortment_changes(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Special Smoothie',
            'product_price' => 22000,
            'is_active' => true,
            'status' => 'active',
            'stock' => 10,
        ]);

        $cartService = app(CartService::class);

        // Park cart saat produk masih aktif
        $parked = ParkedCart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->device->id,
            'shift_id' => $this->shift->id,
            'parked_by_user_id' => $this->user->id,
            'hold_reference' => 'HOLD-TEST-001',
            'status' => ParkedCart::STATUS_PARKED,
            'cart_payload' => [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'qty' => 2,
                        'base_price' => 22000,
                        'unit_price' => 22000,
                    ],
                ],
            ],
        ]);

        // Nonaktifkan produk di cabang A
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => false,
        ]);

        // Coba resume parked cart
        $result = $cartService->resumeParkedCart(
            $parked->id,
            $this->tenant->id,
            $this->storeA->id,
            $this->device->id,
            $this->shift->id,
            $this->user->id
        );

        $this->assertTrue($result['has_blocking_issues']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('sudah tidak tersedia di cabang ini', $result['warnings'][0]);
    }

    public function test_entitlement_service_asserts_product_quota_limit(): void
    {
        $entitlementService = app(EntitlementService::class);
        $productService = app(ProductService::class);
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        // Set override kuota max_products = 2 untuk tenant ini
        TenantEntitlementOverride::create([
            'tenant_id' => $this->tenant->id,
            'feature_code' => EntitlementService::QUOTA_MAX_PRODUCTS,
            'value' => '2',
            'expires_at' => now()->addDays(30),
        ]);
        $entitlementService->clearCache($this->tenant->id);

        // Buat produk 1
        $productService->store([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Quota Prod 1',
            'product_price' => 10000,
            'status' => 'active',
        ]);

        // Buat produk 2
        $productService->store([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Quota Prod 2',
            'product_price' => 12000,
            'status' => 'active',
        ]);

        // Buat produk 3 wajib melempar DomainException karena melampaui kuota (2)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Batas kuota produk (2)');

        $productService->store([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Quota Prod 3 Exceeded',
            'product_price' => 15000,
            'status' => 'active',
        ]);
    }
}
