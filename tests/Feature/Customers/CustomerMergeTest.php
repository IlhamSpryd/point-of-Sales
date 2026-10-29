<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerMergeLog;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Services\CustomerService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected CustomerService $customerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->customerService = app(CustomerService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Merge Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Merge Store',
        ]);

        $role = Role::create([
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->user = User::create([
            'name' => 'Owner Merge',
            'email' => 'owner.merge@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $context->setStoreId($this->store->id);
    }

    public function test_merge_customers_atomically_reassigns_records_and_creates_log(): void
    {
        // 1. Create Source Customer with address, contact, and order
        $source = $this->customerService->createCustomer([
            'name' => 'Source Customer',
            'phone' => '081234567001',
            'email' => 'source@test.com',
        ], $this->user);

        $sourceAddress = $this->customerService->addAddress($source, [
            'address_type' => 'SHIPPING',
            'label' => 'Alamat Sumber',
            'address_line1' => 'Jl. Asal No. 1',
            'is_default_shipping' => true,
        ]);

        $sourceContact = $this->customerService->addContact($source, [
            'name' => 'PIC Sumber',
            'phone' => '081234567002',
            'is_primary' => true,
        ]);

        $sourceOrder = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'customer_id' => $source->id,
            'order_code' => 'ORD-TEST-001',
            'order_type' => 'takeaway',
            'order_status' => 'completed',
            'order_date' => now(),
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'idempotency_key' => 'idem-test-merge-001',
        ]);

        // 2. Create Target Customer
        $target = $this->customerService->createCustomer([
            'name' => 'Target Customer',
            'phone' => '081234567099',
            'email' => 'target@test.com',
        ], $this->user);

        // 3. Execute Merge
        $mergeLog = $this->customerService->mergeCustomers(
            $source,
            $target,
            $this->user,
            'Penggabungan akun duplikat saat verifikasi nomor kontak'
        );

        // 4. Verify CustomerMergeLog
        $this->assertInstanceOf(CustomerMergeLog::class, $mergeLog);
        $this->assertEquals($source->id, $mergeLog->source_customer_id);
        $this->assertEquals($target->id, $mergeLog->target_customer_id);
        $this->assertEquals($this->user->id, $mergeLog->merged_by_user_id);
        $this->assertEquals(1, $mergeLog->reassigned_records_json['orders']);
        $this->assertEquals(1, $mergeLog->reassigned_records_json['customer_addresses']);
        $this->assertEquals(1, $mergeLog->reassigned_records_json['customer_contacts']);

        // 5. Verify Reassignments
        $this->assertEquals($target->id, $sourceOrder->fresh()->customer_id);
        $this->assertEquals($target->id, $sourceAddress->fresh()->customer_id);
        $this->assertEquals($target->id, $sourceContact->fresh()->customer_id);
        $this->assertFalse($sourceAddress->fresh()->is_default_shipping);
        $this->assertFalse($sourceContact->fresh()->is_primary);

        // 6. Verify Source Customer state
        $freshSource = Customer::withoutGlobalScopes()->find($source->id);
        $this->assertEquals($target->id, $freshSource->merged_into_customer_id);
        $this->assertFalse($freshSource->is_active);
        $this->assertNotNull($freshSource->deleted_at);
    }

    public function test_cannot_merge_customer_into_itself(): void
    {
        $cust = $this->customerService->createCustomer([
            'name' => 'Same Customer',
        ], $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pelanggan sumber dan target tidak boleh sama.');

        $this->customerService->mergeCustomers($cust, $cust, $this->user, 'Merge to self');
    }

    public function test_cannot_merge_already_merged_customer(): void
    {
        $source = $this->customerService->createCustomer(['name' => 'Source'], $this->user);
        $target = $this->customerService->createCustomer(['name' => 'Target'], $this->user);
        $third = $this->customerService->createCustomer(['name' => 'Third'], $this->user);

        $this->customerService->mergeCustomers($source, $target, $this->user, 'First merge');

        $this->expectException(DomainException::class);
        // Trying to merge source again
        $this->customerService->mergeCustomers($source, $third, $this->user, 'Second merge');
    }

    public function test_cannot_merge_customers_across_tenants(): void
    {
        $tenant2 = Tenant::create([
            'name' => 'Tenant 2',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $source = $this->customerService->createCustomer(['name' => 'Tenant 1 Customer'], $this->user);

        app(TenantContext::class)->setTenantId($tenant2->id);
        $user2 = User::create([
            'tenant_id' => $tenant2->id,
            'name' => 'User Tenant 2',
            'email' => 'user@tenant2.com',
            'password' => bcrypt('password'),
        ]);
        $target = $this->customerService->createCustomer(['name' => 'Tenant 2 Customer'], $user2);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tidak dapat menggabungkan pelanggan dari tenant yang berbeda.');

        $this->customerService->mergeCustomers($source, $target, $this->user, 'Cross tenant merge');
    }
}
