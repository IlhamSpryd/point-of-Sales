<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Services\EmployeeLifecycleService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class EmployeeLifecycleAndBranchAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    private Store $storeGrandIndo;

    private Store $storeSenayanCity;

    private Department $deptFoh;

    private Department $deptBoh;

    private JobTitle $jobBarista;

    private JobTitle $jobStoreManager;

    private EmployeeLifecycleService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Multi-Branch Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenant->id);

        $this->storeGrandIndo = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Grand Indonesia',
            'address' => 'Jl. M.H. Thamrin No. 1, Jakarta Pusat',
        ]);

        $this->storeSenayanCity = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Senayan City',
            'address' => 'Jl. Asia Afrika No. 19, Jakarta Selatan',
        ]);

        $this->deptFoh = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'D-FOH',
            'name' => 'Front of House',
        ]);

        $this->deptBoh = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'D-BOH',
            'name' => 'Back of House',
        ]);

        $this->jobBarista = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->deptFoh->id,
            'title_code' => 'J-BAR',
            'name' => 'Barista',
            'grade' => 2,
        ]);

        $this->jobStoreManager = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->deptBoh->id,
            'title_code' => 'J-SM',
            'name' => 'Store Manager',
            'grade' => 6,
        ]);

        $this->service = app(EmployeeLifecycleService::class);
    }

    private function createEmployee(string $number = 'EMP-B01', string $name = 'Budi Staff', EmploymentStatusEnum $status = EmploymentStatusEnum::ACTIVE): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'employee_number' => $number,
            'full_name' => $name,
            'phone_number' => '081234567890',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'department_id' => $this->deptFoh->id,
            'job_title_id' => $this->jobBarista->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => $status,
            'hire_date' => '2026-01-01',
        ]);
    }

    public function test_primary_branch_assignment_closes_previous_active_primary_branch(): void
    {
        $employee = $this->createEmployee();

        // 1. Assign Grand Indonesia sebagai PRIMARY mulai 2026-01-01
        $assign1 = $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeGrandIndo->id,
            type: AssignmentTypeEnum::PRIMARY,
            validFrom: '2026-01-01',
            notes: 'Penempatan awal'
        );

        $this->assertEquals(AssignmentTypeEnum::PRIMARY, $assign1->assignment_type);
        $this->assertNull($assign1->valid_to);

        // 2. Assign Senayan City sebagai PRIMARY baru mulai 2026-06-01
        $assign2 = $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeSenayanCity->id,
            type: AssignmentTypeEnum::PRIMARY,
            validFrom: '2026-06-01',
            notes: 'Mutasi cabang utama'
        );

        // Cek penugasan pertama otomatis ditutup pada 2026-05-31
        $assign1->refresh();
        $this->assertEquals('2026-05-31', $assign1->valid_to?->toDateString());
        $this->assertNull($assign2->valid_to);

        // Cek getEffectivePrimaryBranch
        $branchInMarch = $this->service->getEffectivePrimaryBranch($employee, '2026-03-15');
        $this->assertNotNull($branchInMarch);
        $this->assertEquals($this->storeGrandIndo->id, $branchInMarch->store_id);

        $branchInJuly = $this->service->getEffectivePrimaryBranch($employee, '2026-07-01');
        $this->assertNotNull($branchInJuly);
        $this->assertEquals($this->storeSenayanCity->id, $branchInJuly->store_id);
    }

    public function test_secondary_and_temporary_branch_assignments(): void
    {
        $employee = $this->createEmployee();

        $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeGrandIndo->id,
            type: AssignmentTypeEnum::PRIMARY,
            validFrom: '2026-01-01'
        );

        $secondary = $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeSenayanCity->id,
            type: AssignmentTypeEnum::SECONDARY,
            validFrom: '2026-02-01',
            validTo: '2026-04-30',
            notes: 'Bantuan shift akhir pekan'
        );

        $this->assertEquals(AssignmentTypeEnum::SECONDARY, $secondary->assignment_type);
        $this->assertEquals('2026-04-30', $secondary->valid_to?->toDateString());

        // PRIMARY tetap tidak berubah dan tetap aktif
        $activePrimary = $this->service->getEffectivePrimaryBranch($employee, '2026-03-01');
        $this->assertEquals($this->storeGrandIndo->id, $activePrimary?->store_id);

        $this->assertCount(2, $employee->branchAssignments);
    }

    public function test_invalid_valid_to_before_valid_from_throws_exception(): void
    {
        $employee = $this->createEmployee();

        $this->expectException(InvalidArgumentException::class);
        $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeGrandIndo->id,
            type: AssignmentTypeEnum::SECONDARY,
            validFrom: '2026-05-01',
            validTo: '2026-04-30'
        );
    }

    public function test_syncs_user_stores_when_user_id_is_attached_to_employee(): void
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier Role',
            'permissions' => ['pos.order.create'],
        ]);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi User',
            'email' => 'budi.user@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $employee = $this->createEmployee('EMP-USR', 'Budi User');
        $employee->update(['user_id' => $user->id]);

        $this->service->assignBranch(
            employee: $employee,
            storeId: $this->storeGrandIndo->id,
            type: AssignmentTypeEnum::PRIMARY,
            validFrom: '2026-01-01'
        );

        $this->assertDatabaseHas('user_stores', [
            'user_id' => $user->id,
            'store_id' => $this->storeGrandIndo->id,
        ]);
    }

    public function test_employment_status_transition_and_history_audit(): void
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'HR Manager',
            'permissions' => ['hr.employee.manage'],
        ]);

        $actorUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'HR Admin Susan',
            'email' => 'susan@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $employee = $this->createEmployee('EMP-DRAFT', 'Calon Staf', EmploymentStatusEnum::DRAFT);

        // 1. DRAFT -> ON_PROBATION
        $h1 = $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ON_PROBATION,
            effectiveDate: '2026-02-01',
            reason: 'Tanda tangan PKWTT masa percobaan 3 bulan',
            referenceNumber: 'SK/2026/02/001',
            actorUserId: $actorUser->id
        );

        $employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::ON_PROBATION, $employee->employment_status);
        $this->assertEquals(EmploymentStatusEnum::DRAFT, $h1->from_status);
        $this->assertEquals(EmploymentStatusEnum::ON_PROBATION, $h1->to_status);
        $this->assertEquals($actorUser->id, $h1->actor_user_id);
        $this->assertEquals('SK/2026/02/001', $h1->reference_number);

        // 2. ON_PROBATION -> ACTIVE
        $h2 = $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ACTIVE,
            effectiveDate: '2026-05-01',
            reason: 'Lolos masa percobaan, pengangkatan karyawan tetap',
            referenceNumber: 'SK/2026/05/012',
            actorUserId: $actorUser->id
        );

        $employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::ACTIVE, $employee->employment_status);
        $this->assertEquals(EmploymentStatusEnum::ON_PROBATION, $h2->from_status);

        // 3. ACTIVE -> SUSPENDED
        $h3 = $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::SUSPENDED,
            effectiveDate: '2026-08-01',
            reason: 'Investigasi selisih kas drawer',
            referenceNumber: 'SP/2026/08/005',
            actorUserId: $actorUser->id
        );

        $employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::SUSPENDED, $employee->employment_status);

        // 4. SUSPENDED -> ACTIVE (Kembali aktif setelah investigasi klir)
        $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ACTIVE,
            effectiveDate: '2026-08-15',
            reason: 'Hasil investigasi tidak terbukti, pemulihan nama baik',
            actorUserId: $actorUser->id
        );

        // 5. ACTIVE -> RESIGNED
        $h5 = $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::RESIGNED,
            effectiveDate: '2026-09-30',
            reason: 'Mendapat tawaran studi lanjut',
            referenceNumber: 'RES/2026/09/001',
            actorUserId: $actorUser->id
        );

        $employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::RESIGNED, $employee->employment_status);
        $this->assertEquals('2026-09-30', $employee->termination_date?->toDateString());
        $this->assertEquals('Mendapat tawaran studi lanjut', $employee->termination_reason);

        // 6. RESIGNED -> ARCHIVED
        $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ARCHIVED,
            effectiveDate: '2026-10-31',
            reason: 'Exit clearance dan serah terima aset selesai',
            actorUserId: $actorUser->id
        );

        $employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::ARCHIVED, $employee->employment_status);

        $this->assertCount(6, $employee->employmentHistories);
    }

    public function test_invalid_status_transitions_rejected(): void
    {
        $employee = $this->createEmployee('EMP-TERM', 'Staf Selesai', EmploymentStatusEnum::TERMINATED);

        // Dari TERMINATED tidak boleh langsung kembali ke ACTIVE
        $this->expectException(InvalidArgumentException::class);
        $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ACTIVE,
            effectiveDate: '2026-11-01'
        );
    }

    public function test_archived_employee_status_is_permanently_immutable(): void
    {
        $employee = $this->createEmployee('EMP-ARC', 'Staf Arsip', EmploymentStatusEnum::ARCHIVED);

        $this->expectException(InvalidArgumentException::class);
        $this->service->transitionStatus(
            employee: $employee,
            toStatus: EmploymentStatusEnum::ACTIVE,
            effectiveDate: '2026-11-01'
        );
    }

    public function test_promotion_or_department_transfer_records_history_and_updates_employee(): void
    {
        $employee = $this->createEmployee('EMP-PROMO', 'Dedi Barista');

        $this->assertEquals($this->deptFoh->id, $employee->department_id);
        $this->assertEquals($this->jobBarista->id, $employee->job_title_id);

        $history = $this->service->promoteOrTransfer(
            employee: $employee,
            newDepartmentId: $this->deptBoh->id,
            newJobTitleId: $this->jobStoreManager->id,
            effectiveDate: '2026-07-01',
            reason: 'Promosi jabatan menjadi Store Manager BOH',
            referenceNumber: 'PROM/2026/07/003'
        );

        $employee->refresh();
        $this->assertEquals($this->deptBoh->id, $employee->department_id);
        $this->assertEquals($this->jobStoreManager->id, $employee->job_title_id);

        $this->assertEquals($this->deptFoh->id, $history->from_department_id);
        $this->assertEquals($this->deptBoh->id, $history->to_department_id);
        $this->assertEquals($this->jobBarista->id, $history->from_job_title_id);
        $this->assertEquals($this->jobStoreManager->id, $history->to_job_title_id);
    }

    public function test_multi_tenant_isolation_on_branch_assignments(): void
    {
        $tenantOther = Tenant::create([
            'name' => 'Tenant Beta Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($tenantOther->id);
        $storeOther = Store::create([
            'tenant_id' => $tenantOther->id,
            'name' => 'Outlet Beta Bandung',
        ]);
        $this->context->setTenantId($this->tenant->id);

        $employee = $this->createEmployee('EMP-TEN', 'Karyawan Alpha');

        // Tidak boleh bisa menugaskan cabang milik tenant lain
        $this->expectException(ModelNotFoundException::class);
        $this->service->assignBranch(
            employee: $employee,
            storeId: $storeOther->id,
            type: AssignmentTypeEnum::PRIMARY,
            validFrom: '2026-01-01'
        );
    }
}
