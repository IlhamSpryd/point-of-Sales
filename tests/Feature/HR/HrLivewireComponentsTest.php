<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Livewire\HR\AttendanceMonitor;
use App\Livewire\HR\CommissionManager;
use App\Livewire\HR\EmployeeManager;
use App\Livewire\HR\LeaveManager;
use App\Livewire\HR\PayrollBatchManager;
use App\Livewire\HR\WorkforceScheduler;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\AttendanceSourceEnum;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\LeaveRequestStatusEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\ShiftTemplate;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class HrLivewireComponentsTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected Role $ownerRole;

    protected Role $kasirRole;

    protected User $ownerUser;

    protected User $kasirUser;

    protected Employee $employee;

    protected Department $dept;

    protected JobTitle $jobTitle;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'HR Livewire Tenant',
            'code' => 'HRLW-TEN',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store HR Livewire',
            'code' => 'STR-HRLW',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $this->ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['role_code' => 'ROL-OWN-HRLW', 'is_active' => true, 'permissions' => ['*']]
        );

        $this->kasirRole = Role::firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenant->id],
            ['role_code' => 'ROL-KAS-HRLW', 'is_active' => true, 'permissions' => ['orders.create']]
        );

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->kasirUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->kasirRole->id,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $this->dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'DEPT-LW',
            'name' => 'Livewire Ops',
        ]);

        $this->jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'title_code' => 'TITLE-LW',
            'name' => 'Operations Lead',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->ownerUser->id,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employee_number' => 'EMP-LW-001',
            'full_name' => 'Dewi Anggraini',
            'email' => 'dewi.hr@corp.com',
            'phone_number' => '081234567890',
            'national_id_number' => '3271010101900001',
            'birth_date' => '1992-05-15',
            'hire_date' => '2023-01-01',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
        ]);
    }

    public function test_employee_manager_renders_and_creates_employee(): void
    {
        $lw = Livewire::actingAs($this->ownerUser)->test(EmployeeManager::class);
        $lw->assertOk();
        $lw->assertSee('Master Karyawan', false);
        $lw->assertSee('Dewi Anggraini');
        $lw->set('employeeNumber', 'EMP-LW-NEW')
            ->set('fullName', 'Ahmad Fadillah')
            ->set('email', 'ahmad.f@corp.com')
            ->set('phoneNumber', '081288887777')
            ->set('nationalIdNumber', '3271010101900099')
            ->set('gender', 'MALE')
            ->set('maritalStatus', 'MARRIED')
            ->set('religion', 'ISLAM')
            ->set('employmentType', 'PERMANENT')
            ->set('departmentId', $this->dept->id)
            ->set('jobTitleId', $this->jobTitle->id)
            ->set('hireDate', '2026-01-01')
            ->call('saveEmployee')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'tenant_id' => $this->tenant->id,
            'employee_number' => 'EMP-LW-NEW',
            'full_name' => 'Ahmad Fadillah',
        ]);
    }

    public function test_employee_manager_toggles_active_status(): void
    {
        $this->assertEquals(EmploymentStatusEnum::ACTIVE, $this->employee->employment_status);

        $lw = Livewire::actingAs($this->ownerUser)->test(EmployeeManager::class);
        $lw->call('toggleActiveStatus', $this->employee->id);

        $this->employee->refresh();
        $this->assertEquals(EmploymentStatusEnum::TERMINATED, $this->employee->employment_status);
    }

    public function test_workforce_scheduler_renders_and_assigns_shift(): void
    {
        $shift = ShiftTemplate::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'SHIFT-PAGI-LW',
            'name' => 'Shift Pagi Livewire',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'duration_hours' => 8.0,
            'is_cross_midnight' => false,
            'is_active' => true,
        ]);

        $lw = Livewire::actingAs($this->ownerUser)->test(WorkforceScheduler::class);
        $lw->assertOk();
        $lw->assertSee('Jadwal', false);
        $lw->set('selectedStoreId', $this->store->id)
            ->set('assignEmployeeId', $this->employee->id)
            ->set('assignDate', '2026-11-02')
            ->set('assignShiftTemplateId', $shift->id)
            ->call('saveScheduleAssignment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('work_schedules', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'schedule_date' => '2026-11-02',
            'shift_template_id' => $shift->id,
        ]);
    }

    public function test_attendance_monitor_renders_and_saves_correction(): void
    {
        $rec = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $this->employee->id,
            'clock_in_at' => Carbon::parse('2026-11-01 07:15:00'),
            'clock_out_at' => Carbon::parse('2026-11-01 15:30:00'),
            'source' => AttendanceSourceEnum::POS_KIOSK,
            'status' => AttendanceStatusEnum::PRESENT,
        ]);

        $lw = Livewire::actingAs($this->ownerUser)->test(AttendanceMonitor::class);
        $lw->assertOk();
        $lw->assertSee('Pemantauan Absensi', false);
        $lw->set('editingRecordId', $rec->id)
            ->set('correctedClockIn', '2026-11-01T07:00')
            ->set('correctedClockOut', '2026-11-01T15:00')
            ->set('correctionReason', 'Fingerprint sensor error di kasir')
            ->call('saveCorrection')
            ->assertHasNoErrors();

        $rec->refresh();
        $this->assertStringContainsString('Fingerprint sensor error', $rec->notes ?? '');
    }

    public function test_leave_manager_renders_and_approves_request(): void
    {
        $policy = LeavePolicy::create([
            'tenant_id' => $this->tenant->id,
            'policy_code' => 'ANNUAL-LW',
            'policy_name' => 'Cuti Tahunan Livewire',
            'default_days_per_year' => 12,
            'is_paid' => true,
            'is_active' => true,
        ]);

        LeaveBalance::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'leave_policy_id' => $policy->id,
            'year' => 2026,
            'allocated_days' => 12,
            'used_days' => 0,
            'remaining_days' => 12,
            'expired_days' => 0,
        ]);

        $request = LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'leave_policy_id' => $policy->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-12',
            'total_days' => 3,
            'reason' => 'Keperluan keluarga penting',
            'status' => LeaveRequestStatusEnum::PENDING,
        ]);

        $lw = Livewire::actingAs($this->ownerUser)->test(LeaveManager::class);
        $lw->assertOk();
        $lw->assertSee('Manajemen Cuti', false);
        $lw->call('approve', $request->id);

        $request->refresh();
        $this->assertEquals(LeaveRequestStatusEnum::APPROVED, $request->status);
    }

    public function test_commission_manager_renders_successfully(): void
    {
        $lw = Livewire::actingAs($this->ownerUser)->test(CommissionManager::class);
        $lw->assertOk();
        $lw->assertSee('Rekapitulasi Komisi Penjualan Staf');
    }

    public function test_payroll_batch_manager_renders_and_creates_batch(): void
    {
        $lw = Livewire::actingAs($this->ownerUser)->test(PayrollBatchManager::class);
        $lw->assertOk();
        $lw->assertSee('Penggajian Karyawan (Payroll Engine)');
        $lw->set('periodStartDate', '2026-11-01')
            ->set('periodEndDate', '2026-11-30')
            ->set('cutoffDate', '2026-11-25')
            ->set('paymentDate', '2026-11-30')
            ->call('createBatch')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payroll_batches', [
            'tenant_id' => $this->tenant->id,
            'period_start_date' => '2026-11-01 00:00:00',
            'period_end_date' => '2026-11-30 00:00:00',
        ]);
    }

    public function test_hr_routes_require_owner_or_manager_role(): void
    {
        // Kasir role harus ditolak (403 Forbidden)
        $responseKasir = $this->actingAs($this->kasirUser)
            ->withSession(['current_store_id' => $this->store->id])
            ->get(route('hr.employees.index'));
        $responseKasir->assertStatus(403);

        // Owner role harus diterima (200 OK)
        $responseOwner = $this->actingAs($this->ownerUser)
            ->withSession(['current_store_id' => $this->store->id])
            ->get(route('hr.employees.index'));
        $responseOwner->assertStatus(200);
    }
}
