<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Modules\Customers\Services\CustomerService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class CustomerTenantIsolationTest extends TestCase
{
    use RefreshDatabase, TwoTenantFixture;

    protected CustomerService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->setupTwoTenants();
        $this->service = app(CustomerService::class);
    }

    public function test_customer_created_in_tenant_a_cannot_be_retrieved_in_tenant_b(): void
    {
        $context = app(TenantContext::class);

        // 1. Create Customer in Tenant A
        $context->setTenantId($this->tenantA->id);
        $customerA = $this->service->createCustomer([
            'name' => 'Tenant A Customer',
            'phone' => '081299990001',
            'email' => 'tenantA@cust.com',
            'customer_type' => 'b2c',
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customerA->id,
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Customer',
        ]);

        // 2. Switch to Tenant B and attempt query
        $context->setTenantId($this->tenantB->id);

        $found = Customer::query()->find($customerA->id);
        $this->assertNull($found, 'Tenant B must not see Tenant A customer via Eloquent query');

        $this->assertCount(0, Customer::query()->where('phone', '+6281299990001')->get());
    }

    public function test_customer_group_isolation_between_tenants(): void
    {
        $context = app(TenantContext::class);

        // 1. Create Customer Group in Tenant A
        $context->setTenantId($this->tenantA->id);
        $groupA = CustomerGroup::create([
            'name' => 'VIP Wholesale A',
            'code' => 'VIP-A',
            'discount_percentage' => 10.00,
        ]);

        // 2. Switch to Tenant B
        $context->setTenantId($this->tenantB->id);

        $foundGroup = CustomerGroup::query()->find($groupA->id);
        $this->assertNull($foundGroup, 'Tenant B must not see Tenant A group');

        // Tenant B should be allowed to create a group with the same code (tenant scoped unique)
        $groupB = CustomerGroup::create([
            'name' => 'VIP Wholesale B',
            'code' => 'VIP-A',
            'discount_percentage' => 15.00,
        ]);

        $this->assertDatabaseHas('customer_groups', [
            'id' => $groupB->id,
            'tenant_id' => $this->tenantB->id,
            'code' => 'VIP-A',
        ]);
    }
}
