<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Modules\Products\Services\CatalogPriceResolverService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class CustomerPricingIntegrationTest extends TestCase
{
    use RefreshDatabase, TwoTenantFixture;

    protected TransactionService $transactionService;

    protected CatalogPriceResolverService $priceResolver;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->setupTwoTenants();
        $this->transactionService = app(TransactionService::class);
        $this->priceResolver = app(CatalogPriceResolverService::class);
    }

    public function test_transaction_service_resolves_contract_price_when_customer_selected(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Create B2B Customer in Tenant A
        $customerA = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'PT Pelanggan Kontrak A',
            'customer_code' => 'CUST-KTR-A',
            'phone' => '+6281122334455',
            'customer_type' => 'COMPANY',
            'is_active' => true,
        ]);

        // Standard Product price is $this->productA->product_price (e.g. 25000)
        $standardPrice = (float) $this->productA->product_price;

        // 2. Setup Customer Contract Price List for Customer A at 21,000
        $contractPl = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kontrak Khusus PT Pelanggan A',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $customerA->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $contractPl->id,
            'product_id' => $this->productA->id,
            'min_quantity' => 1.0,
            'price' => 21000.00,
        ]);

        // 3. Resolve line for Walk-in (no customer) -> gets standard price
        $walkInLine = $this->transactionService->resolveOrderItemLine(
            product: $this->productA,
            modifierIds: [],
            qty: 2,
            notes: null,
            variantId: null,
            customerId: null
        );

        $this->assertEquals($standardPrice, $walkInLine['order_price']);
        $this->assertEquals($standardPrice * 2, $walkInLine['order_subtotal']);

        // 4. Resolve line for Contract Customer A -> gets contract price 21,000
        $contractLine = $this->transactionService->resolveOrderItemLine(
            product: $this->productA,
            modifierIds: [],
            qty: 2,
            notes: null,
            variantId: null,
            customerId: $customerA->id
        );

        $this->assertEquals(21000.00, $contractLine['order_price']);
        $this->assertEquals(42000.00, $contractLine['order_subtotal']);
    }

    public function test_customer_pricing_tenant_isolation(): void
    {
        $context = app(TenantContext::class);

        // 1. In Tenant A: Create contract for $productA at 18,000
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $customerA = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Customer Tenant A',
            'phone' => '+628111111111',
            'is_active' => true,
        ]);

        $contractA = PriceList::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Contract Tenant A',
            'type' => 'customer_specific',
            'target_type' => PriceList::TARGET_CUSTOMER_CONTRACT,
            'customer_id' => $customerA->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        PriceListItem::create([
            'tenant_id' => $this->tenantA->id,
            'price_list_id' => $contractA->id,
            'product_id' => $this->productA->id,
            'min_quantity' => 1.0,
            'price' => 18000.00,
        ]);

        // 2. In Tenant B: Query price for Tenant B's product
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);

        $priceB = $this->priceResolver->resolvePrice(
            product: $this->productB,
            storeId: $this->storeB->id,
            quantity: 1,
            customerId: $customerA->id // Passing cross-tenant customer ID
        );

        // Tenant B must not pick up Tenant A's contract price
        $this->assertEquals((float) $this->productB->product_price, $priceB);
        $this->assertNotEquals(18000.00, $priceB);
    }
}
