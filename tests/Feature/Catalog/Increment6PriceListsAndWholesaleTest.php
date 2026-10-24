<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Customer;
use App\Models\LoyaltyTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Increment6PriceListsAndWholesaleTest extends TestCase
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
            'name' => 'Inc6 Tenant Alpha',
            'subdomain' => 'inc6-alpha',
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

        // Setup Tenant B (untuk cross-tenant rejection)
        $this->tenantB = Tenant::create([
            'name' => 'Inc6 Tenant Beta',
            'subdomain' => 'inc6-beta',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $this->actingAs($this->userA);
    }

    public function test_price_lists_and_items_creation_with_tenant_isolation(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);
        $product = Product::factory()->create(['tenant_id' => $this->tenantA->id, 'category_id' => $category->id]);

        $priceListA = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Standard Price List Alpha',
            'code' => 'PL-STD-A',
            'type' => 'standard',
            'priority' => 10,
            'is_active' => true,
        ]);

        $itemA = PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $priceListA->id,
            'product_id' => $product->id,
            'min_quantity' => 1,
            'price' => 45000,
        ]);

        $this->assertCount(1, $priceListA->items);
        $this->assertEquals(45000, $priceListA->items->first()->price);

        // Tenant B membuat code yang sama -> Boleh karena unik per tenant
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);

        $priceListB = PriceList::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Standard Price List Beta',
            'code' => 'PL-STD-A',
            'type' => 'standard',
        ]);

        $this->assertNotNull($priceListB);
        $this->assertNotEquals($priceListA->id, $priceListB->id);

        $context->setTenantId($this->tenantA->id);
    }

    public function test_price_resolution_tier_1_customer_specific_overrides_everything(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 100000, // Tier 5 (Base price)
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $product->id,
            'variant_name' => 'Size M',
            'price_override' => 90000, // Tier 4
        ]);

        // Tier 3: Cabang override
        StoreProductAssortment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'is_available' => true,
            'store_price_override' => 85000, // Tier 3
        ]);

        // Pelanggan VIP
        $customerVip = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'PT Pelanggan Prioritas',
            'customer_code' => 'CUST-VIP-01',
        ]);

        // Tier 1: Kontrak harga khusus pelanggan
        $customerPl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kontrak VIP PT Pelanggan Prioritas',
            'type' => 'customer_specific',
            'customer_id' => $customerVip->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $customerPl->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'min_quantity' => 1,
            'price' => 75000, // Harga kontrak khusus
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // 1. Pelanggan umum (tanpa customer_id) -> Mendapatkan Tier 3 (Harga Cabang 85.000)
        $priceGeneral = $resolver->resolvePrice($product, $this->storeA->id, $variant->id);
        $this->assertEquals(85000, $priceGeneral);

        // 2. Pelanggan VIP -> Mendapatkan Tier 1 (Harga Kontrak 75.000)
        $priceVip = $resolver->resolvePrice(
            product: $product,
            storeId: $this->storeA->id,
            variantId: $variant->id,
            quantity: 1,
            customerId: $customerVip->id
        );
        $this->assertEquals(75000, $priceVip);
    }

    public function test_price_resolution_tier_2_wholesale_tiered_pricing(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 50000,
        ]);

        $wholesalePl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tier Harga Grosir',
            'type' => 'wholesale',
            'priority' => 5,
            'is_active' => true,
        ]);

        // Bracket 1: Beli >= 10 unit -> Rp 45.000
        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $wholesalePl->id,
            'product_id' => $product->id,
            'min_quantity' => 10,
            'price' => 45000,
        ]);

        // Bracket 2: Beli >= 50 unit -> Rp 40000
        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $wholesalePl->id,
            'product_id' => $product->id,
            'min_quantity' => 50,
            'price' => 40000,
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // 1. Beli 5 unit (di bawah 10) -> Harga normal Rp 50.000
        $this->assertEquals(50000, $resolver->resolvePrice($product, null, null, quantity: 5));

        // 2. Beli 15 unit (termasuk bracket 1) -> Rp 45.000
        $this->assertEquals(45000, $resolver->resolvePrice($product, null, null, quantity: 15));

        // 3. Beli 100 unit (termasuk bracket 2) -> Rp 40.000
        $this->assertEquals(40000, $resolver->resolvePrice($product, null, null, quantity: 100));
    }

    public function test_price_resolution_tier_2_channel_and_customer_tier_pricing(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 30000,
        ]);

        // 1. Price list untuk kanal 'online_delivery'
        $channelPl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Harga Khusus Delivery Online',
            'type' => 'channel',
            'channel' => 'online_delivery',
            'priority' => 8,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $channelPl->id,
            'product_id' => $product->id,
            'min_quantity' => 1,
            'price' => 35000,
        ]);

        // 2. Price list untuk Gold Member Loyalty Tier
        $goldTier = LoyaltyTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gold Member',
            'tier_code' => 'GOLD',
            'min_points' => 1000,
        ]);

        $tierPl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Diskon Khusus Member Gold',
            'type' => 'customer_tier',
            'loyalty_tier_id' => $goldTier->id,
            'priority' => 4, // Prioritas lebih tinggi daripada channel
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $tierPl->id,
            'product_id' => $product->id,
            'min_quantity' => 1,
            'price' => 27000,
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // Kasus A: Kanal online_delivery tanpa loyalty tier -> Rp 35.000
        $priceChannel = $resolver->resolvePrice($product, channel: 'online_delivery');
        $this->assertEquals(35000, $priceChannel);

        // Kasus B: Member Gold di kanal online_delivery -> Prioritas 4 (Tier PL) menang atas Prioritas 8 (Channel PL) -> Rp 27.000
        $priceGold = $resolver->resolvePrice($product, loyaltyTierId: $goldTier->id, channel: 'online_delivery');
        $this->assertEquals(27000, $priceGold);
    }

    public function test_price_resolution_date_validity_period(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 50000,
        ]);

        // Price list kedaluwarsa (masa berlaku kemarin)
        $expiredPl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Promo Kemarin',
            'type' => 'standard',
            'priority' => 1,
            'valid_from' => now()->subDays(5),
            'valid_until' => now()->subDay(),
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $expiredPl->id,
            'product_id' => $product->id,
            'min_quantity' => 1,
            'price' => 30000,
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // Karena sudah kedaluwarsa, harus fallback ke harga dasar Rp 50.000
        $price = $resolver->resolvePrice($product);
        $this->assertEquals(50000, $price);
    }

    public function test_price_resolution_tier_3_to_5_fallback_hierarchy(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenantA->id]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $category->id,
            'product_price' => 100000, // Tier 5
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $product->id,
            'variant_name' => 'Size M',
            'price_override' => 110000, // Tier 4
        ]);

        $resolver = app(CatalogPriceResolverService::class);

        // Tanpa assortment dan tanpa price list:
        // Produk biasa -> Tier 5 (100.000)
        $this->assertEquals(100000, $resolver->resolvePrice($product));
        // Varian -> Tier 4 (110.000)
        $this->assertEquals(110000, $resolver->resolvePrice($product, variantId: $variant->id));

        // Tambahkan override cabang untuk produk -> Tier 3 (105.000)
        StoreProductAssortment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 105000,
        ]);

        // Produk di cabang storeA -> Tier 3 (105.000)
        $this->assertEquals(105000, $resolver->resolvePrice($product, storeId: $this->storeA->id));
    }

    public function test_cross_tenant_price_list_rejection(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);

        $productTenantB = Product::factory()->create(['tenant_id' => $this->tenantB->id]);

        $context->setTenantId($this->tenantA->id);

        $priceListA = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Price List Alpha',
            'type' => 'standard',
        ]);

        $this->expectException(QueryException::class);

        // Mencoba mereferensikan produk milik Tenant B ke PriceListItem Tenant A ditolak oleh foreign key composite
        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $priceListA->id,
            'product_id' => $productTenantB->id,
            'min_quantity' => 1,
            'price' => 50000,
        ]);
    }
}
