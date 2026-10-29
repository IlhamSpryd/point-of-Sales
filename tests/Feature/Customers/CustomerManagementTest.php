<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerGroupManager;
use App\Livewire\Customers\CustomerManager;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $owner;

    protected User $cashier;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Livewire Customer Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Livewire Store',
        ]);

        $ownerRole = Role::create([
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $cashierRole = Role::create([
            'name' => 'Kasir',
            'permissions' => ['pos.*'],
        ]);

        $this->owner = User::create([
            'name' => 'Owner User',
            'email' => 'owner.cust@test.com',
            'password' => bcrypt('password'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier.cust@test.com',
            'password' => bcrypt('password'),
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);

        $this->owner->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->cashier->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $context->setStoreId($this->store->id);
    }

    public function test_customer_manager_renders_and_lists_customers(): void
    {
        $this->actingAs($this->owner);

        Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'John Doe',
            'customer_type' => 'INDIVIDUAL',
        ]);

        Livewire::test(CustomerManager::class)
            ->assertStatus(200)
            ->assertSee('John Doe')
            ->assertSee('CUST-0001');
    }

    public function test_customer_manager_can_create_b2b_customer(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CustomerManager::class)
            ->call('create')
            ->set('name', 'Budi Corporate')
            ->set('customer_type', 'COMPANY')
            ->set('company_name', 'PT Solusi Bangsa')
            ->set('tax_id', '01.999.888.7-001.000')
            ->set('phone', '081299887766')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi Corporate',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Solusi Bangsa',
            'phone' => '+6281299887766',
        ]);
    }

    public function test_customer_group_manager_crud(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CustomerGroupManager::class)
            ->assertStatus(200)
            ->call('create')
            ->set('code', 'DIST-1')
            ->set('name', 'Distributor Utama')
            ->set('default_discount_rate', '12.50')
            ->set('is_b2b_default', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_groups', [
            'tenant_id' => $this->tenant->id,
            'code' => 'DIST-1',
            'name' => 'Distributor Utama',
            'is_b2b_default' => 1,
        ]);
    }

    public function test_unauthorized_user_cannot_manage_customers(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(CustomerManager::class)
            ->assertForbidden();
    }
}
