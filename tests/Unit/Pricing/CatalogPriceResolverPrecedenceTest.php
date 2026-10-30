<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Products\DataTransferObjects\PriceResolutionContextDto;
use App\Modules\Products\DataTransferObjects\ResolvedPriceDto;
use App\Modules\Products\Services\CatalogPriceResolverService;
use App\Modules\Sales\Services\CartService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPriceResolverPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    private Category $category;

    private CatalogPriceResolverService $resolver;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Pricing Test Tenant',
            'slug' => 'pricing-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama',
            'is_active' => true,
        ]);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'General Category',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);
        session(['current_store_id' => $this->store->id, 'current_tenant_id' => $this->tenant->id]);

        $this->resolver = app(CatalogPriceResolverService::class);
    }

    private function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'is_active' => true,
            'status' => 'active',
        ], $attributes));
    }

    public function test_customer_contract_price_overrides_branch_and_base_price(): void
    {
        // Base Product: 10.000
        $product = $this->createProduct([
            'product_name' => 'Kopi Arabika 250g',
            'product_price' => 10000,
        ]);

        // Branch Override: 12.000
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 12000,
        ]);

        // Customer
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Kopi Sejahtera',
            'email' => 'b2b@kopisejahtera.com',
            'phone' => '081122334455',
            'is_active' => true,
        ]);

        // Prioritas 1: Customer Contract Price = 8.500
        $priceList = PriceList::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kontrak B2B Kopi Sejahtera',
            'code' => 'PL-B2B-01',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $customer->id,
            'currency' => 'IDR',
            'priority' => 1,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenant->id,
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'min_quantity' => 1.0,
            'price' => 8500,
        ]);

        // Selesaikan harga untuk customer tersebut
        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $product->id,
            storeId: $this->store->id,
            quantity: 2,
            customerId: $customer->id
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);

        $this->assertSame(8500.0, (float) $resolved->unitPrice);
        $this->assertSame(ResolvedPriceDto::SOURCE_CUSTOMER_CONTRACT, $resolved->pricingSource);
    }

    public function test_customer_group_price_overrides_branch_override(): void
    {
        $group = CustomerGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VIP Wholesale',
            'code' => 'GRP-VIP',
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_group_id' => $group->id,
            'name' => 'Pelanggan VIP',
            'phone' => '081299998888',
            'is_active' => true,
        ]);

        $product = $this->createProduct([
            'product_name' => 'Teh Melati Premium',
            'product_price' => 20000,
        ]);

        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 22000,
        ]);

        $priceList = PriceList::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Price List VIP Group',
            'code' => 'PL-GRP-VIP',
            'type' => 'wholesale',
            'target_type' => PriceList::TARGET_CUSTOMER_GROUP,
            'customer_group_id' => $group->id,
            'currency' => 'IDR',
            'priority' => 2,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenant->id,
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'min_quantity' => 1.0,
            'price' => 17500,
        ]);

        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $product->id,
            storeId: $this->store->id,
            quantity: 5,
            customerId: $customer->id
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);

        $this->assertSame(17500.0, (float) $resolved->unitPrice);
        $this->assertSame(ResolvedPriceDto::SOURCE_CUSTOMER_GROUP, $resolved->pricingSource);
    }

    public function test_branch_override_takes_precedence_over_base_product_price(): void
    {
        $product = $this->createProduct([
            'product_name' => 'Matcha Latte',
            'product_price' => 30000,
        ]);

        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 35000,
        ]);

        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $product->id,
            storeId: $this->store->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);

        $this->assertSame(35000.0, (float) $resolved->unitPrice);
        $this->assertSame(ResolvedPriceDto::SOURCE_STORE_OVERRIDE, $resolved->pricingSource);
    }

    public function test_open_price_is_resolved_when_flag_enabled(): void
    {
        $product = $this->createProduct([
            'product_name' => 'Kue Custom Special',
            'product_price' => 0,
            'is_open_price' => true,
        ]);

        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $product->id,
            storeId: $this->store->id,
            quantity: 1,
            customPrice: 75000.0
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);

        $this->assertSame(75000.0, (float) $resolved->unitPrice);
        $this->assertSame(ResolvedPriceDto::SOURCE_OPEN_PRICE, $resolved->pricingSource);
    }

    public function test_cart_service_refresh_cart_prices_uses_price_resolver(): void
    {
        $product = $this->createProduct([
            'product_name' => 'Croissant Butter',
            'product_price' => 25000,
        ]);

        // Tambah override cabang 28.000
        StoreProductAssortment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'is_available' => true,
            'store_price_override' => 28000,
        ]);

        $cartService = app(CartService::class);
        $cartService->addItem($product, [], 1);

        // Awalnya dari addItem sudah 28.000
        $items = $cartService->getItems();
        $firstItem = reset($items);
        $this->assertSame(28000.0, (float) $firstItem['base_price']);

        // Ubah override menjadi 32.000 di database
        StoreProductAssortment::where('product_id', $product->id)->update(['store_price_override' => 32000]);

        // Panggil refreshCartPrices
        $cartService->refreshCartPrices();

        $refreshedItems = $cartService->getItems();
        $refreshedItem = reset($refreshedItems);

        // Harus merefleksikan 32.000 (bukan 25.000 base_price produk)
        $this->assertSame(32000.0, (float) $refreshedItem['base_price']);
        $this->assertSame(32000.0, (float) $refreshedItem['unit_price']);
    }
}
