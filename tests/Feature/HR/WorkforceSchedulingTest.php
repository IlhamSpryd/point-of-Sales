<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\WorkScheduleStatusEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBranchAssignment;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\ShiftTemplate;
use App\Modules\HR\Services\WorkforceSchedulingService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class WorkforceSchedulingTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    private Store $store;

    private Department $department;

    private JobTitle $jobTitle;

    private ShiftTemplate $shiftPagi;

    private ShiftTemplate $shiftSiang;

    private WorkforceSchedulingService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Scheduling Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Pacific Place',
        ]);

        $this->department = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'D-OPS',
            'name' => 'Store Operations',
        ]);

        $this->jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'title_code' => 'J-BAR',
            'name' => 'Barista',
            'grade' => 2,
        ]);

        $this->shiftPagi = ShiftTemplate::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-PAGI',
            'name' => 'Shift Pagi 07:00-15:00',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'cross_midnight' => false,
            'unpaid_break_minutes' => 60,
            'grace_period_minutes' => 15,
            'is_active' => true,
        ]);

        $this->shiftSiang = ShiftTemplate::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-SIANG',
            'name' => 'Shift Siang 15:00-23:00',
            'start_time' => '15:00',
            'end_time' => '23:00',
            'cross_midnight' => false,
            'unpaid_break_minutes' => 60,
            'grace_period_minutes' => 15,
            'is_active' => true,
        ]);

        $this->service = app(WorkforceSchedulingService::class);
    }

    private function createEmployee(string $number, string $name, EmploymentStatusEnum $status = EmploymentStatusEnum::ACTIVE, bool $assignBranch = true): Employee
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'employee_number' => $number,
            'full_name' => $name,
            'phone_number' => '081234567890',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'department_id' => $this->department->id,
            'job_title_id' => $this->jobTitle->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => $status,
            'hire_date' => '2026-01-01',
        ]);

        if ($assignBranch) {
            EmployeeBranchAssignment::create([
                'tenant_id' => $this->tenant->id,
                'employee_id' => $employee->id,
                'store_id' => $this->store->id,
                'assignment_type' => AssignmentTypeEnum::PRIMARY,
                'valid_from' => '2026-01-01',
            ]);
        }

        return $employee;
    }

    public function test_schedule_shift_successfully_for_active_employee(): void
    {
        $employee = $this->createEmployee('EMP-001', 'Ahmad Barista');

        $schedule = $this->service->scheduleShift(
            employee: $employee,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-01',
            notes: 'Opening shift'
        );

        $this->assertEquals(WorkScheduleStatusEnum::SCHEDULED, $schedule->status);
        $this->assertEquals('2026-11-01', $schedule->schedule_date->toDateString());
        $this->assertEquals($this->shiftPagi->id, $schedule->shift_template_id);
    }

    public function test_scheduling_fails_if_employee_has_no_branch_assignment_in_store(): void
    {
        $employee = $this->createEmployee('EMP-NOBRANCH', 'Rudi Tanpa Cabang', EmploymentStatusEnum::ACTIVE, assignBranch: false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->scheduleShift(
            employee: $employee,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-01'
        );
    }

    public function test_scheduling_fails_for_inactive_employee(): void
    {
        $employee = $this->createEmployee('EMP-DRAFT', 'Calon Staf', EmploymentStatusEnum::DRAFT);

        $this->expectException(InvalidArgumentException::class);
        $this->service->scheduleShift(
            employee: $employee,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-01'
        );
    }

    public function test_duplicate_schedule_on_same_date_rejected(): void
    {
        $employee = $this->createEmployee('EMP-DUP', 'Hadi Barista');

        $this->service->scheduleShift(
            employee: $employee,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-05'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->service->scheduleShift(
            employee: $employee,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftSiang->id,
            scheduleDate: '2026-11-05'
        );
    }

    public function test_batch_schedule_creates_all_records_atomically(): void
    {
        $emp1 = $this->createEmployee('EMP-B1', 'Staff Satu');
        $emp2 = $this->createEmployee('EMP-B2', 'Staff Dua');

        $entries = [
            [
                'employee' => $emp1,
                'store_id' => $this->store->id,
                'shift_template_id' => $this->shiftPagi->id,
                'schedule_date' => '2026-11-10',
            ],
            [
                'employee' => $emp2,
                'store_id' => $this->store->id,
                'shift_template_id' => $this->shiftSiang->id,
                'schedule_date' => '2026-11-10',
            ],
            [
                'employee' => $emp1,
                'store_id' => $this->store->id,
                'shift_template_id' => $this->shiftSiang->id,
                'schedule_date' => '2026-11-11',
            ],
        ];

        $results = $this->service->batchSchedule($entries);

        $this->assertCount(3, $results);
        $this->assertDatabaseCount('work_schedules', 3);
    }

    public function test_shift_swap_workflow_between_two_employees(): void
    {
        $empA = $this->createEmployee('EMP-SWAPA', 'Karyawan A');
        $empB = $this->createEmployee('EMP-SWAPB', 'Karyawan B');

        // Emp A dijadwalkan Shift Pagi tanggal 2026-11-15
        $schedA = $this->service->scheduleShift(
            employee: $empA,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-15',
            notes: 'Jadwal asli A'
        );

        // Emp B dijadwalkan Shift Siang tanggal 2026-11-15
        $schedB = $this->service->scheduleShift(
            employee: $empB,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftSiang->id,
            scheduleDate: '2026-11-15',
            notes: 'Jadwal asli B'
        );

        $swapResult = $this->service->requestShiftSwap(
            scheduleA: $schedA,
            scheduleB: $schedB,
            reason: 'Emp A ada urusan keluarga mendesak pagi hari'
        );

        $newSchedA = $swapResult['schedule_a'];
        $newSchedB = $swapResult['schedule_b'];

        $this->assertEquals(WorkScheduleStatusEnum::SWAPPED, $newSchedA->status);
        $this->assertEquals(WorkScheduleStatusEnum::SWAPPED, $newSchedB->status);

        // Tanggal sama: template shift ditukar
        $this->assertEquals($this->shiftSiang->id, $newSchedA->shift_template_id);
        $this->assertEquals($this->shiftPagi->id, $newSchedB->shift_template_id);

        $this->assertEquals($schedB->id, $newSchedA->swapped_with_schedule_id);
        $this->assertEquals($schedA->id, $newSchedB->swapped_with_schedule_id);
    }

    public function test_shift_swap_workflow_on_different_dates(): void
    {
        $empA = $this->createEmployee('EMP-DIFF1', 'Karyawan 1');
        $empB = $this->createEmployee('EMP-DIFF2', 'Karyawan 2');

        $schedA = $this->service->scheduleShift(
            employee: $empA,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-18'
        );

        $schedB = $this->service->scheduleShift(
            employee: $empB,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-19'
        );

        $swapResult = $this->service->requestShiftSwap(
            scheduleA: $schedA,
            scheduleB: $schedB,
            reason: 'Tukar hari kerja'
        );

        $newSchedA = $swapResult['schedule_a'];
        $newSchedB = $swapResult['schedule_b'];

        // Tanggal berbeda: employee_id ditukar
        $this->assertEquals($empB->id, $newSchedA->employee_id);
        $this->assertEquals($empA->id, $newSchedB->employee_id);
    }

    public function test_cancel_schedule_marks_status_cancelled(): void
    {
        $emp = $this->createEmployee('EMP-CANCEL', 'Karyawan Cancel');

        $sched = $this->service->scheduleShift(
            employee: $emp,
            storeId: $this->store->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-20'
        );

        $cancelled = $this->service->cancelSchedule($sched, 'Karyawan izin sakit mendadak');

        $this->assertEquals(WorkScheduleStatusEnum::CANCELLED, $cancelled->status);
        $this->assertStringContainsString('Karyawan izin sakit mendadak', (string) $cancelled->notes);
    }

    public function test_roster_queries_for_employee_and_store(): void
    {
        $emp = $this->createEmployee('EMP-ROSTER', 'Karyawan Roster');

        $this->service->scheduleShift($emp, $this->store->id, $this->shiftPagi->id, '2026-11-01');
        $this->service->scheduleShift($emp, $this->store->id, $this->shiftPagi->id, '2026-11-02');
        $this->service->scheduleShift($emp, $this->store->id, $this->shiftSiang->id, '2026-11-03');

        $employeeRoster = $this->service->getEmployeeRoster($emp, '2026-11-01', '2026-11-02');
        $this->assertCount(2, $employeeRoster);

        $storeRoster = $this->service->getStoreDailyRoster($this->store->id, '2026-11-01');
        $this->assertCount(1, $storeRoster);
    }

    public function test_multi_tenant_isolation_on_work_schedules(): void
    {
        $tenantOther = Tenant::create([
            'name' => 'Tenant Other Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($tenantOther->id);
        $storeOther = Store::create([
            'tenant_id' => $tenantOther->id,
            'name' => 'Outlet Bandung',
        ]);
        $this->context->setTenantId($this->tenant->id);

        $emp = $this->createEmployee('EMP-ISO', 'Karyawan Alpha');

        $this->expectException(ModelNotFoundException::class);
        $this->service->scheduleShift(
            employee: $emp,
            storeId: $storeOther->id,
            shiftTemplateId: $this->shiftPagi->id,
            scheduleDate: '2026-11-01'
        );
    }
}
