<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Livewire\HR\EmployeeManager;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Services\Context\TenantContext;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeUserUnifiedLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private Department $dept;

    private JobTitle $jobTitle;

    private Role $cashierRole;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Enterprise Lifecycle Tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sudirman',
            'code' => 'SDR-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $roleOwner = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'description' => 'Owner Role', 'is_admin' => true]
        );

        $this->cashierRole = Role::firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Kasir POS', 'description' => 'Kasir Role', 'is_admin' => false]
        );

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'name' => 'Owner Lifecycle',
            'email' => 'owner.lifecycle@apeiron.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);

        $this->dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS',
            'name' => 'Store Operations',
        ]);

        $this->jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'title_code' => 'KSR',
            'name' => 'Junior Cashier',
            'department_id' => $this->dept->id,
        ]);
    }

    public function test_user_model_has_employee_relationship(): void
    {
        $this->actingAs($this->ownerUser);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->ownerUser->id,
            'employee_number' => 'EMP-REL-001',
            'full_name' => $this->ownerUser->name,
            'email' => $this->ownerUser->email,
            'phone_number' => '081299998888',
            'gender' => \App\Modules\HR\Enums\GenderEnum::MALE,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'hire_date' => now()->toDateString(),
        ]);

        $this->assertTrue(method_exists($this->ownerUser, 'employee'));
        $this->assertNotNull($this->ownerUser->employee);
        $this->assertSame($employee->id, $this->ownerUser->employee->id);
    }

    public function test_can_provision_system_user_account_directly_from_employee_manager(): void
    {
        $this->actingAs($this->ownerUser);

        Livewire::test(EmployeeManager::class)
            ->call('openCreateModal')
            ->set('employeeNumber', 'EMP-ACC-001')
            ->set('fullName', 'Rian Kasir Baru')
            ->set('email', 'rian.kasir@apeiron.test')
            ->set('phoneNumber', '081234567891')
            ->set('nationalIdNumber', '3271234567890009')
            ->set('gender', 'MALE')
            ->set('maritalStatus', 'SINGLE')
            ->set('religion', 'ISLAM')
            ->set('employmentType', 'PERMANENT')
            ->set('departmentId', $this->dept->id)
            ->set('jobTitleId', $this->jobTitle->id)
            ->set('hireDate', '2026-03-01')
            ->set('createSystemAccount', true)
            ->set('systemRoleId', $this->cashierRole->id)
            ->set('systemPassword', 'SecretPass123!')
            ->set('cashierPin', '123456')
            ->call('saveEmployee')
            ->assertHasNoErrors()
            ->assertSet('isModalOpen', false);

        /** @var Employee|null $employee */
        $employee = Employee::where('employee_number', 'EMP-ACC-001')->first();
        $this->assertNotNull($employee);
        $this->assertNotNull($employee->user_id);

        /** @var User|null $user */
        $user = User::find($employee->user_id);
        $this->assertNotNull($user);
        $this->assertSame('Rian Kasir Baru', $user->name);
        $this->assertSame('rian.kasir@apeiron.test', $user->email);
        $this->assertSame($this->cashierRole->id, $user->role_id);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('SecretPass123!', $user->password_hash));
        $this->assertTrue(Hash::check('123456', $user->pin_hash));
    }

    public function test_terminating_employee_deactivates_linked_user_account(): void
    {
        $this->actingAs($this->ownerUser);

        $cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'name' => 'Kasir Akan Diberhentikan',
            'email' => 'terminated.kasir@apeiron.test',
            'password_hash' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $cashierUser->id,
            'employee_number' => 'EMP-TERM-002',
            'full_name' => $cashierUser->name,
            'email' => $cashierUser->email,
            'phone_number' => '081277778888',
            'gender' => \App\Modules\HR\Enums\GenderEnum::MALE,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-01',
        ]);

        // Toggle to TERMINATED
        Livewire::test(EmployeeManager::class)
            ->call('toggleActiveStatus', $employee->id);

        $employee->refresh();
        $cashierUser->refresh();

        $this->assertSame(EmploymentStatusEnum::TERMINATED, $employee->employment_status);
        $this->assertFalse((bool) $cashierUser->is_active);

        // Toggle back to ACTIVE
        Livewire::test(EmployeeManager::class)
            ->call('toggleActiveStatus', $employee->id);

        $employee->refresh();
        $cashierUser->refresh();

        $this->assertSame(EmploymentStatusEnum::ACTIVE, $employee->employment_status);
        $this->assertTrue((bool) $cashierUser->is_active);
    }

    public function test_user_service_store_links_or_creates_employee_profile(): void
    {
        $this->actingAs($this->ownerUser);

        $userService = app(UserService::class);

        $newUser = $userService->store([
            'name' => 'Staf Dari User Service',
            'email' => 'staf.service@apeiron.test',
            'password' => 'SecurePass123!',
            'role_id' => $this->cashierRole->id,
            'phone_number' => '081233445566',
            'is_active' => true,
        ]);

        $this->assertNotNull($newUser);

        $employee = Employee::where('user_id', $newUser->id)->first();
        $this->assertNotNull($employee);
        $this->assertSame('Staf Dari User Service', $employee->full_name);
        $this->assertSame('staf.service@apeiron.test', $employee->email);
    }
}
