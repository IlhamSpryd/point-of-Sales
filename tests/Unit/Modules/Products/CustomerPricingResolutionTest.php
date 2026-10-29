<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use App\Modules\Products\DataTransferObjects\PriceResolutionContextDto;
use App\Modules\Products\DataTransferObjects\ResolvedPriceDto;
use App\Modules\Products\Services\CatalogPriceResolverService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPricingResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected CatalogPriceResolverService $resolver;

    protected Tenant $tenant;

    protected Store $store;

    protected Product $product;

    protected ProductVariant $variant;

    protected CustomerGroup $vipGroup;

    protected Customer $b2bCustomer;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->resolver = app(CatalogPriceResolverService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Pricing Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Main Store',
        ]);
        $context->setStoreId($this->store->id);

        $category = Category::create([
            'category_name' => 'Beverages',
            'category_code' => 'BEV',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Specialty Blend Beans 1kg',
            'product_code' => 'BEAN-001',
            'product_price' => 150000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'variant_name' => 'Dark Roast',
            'sku' => 'BEAN-001-DARK',
            'price_override' => 160000.00,
            'is_active' => true,
        ]);

        $this->vipGroup = CustomerGroup::create([
            'code' => 'GRP-VIP',
            'name' => 'VIP Wholesale Group',
            'default_discount_rate' => 10.00,
        ]);

        $this->b2bCustomer = Customer::create([
            'name' => 'PT Mitra Sejati',
            'customer_code' => 'CUST-B2B-01',
            'customer_type' => 'COMPANY',
            'customer_group_id' => $this->vipGroup->id,
            'phone' => '+6281299998888',
            'is_active' => true,
        ]);
    }

    public function test_hierarchy_level_4_base_product_and_variant(): void
    {
        // 4a. Base Master Product
        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);
        $this->assertEquals(150000.00, $resolved->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_BASE_PRODUCT, $resolved->pricingSource);

        // 4b. Variant Price Override
        $variantContext = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            variantId: $this->variant->id,
            quantity: 1
        );

        $resolvedVariant = $this->resolver->resolveCustomerPrice($variantContext);
        $this->assertEquals(160000.00, $resolvedVariant->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_VARIANT_OVERRIDE, $resolvedVariant->pricingSource);
    }

    public function test_hierarchy_level_3_store_price_list_and_assortment_override(): void
    {
        // 3a. StoreProductAssortment override wins over Level 4
        StoreProductAssortment::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'store_price_override' => 145000.00,
            'is_available' => true,
        ]);

        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);
        $this->assertEquals(145000.00, $resolved->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_STORE_OVERRIDE, $resolved->pricingSource);

        // 3b. Store PriceList wins over assortment override
        $storePl = PriceList::create([
            'name' => 'Store Promo Price List',
            'type' => 'branch',
            'target_type' => PriceList::TARGET_STORE,
            'store_id' => $this->store->id,
            'priority' => 50,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'price_list_id' => $storePl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 140000.00,
        ]);

        $resolvedPl = $this->resolver->resolveCustomerPrice($context);
        $this->assertEquals(140000.00, $resolvedPl->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_STORE_PRICE_LIST, $resolvedPl->pricingSource);
    }

    public function test_hierarchy_level_2_customer_group_wins_over_store(): void
    {
        // Setup Store Price List (Level 3)
        $storePl = PriceList::create([
            'name' => 'Store Price List',
            'type' => 'branch',
            'target_type' => PriceList::TARGET_STORE,
            'store_id' => $this->store->id,
            'priority' => 50,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $storePl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 140000.00,
        ]);

        // Setup Customer Group Price List (Level 2)
        $groupPl = PriceList::create([
            'name' => 'VIP Group Price List',
            'type' => 'customer_tier',
            'target_type' => PriceList::TARGET_CUSTOMER_GROUP,
            'customer_group_id' => $this->vipGroup->id,
            'priority' => 40,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $groupPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 130000.00,
        ]);

        $contextWithCustomer = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            customerId: $this->b2bCustomer->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($contextWithCustomer);
        $this->assertEquals(130000.00, $resolved->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_CUSTOMER_GROUP, $resolved->pricingSource);

        // Without customer, it falls back to store price list (Level 3)
        $contextWalkIn = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            quantity: 1
        );
        $resolvedWalkIn = $this->resolver->resolveCustomerPrice($contextWalkIn);
        $this->assertEquals(140000.00, $resolvedWalkIn->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_STORE_PRICE_LIST, $resolvedWalkIn->pricingSource);
    }

    public function test_hierarchy_level_1_customer_contract_wins_over_all(): void
    {
        // Setup Customer Group Price List (Level 2)
        $groupPl = PriceList::create([
            'name' => 'VIP Group Price List',
            'type' => 'customer_tier',
            'target_type' => PriceList::TARGET_CUSTOMER_GROUP,
            'customer_group_id' => $this->vipGroup->id,
            'priority' => 40,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $groupPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 130000.00,
        ]);

        // Setup Customer Contract Price List (Level 1)
        $contractPl = PriceList::create([
            'name' => 'PT Mitra Sejati Contract 2026',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'priority' => 10,
            'is_active' => true,
            'allow_promo_stacking' => false,
        ]);
        PriceListItem::create([
            'price_list_id' => $contractPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 120000.00,
        ]);

        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            customerId: $this->b2bCustomer->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);
        $this->assertEquals(120000.00, $resolved->unitPrice);
        $this->assertEquals(ResolvedPriceDto::SOURCE_CUSTOMER_CONTRACT, $resolved->pricingSource);
        $this->assertEquals($contractPl->id, $resolved->priceListId);
        $this->assertFalse($resolved->allowPromoStacking);
    }

    public function test_quantity_breaks_tiers(): void
    {
        $contractPl = PriceList::create([
            'name' => 'Wholesale Quantity Breaks',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        // Tier 1: 1 - 4 pcs -> 120,000
        PriceListItem::create([
            'price_list_id' => $contractPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'max_quantity' => 4.0,
            'price' => 120000.00,
        ]);

        // Tier 2: 5 - 9 pcs -> 110,000
        PriceListItem::create([
            'price_list_id' => $contractPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 5.0,
            'max_quantity' => 9.0,
            'price' => 110000.00,
        ]);

        // Tier 3: 10+ pcs -> 100,000
        PriceListItem::create([
            'price_list_id' => $contractPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 10.0,
            'max_quantity' => null,
            'price' => 100000.00,
        ]);

        // Qty 2 -> Tier 1 (120,000)
        $p1 = $this->resolver->resolvePrice($this->product, $this->store->id, null, 2, $this->b2bCustomer->id);
        $this->assertEquals(120000.00, $p1);

        // Qty 7 -> Tier 2 (110,000)
        $p2 = $this->resolver->resolvePrice($this->product, $this->store->id, null, 7, $this->b2bCustomer->id);
        $this->assertEquals(110000.00, $p2);

        // Qty 25 -> Tier 3 (100,000)
        $p3 = $this->resolver->resolvePrice($this->product, $this->store->id, null, 25, $this->b2bCustomer->id);
        $this->assertEquals(100000.00, $p3);
    }

    public function test_effective_date_range_is_strictly_enforced(): void
    {
        // Future Contract Price List
        $futurePl = PriceList::create([
            'name' => 'Future Contract',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'valid_from' => now()->addDays(7),
            'valid_until' => now()->addDays(30),
            'priority' => 5,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $futurePl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 80000.00,
        ]);

        // Active should fallback to base because future contract is not yet effective
        $price = $this->resolver->resolvePrice($this->product, $this->store->id, null, 1, $this->b2bCustomer->id);
        $this->assertEquals(150000.00, $price);

        // Past Expired Contract
        $expiredPl = PriceList::create([
            'name' => 'Expired Contract',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'valid_from' => now()->subDays(60),
            'valid_until' => now()->subDays(10),
            'priority' => 5,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $expiredPl->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 70000.00,
        ]);

        $priceExpired = $this->resolver->resolvePrice($this->product, $this->store->id, null, 1, $this->b2bCustomer->id);
        $this->assertEquals(150000.00, $priceExpired);
    }

    public function test_tie_breaker_lower_priority_number_wins(): void
    {
        // Contract A: Priority 20 -> 115,000
        $plA = PriceList::create([
            'name' => 'Contract A',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'priority' => 20,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $plA->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 115000.00,
        ]);

        // Contract B: Priority 10 (higher precedence) -> 125,000
        $plB = PriceList::create([
            'name' => 'Contract B Priority 10',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $this->b2bCustomer->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $plB->id,
            'product_id' => $this->product->id,
            'min_quantity' => 1.0,
            'price' => 125000.00,
        ]);

        // Contract B wins because priority 10 < 20
        $context = new PriceResolutionContextDto(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            storeId: $this->store->id,
            customerId: $this->b2bCustomer->id,
            quantity: 1
        );

        $resolved = $this->resolver->resolveCustomerPrice($context);
        $this->assertEquals(125000.00, $resolved->unitPrice);
        $this->assertEquals($plB->id, $resolved->priceListId);
    }
}
