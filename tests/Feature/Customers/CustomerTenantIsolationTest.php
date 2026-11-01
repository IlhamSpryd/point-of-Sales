<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Livewire\Kasir\CreateOrder;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\LoyaltyTier;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Customers\Services\LoyaltyService;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

    public function test_create_order_select_customer_rejects_cross_tenant_store_credit(): void
    {
        $context = app(TenantContext::class);

        // 1. Setup Customer and StoreCreditAccount in Tenant A
        $context->setTenantId($this->tenantA->id);
        $customerA = $this->service->createCustomer([
            'name' => 'Tenant A Customer',
            'phone' => '081299990001',
            'email' => 'tenantA@cust.com',
            'customer_type' => 'b2c',
        ]);

        StoreCreditAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $customerA->id,
            'current_balance' => 250000.00,
            'status' => StoreCreditStatusEnum::ACTIVE,
        ]);

        // 2. Setup Customer and StoreCreditAccount in Tenant B
        $context->setTenantId($this->tenantB->id);
        $customerB = $this->service->createCustomer([
            'name' => 'Tenant B Customer',
            'phone' => '081299990002',
            'email' => 'tenantB@cust.com',
            'customer_type' => 'b2c',
        ]);
        StoreCreditAccount::create([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $customerB->id,
            'current_balance' => 75000.00,
            'status' => StoreCreditStatusEnum::ACTIVE,
        ]);

        // 3. User A (Owner in Tenant A, Store A) accesses POS
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->userAOwner);

        // Attempting to select Tenant B's customer from Tenant A cashier interface (IDOR attempt)
        Livewire::test(CreateOrder::class)
            ->call('selectCustomer', $customerB->id, $customerB->name)
            ->assertSet('customerId', null)
            ->assertSet('storeCreditBalance', 0.0);

        // Selecting Tenant A's own customer succeeds and loads store credit balance
        Livewire::test(CreateOrder::class)
            ->call('selectCustomer', $customerA->id, $customerA->name)
            ->assertSet('customerId', $customerA->id)
            ->assertSet('storeCreditBalance', 250000.0);
    }

    public function test_loyalty_service_tenant_isolation_prevents_cross_tenant_access(): void
    {
        $context = app(TenantContext::class);
        $loyaltyService = app(LoyaltyService::class);

        // Setup Tier for Tenant A
        $context->setTenantId($this->tenantA->id);
        $tierA = LoyaltyTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gold A',
            'min_points' => 100,
            'points_multiplier' => 2.0,
            'is_active' => true,
        ]);

        // Setup Tier for Tenant B
        $context->setTenantId($this->tenantB->id);
        $tierB = LoyaltyTier::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Diamond B',
            'min_points' => 100,
            'points_multiplier' => 5.0,
            'is_active' => true,
        ]);

        // Setup Customer and Account in Tenant A
        $context->setTenantId($this->tenantA->id);
        $customerA = $this->service->createCustomer([
            'name' => 'Loyal Cust A',
            'phone' => '081211110001',
            'email' => 'loyala@test.com',
            'customer_type' => 'b2c',
        ]);
        DB::table('customer_loyalty_accounts')
            ->where('customer_id', $customerA->id)
            ->update([
                'current_points' => 150,
                'lifetime_points_earned' => 150,
                'current_tier_id' => null,
            ]);

        // checkAndUpgradeTier for customerA must upgrade to Tier A, NOT Tier B
        $promotedTier = $loyaltyService->checkAndUpgradeTier($customerA);
        $this->assertNotNull($promotedTier);
        $this->assertEquals($tierA->id, $promotedTier->id);
        $this->assertEquals($this->tenantA->id, $promotedTier->getAttribute('tenant_id'));

        // calculateEarnablePoints for customerA with 100,000 DPP (multiplier 2.0 -> 20 points)
        $pointsEarned = $loyaltyService->calculateEarnablePoints($customerA, 100000);
        $this->assertEquals(20, $pointsEarned);

        // In Tenant B context, getCurrentPoints for mismatched tenant customer returns 0
        $context->setTenantId($this->tenantB->id);
        $customerInContextB = Customer::withoutGlobalScopes()->find($customerA->id);
        $customerInContextB?->setAttribute('tenant_id', $this->tenantB->id);
        $pointsInB = $customerInContextB ? $loyaltyService->getCurrentPoints($customerInContextB) : 0;
        $this->assertEquals(0, $pointsInB);
    }

    public function test_document_sequence_service_isolates_sequences_between_tenants(): void
    {
        $docSeqService = app(DocumentSequenceService::class);

        $seqA1 = $docSeqService->nextValue((int) $this->tenantA->id, 'PO', null, '202611');
        $seqA2 = $docSeqService->nextValue((int) $this->tenantA->id, 'PO', null, '202611');

        $seqB1 = $docSeqService->nextValue((int) $this->tenantB->id, 'PO', null, '202611');

        $this->assertEquals(1, $seqA1);
        $this->assertEquals(2, $seqA2);
        $this->assertEquals(1, $seqB1);

        $rowA = DB::table('document_sequences')
            ->where('tenant_id', $this->tenantA->id)
            ->where('doc_type', 'PO')
            ->first();
        $this->assertNotNull($rowA);
        $this->assertEquals(2, $rowA->last_value);

        $rowB = DB::table('document_sequences')
            ->where('tenant_id', $this->tenantB->id)
            ->where('doc_type', 'PO')
            ->first();
        $this->assertNotNull($rowB);
        $this->assertEquals(1, $rowB->last_value);
    }

    public function test_loyalty_service_fails_closed_when_tenant_context_is_missing(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $loyaltyService = app(LoyaltyService::class);

        $unscopedCustomer = new Customer;
        $unscopedCustomer->id = 9999;
        $unscopedCustomer->setAttribute('tenant_id', null);

        // 1. calculateEarnablePoints must throw RuntimeException when tenant context is missing
        $this->expectException(\RuntimeException::class);
        $loyaltyService->calculateEarnablePoints($unscopedCustomer, 100000);
    }

    public function test_loyalty_service_check_tier_fails_closed_when_tenant_context_is_missing(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $loyaltyService = app(LoyaltyService::class);

        $unscopedCustomer = new Customer;
        $unscopedCustomer->id = 9999;
        $unscopedCustomer->setAttribute('tenant_id', null);

        // 2. checkAndUpgradeTier must throw RuntimeException when tenant context is missing
        $this->expectException(\RuntimeException::class);
        $loyaltyService->checkAndUpgradeTier($unscopedCustomer);
    }

    public function test_loyalty_service_fails_closed_when_customer_tenant_conflicts_with_active_context(): void
    {
        $context = app(TenantContext::class);
        $loyaltyService = app(LoyaltyService::class);

        // Customer created in Tenant A
        $context->setTenantId($this->tenantA->id);
        $customerA = $this->service->createCustomer([
            'name' => 'Tenant A Loyal Customer',
            'phone' => '081299990099',
            'email' => 'tenantA_loyal@cust.com',
            'customer_type' => 'b2c',
        ]);

        // Switch active context to Tenant B
        $context->setTenantId($this->tenantB->id);

        // Attempting to calculate points for Tenant A customer while in Tenant B context
        $threwConflict = false;
        try {
            $loyaltyService->calculateEarnablePoints($customerA, 50000);
        } catch (\RuntimeException $e) {
            $threwConflict = str_contains($e->getMessage(), 'Konflik isolasi tenant');
        }
        $this->assertTrue($threwConflict, 'LoyaltyService::calculateEarnablePoints must fail closed on cross-tenant customer conflict');

        // Attempting to check tier for Tenant A customer while in Tenant B context
        $threwUpgradeConflict = false;
        try {
            $loyaltyService->checkAndUpgradeTier($customerA);
        } catch (\RuntimeException $e) {
            $threwUpgradeConflict = str_contains($e->getMessage(), 'Konflik isolasi tenant');
        }
        $this->assertTrue($threwUpgradeConflict, 'LoyaltyService::checkAndUpgradeTier must fail closed on cross-tenant customer conflict');

        // Attempting to get points for Tenant A customer while in Tenant B context
        $threwPointsConflict = false;
        try {
            $loyaltyService->getCurrentPoints($customerA);
        } catch (\RuntimeException $e) {
            $threwPointsConflict = str_contains($e->getMessage(), 'Konflik isolasi tenant');
        }
        $this->assertTrue($threwPointsConflict, 'LoyaltyService::getCurrentPoints must fail closed on cross-tenant customer conflict');
    }
}
