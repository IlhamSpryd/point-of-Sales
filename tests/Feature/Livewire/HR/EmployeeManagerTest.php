<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\HR;

use App\Livewire\HR\EmployeeManager;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeManagerTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private Department $dept;

    private JobTitle $jobTitle;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Enterprise HR Tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama Jakarta',
            'code' => 'JKT-01',
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
            'name' => 'HR Director Apeiron',
            'email' => 'hr.director@apeiron.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);

        $this->dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'ENG',
            'name' => 'Engineering & Product',
        ]);

        $this->jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'title_code' => 'SE',
            'name' => 'Senior Software Engineer',
            'department_id' => $this->dept->id,
        ]);
    }

    public function test_employee_manager_screen_renders_successfully(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->get(route('hr.employees.index'));

        $response->assertOk();
        $response->assertSee('Employee Directory &amp; Workforce', false);
        $response->assertSee('New Employee');
        $response->assertSee('Total Employees');
        $response->assertSee('Active Employees');
        $response->assertSee('data-table');
    }

    public function test_can_create_new_employee_via_modal(): void
    {
        $this->actingAs($this->ownerUser);

        Livewire::test(EmployeeManager::class)
            ->call('openCreateModal')
            ->assertSet('isModalOpen', true)
            ->assertSet('modalMode', 'create')
            ->set('employeeNumber', 'EMP-TEST-001')
            ->set('fullName', 'Ahmad Pratama')
            ->set('email', 'ahmad@apeiron.test')
            ->set('phoneNumber', '081234567890')
            ->set('nationalIdNumber', '3271234567890001')
            ->set('gender', 'MALE')
            ->set('maritalStatus', 'SINGLE')
            ->set('religion', 'ISLAM')
            ->set('employmentType', 'PERMANENT')
            ->set('departmentId', $this->dept->id)
            ->set('jobTitleId', $this->jobTitle->id)
            ->set('hireDate', '2026-01-15')
            ->call('saveEmployee')
            ->assertHasNoErrors()
            ->assertSet('isModalOpen', false);

        $this->assertDatabaseHas('employees', [
            'tenant_id' => $this->tenant->id,
            'employee_number' => 'EMP-TEST-001',
            'full_name' => 'Ahmad Pratama',
            'email' => 'ahmad@apeiron.test',
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employment_status' => EmploymentStatusEnum::ACTIVE->value,
        ]);
    }

    public function test_can_edit_existing_employee_via_modal(): void
    {
        $this->actingAs($this->ownerUser);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employee_number' => 'EMP-EDIT-002',
            'full_name' => 'Siti Nurhaliza',
            'email' => 'siti@apeiron.test',
            'phone_number' => '081298765432',
            'national_id_number' => '3271987654320002',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::CONTRACT_PKWT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-02-01',
        ]);

        Livewire::test(EmployeeManager::class)
            ->call('openEditModal', $employee->id)
            ->assertSet('isModalOpen', true)
            ->assertSet('modalMode', 'edit')
            ->assertSet('editingEmployeeId', $employee->id)
            ->assertSet('fullName', 'Siti Nurhaliza')
            ->set('fullName', 'Siti Nurhaliza Putri')
            ->set('employmentType', 'PERMANENT')
            ->call('saveEmployee')
            ->assertHasNoErrors()
            ->assertSet('isModalOpen', false);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Siti Nurhaliza Putri',
            'employment_type' => EmploymentTypeEnum::PERMANENT->value,
        ]);
    }

    public function test_can_toggle_employee_active_status(): void
    {
        $this->actingAs($this->ownerUser);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employee_number' => 'EMP-TOGGLE-003',
            'full_name' => 'Bambang Supriyadi',
            'email' => 'bambang@apeiron.test',
            'phone_number' => '081211112222',
            'national_id_number' => '3271111122220003',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-01',
        ]);

        Livewire::test(EmployeeManager::class)
            ->call('toggleActiveStatus', $employee->id);

        $employee->refresh();
        $this->assertSame(EmploymentStatusEnum::TERMINATED, $employee->employment_status);

        Livewire::test(EmployeeManager::class)
            ->call('toggleActiveStatus', $employee->id);

        $employee->refresh();
        $this->assertSame(EmploymentStatusEnum::ACTIVE, $employee->employment_status);
    }

    public function test_can_filter_employees_by_search_and_status(): void
    {
        $this->actingAs($this->ownerUser);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employee_number' => 'EMP-SEARCH-A',
            'full_name' => 'Eko Prasetyo',
            'email' => 'eko.prasetyo@apeiron.test',
            'phone_number' => '081233334444',
            'national_id_number' => '3271333344440004',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-01',
        ]);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employee_number' => 'EMP-SEARCH-B',
            'full_name' => 'Diana Lestari',
            'email' => 'diana.lestari@apeiron.test',
            'phone_number' => '081255556666',
            'national_id_number' => '3271555566660005',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::CONTRACT_PKWT,
            'employment_status' => EmploymentStatusEnum::TERMINATED,
            'hire_date' => '2026-01-01',
        ]);

        Livewire::test(EmployeeManager::class)
            ->set('search', 'Diana')
            ->assertSee('Diana Lestari')
            ->assertDontSee('Eko Prasetyo');

        Livewire::test(EmployeeManager::class)
            ->set('statusFilter', 'ACTIVE')
            ->assertSee('Eko Prasetyo')
            ->assertDontSee('Diana Lestari');
    }
}
