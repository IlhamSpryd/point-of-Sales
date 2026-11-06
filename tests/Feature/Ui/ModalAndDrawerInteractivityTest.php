<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Livewire\HR\EmployeeManager;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ModalAndDrawerInteractivityTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Interactivity Test Tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Interactivity Test Store',
            'code' => 'INT-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $roleOwner = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'description' => 'Owner Role', 'is_admin' => true]
        );

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'name' => 'Owner Interactivity',
            'email' => 'owner.interactivity@test.local',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);
    }

    public function test_user_management_screen_has_functional_add_user_drawer_binding(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->get(route('users.index'));

        $response->assertOk();
        // Verify Add User button is present with click trigger
        $response->assertSee('Add User');
        $response->assertSee('createUserDrawer = true', false);

        // Verify Drawer component is rendered with x-modelable for Alpine reactivity
        $response->assertSee('x-modelable="open"', false);
        $response->assertSee('x-model="createUserDrawer"', false);
    }

    public function test_employee_directory_screen_has_functional_add_employee_modal_binding(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->get(route('hr.employees.index'));

        $response->assertOk();
        // Verify Add New Employee button is present with wire:click
        $response->assertSee('Add New Employee');
        $response->assertSee('wire:click="openCreateModal"', false);

        // Verify Modal component uses live entangle and x-modelable
        $response->assertSee('x-modelable="modalOpen"', false);
        $response->assertSee("entangle('isModalOpen'", false);
    }

    public function test_livewire_employee_manager_open_create_modal_sets_state_and_renders_form(): void
    {
        $this->actingAs($this->ownerUser);

        Livewire::test(EmployeeManager::class)
            ->assertSet('isModalOpen', false)
            ->call('openCreateModal')
            ->assertSet('isModalOpen', true)
            ->assertSet('modalMode', 'create')
            ->assertSee('Add New Employee')
            ->assertSee('employeeNumber')
            ->assertSee('fullName')
            ->assertSee('saveEmployee');
    }
}
