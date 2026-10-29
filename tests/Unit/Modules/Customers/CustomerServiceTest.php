<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Customers;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Services\CustomerService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CustomerService $service;

    protected Tenant $tenant;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->service = app(CustomerService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Customer Unit Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->user = User::create([
            'name' => 'Owner User',
            'email' => 'owner@customer-unit-test.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_phone_and_email_normalization(): void
    {
        $this->assertEquals('+628123456789', $this->service->normalizePhone('0812-3456-789'));
        $this->assertEquals('+628123456789', $this->service->normalizePhone('+62 812-3456-789'));
        $this->assertNull($this->service->normalizePhone(''));
        $this->assertNull($this->service->normalizePhone(null));

        $this->assertEquals('john.doe@example.com', $this->service->normalizeEmail('  JOHN.DOE@EXAMPLE.COM '));
        $this->assertNull($this->service->normalizeEmail(''));
        $this->assertNull($this->service->normalizeEmail(null));
    }

    public function test_create_b2c_individual_customer(): void
    {
        $customer = $this->service->createCustomer([
            'name' => 'Siti Aminah',
            'customer_type' => 'INDIVIDUAL',
            'phone' => '081298765432',
            'email' => 'siti@example.com',
        ], $this->user);

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEquals('Siti Aminah', $customer->name);
        $this->assertEquals('+6281298765432', $customer->phone);
        $this->assertEquals('siti@example.com', $customer->email);
        $this->assertEquals('INDIVIDUAL', $customer->customer_type);
        $this->assertStringStartsWith('CUST-', $customer->customer_code);
        $this->assertTrue($customer->isIndividual());
        $this->assertFalse($customer->isCompany());
        $this->assertNotNull($customer->loyaltyAccount);
    }

    public function test_create_b2b_company_customer_with_contacts_and_addresses(): void
    {
        $group = CustomerGroup::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'WHOLESALE-VIP',
            'name' => 'Wholesale VIP',
            'default_discount_rate' => 15.00,
        ]);

        $customer = $this->service->createCustomer([
            'name' => 'Hendra Kusuma',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Maju Mundur Sentosa',
            'tax_id' => '01.234.567.8-012.000',
            'business_registration_no' => 'NIB-992837461',
            'customer_group_id' => $group->id,
            'phone' => '081122334455',
            'email' => 'purchasing@majumundur.com',
            'addresses' => [
                [
                    'address_type' => 'SHIPPING',
                    'label' => 'Gudang Utama',
                    'address_line1' => 'Jl. Industri No. 45',
                    'city' => 'Bekasi',
                    'province' => 'Jawa Barat',
                    'is_default_shipping' => true,
                ],
            ],
            'contacts' => [
                [
                    'name' => 'Rina Finance',
                    'role_title' => 'Finance Manager',
                    'email' => 'rina.finance@majumundur.com',
                    'phone' => '081234000111',
                    'is_primary' => true,
                ],
            ],
        ], $this->user);

        $this->assertTrue($customer->isCompany());
        $this->assertEquals('PT Maju Mundur Sentosa', $customer->company_name);
        $this->assertEquals('01.234.567.8-012.000', $customer->tax_id);
        $this->assertEquals($group->id, $customer->customer_group_id);
        $this->assertCount(1, $customer->addresses);
        $this->assertCount(1, $customer->contacts);
        $this->assertEquals('Gudang Utama', $customer->addresses->first()->label);
        $this->assertEquals('Rina Finance', $customer->contacts->first()->name);
        $this->assertTrue($customer->contacts->first()->is_primary);
    }

    public function test_duplicate_phone_or_email_throws_validation_exception(): void
    {
        $this->service->createCustomer([
            'name' => 'Customer A',
            'phone' => '081234567890',
            'email' => 'duplicate@example.com',
        ], $this->user);

        $this->expectException(ValidationException::class);

        $this->service->createCustomer([
            'name' => 'Customer B',
            'phone' => '081234567890', // duplicate phone
            'email' => 'other@example.com',
        ], $this->user);
    }

    public function test_update_customer_updates_fields_and_validates(): void
    {
        $customer = $this->service->createCustomer([
            'name' => 'Original Name',
            'phone' => '081111111111',
            'email' => 'orig@example.com',
        ], $this->user);

        $updated = $this->service->updateCustomer($customer, [
            'name' => 'Updated Name',
            'company_name' => 'New Company Corp',
            'customer_type' => 'COMPANY',
        ], $this->user);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('New Company Corp', $updated->company_name);
        $this->assertEquals('COMPANY', $updated->customer_type);
    }

    public function test_address_management_and_default_flag_exclusivity(): void
    {
        $customer = $this->service->createCustomer([
            'name' => 'Customer Addresses Test',
        ], $this->user);

        $addr1 = $this->service->addAddress($customer, [
            'address_type' => 'SHIPPING',
            'label' => 'Alamat 1',
            'address_line1' => 'Jalan Pertama',
            'is_default_shipping' => true,
        ]);

        $this->assertTrue($addr1->is_default_shipping);

        // Add second address also marked default shipping -> should unset addr1
        $addr2 = $this->service->addAddress($customer, [
            'address_type' => 'SHIPPING',
            'label' => 'Alamat 2',
            'address_line1' => 'Jalan Kedua',
            'is_default_shipping' => true,
        ]);

        $this->assertTrue($addr2->is_default_shipping);
        $this->assertFalse($addr1->fresh()->is_default_shipping);
    }

    public function test_delete_customer_soft_deletes_record(): void
    {
        $customer = $this->service->createCustomer([
            'name' => 'Customer To Delete',
        ], $this->user);

        $id = $customer->id;
        $this->service->deleteCustomer($customer, $this->user, 'Uji hapus pelanggan');

        $this->assertSoftDeleted('customers', ['id' => $id]);
        $this->assertFalse($customer->fresh()->is_active);
    }
}
