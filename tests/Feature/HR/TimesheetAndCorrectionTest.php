<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Approval;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\DataTransferObjects\AttendancePunchDto;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBranchAssignment;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\ShiftTemplate;
use App\Modules\HR\Models\WorkSchedule;
use App\Modules\HR\Services\AttendanceCorrectionService;
use App\Modules\HR\Services\AttendanceService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class TimesheetAndCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    private Store $store;

    private Department $dept;

    private JobTitle $jobTitle;

    private ShiftTemplate $shiftPagi;

    private AttendanceService $attendanceService;

    private AttendanceCorrectionService $correctionService;

    private User $staffUser;

    private User $managerUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Timesheet Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Pondok Indah',
        ]);

        $this->dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'D-OPS',
            'name' => 'Store Operations',
        ]);

        $this->jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->dept->id,
            'title_code' => 'J-BAR',
            'name' => 'Barista',
            'grade' => 2,
        ]);

        $this->shiftPagi = ShiftTemplate::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_code' => 'S-0715',
            'name' => 'Shift Pagi 07:00-15:00',
            'start_time' => '07:00',
            'end_time' => '15:00',
            'cross_midnight' => false,
            'unpaid_break_minutes' => 60,
            'grace_period_minutes' => 15,
            'is_active' => true,
        ]);

        $roleStaff = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Role',
            'permissions' => ['pos.order.create'],
        ]);

        $roleManager = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager Role',
            'permissions' => ['hr.attendance.correct'],
        ]);

        $this->staffUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Doni',
            'email' => 'doni@test.local',
            'password' => bcrypt('password'),
            'role_id' => $roleStaff->id,
            'is_active' => true,
        ]);

        $this->managerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager Hendra',
            'email' => 'hendra@test.local',
            'password' => bcrypt('password'),
            'role_id' => $roleManager->id,
            'is_active' => true,
        ]);

        $this->attendanceService = app(AttendanceService::class);
        $this->correctionService = app(AttendanceCorrectionService::class);
    }

    private function createEmployee(string $number = 'EMP-CORR1'): Employee
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'employee_number' => $number,
            'full_name' => 'Doni Barista',
            'phone_number' => '081234567890',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-01',
            'user_id' => $this->staffUser->id,
        ]);

        EmployeeBranchAssignment::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'store_id' => $this->store->id,
            'assignment_type' => AssignmentTypeEnum::PRIMARY,
            'valid_from' => '2026-01-01',
        ]);

        return $employee;
    }

    public function test_request_and_approve_attendance_correction_workflow(): void
    {
        $employee = $this->createEmployee();

        $schedule = WorkSchedule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'shift_template_id' => $this->shiftPagi->id,
            'schedule_date' => now()->toDateString(),
        ]);

        // Karyawan lupa clock in tepat waktu, mesin mencatat jam 08:30 (terlambat 90 menit)
        $punchIn = now()->setTime(8, 30, 0);
        $dtoIn = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id
        );
        $record = $this->attendanceService->clockIn($dtoIn, serverNow: $punchIn);
        $this->assertEquals(AttendanceStatusEnum::LATE, $record->status);
        $this->assertEquals(90, $record->late_minutes);

        // Pulang jam 15:00
        $punchOut = now()->setTime(15, 0, 0);
        $this->attendanceService->clockOut($dtoIn, serverNow: $punchOut);

        // Doni mengajukan koreksi: sebenarnya datang jam 07:00 tapi mesin fingerprint hang
        $newIn = now()->setTime(7, 0, 0);
        $approval = $this->correctionService->requestCorrection(
            record: $record,
            newClockIn: $newIn,
            newClockOut: $punchOut,
            reason: 'Mesin fingerprint cabang error saat booting',
            requesterUserId: $this->staffUser->id
        );

        $this->assertEquals('PENDING', $approval->status);
        $this->assertEquals('ATTENDANCE_CORRECTION', $approval->action_code);

        // Manager menyetujui koreksi
        $corrected = $this->correctionService->approveCorrection(
            approval: $approval,
            approverUserId: $this->managerUser->id,
            approverNotes: 'Telah dikonfirmasi lewat CCTV toko'
        );

        $this->assertEquals(AttendanceStatusEnum::CORRECTED, $corrected->status);
        $this->assertEquals(0, $corrected->late_minutes); // Jam 07:00 tidak terlambat
        $this->assertEquals($approval->id, $corrected->approval_id);
        $this->assertEquals($this->managerUser->id, $corrected->corrected_by_user_id);
        $this->assertEquals(480, $corrected->net_work_minutes); // 07:00-15:00 = 8 jam = 480 menit

        $approval->refresh();
        $this->assertEquals(Approval::STATUS_APPROVED, $approval->status);
        $this->assertNotNull($approval->consumed_at);
    }

    public function test_anti_self_approval_rejects_approving_own_correction(): void
    {
        $employee = $this->createEmployee();

        $record = $this->attendanceService->clockIn(
            new AttendancePunchDto(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                employeeId: $employee->id
            ),
            serverNow: now()->setTime(8, 0, 0)
        );

        $approval = $this->correctionService->requestCorrection(
            record: $record,
            newClockIn: now()->setTime(7, 0, 0),
            newClockOut: null,
            reason: 'Salah jam',
            requesterUserId: $this->staffUser->id
        );

        // Doni mencoba menyetujui pengajuannya sendiri
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anti-Self Approval');

        $this->correctionService->approveCorrection(
            approval: $approval,
            approverUserId: $this->staffUser->id
        );
    }

    public function test_reject_attendance_correction(): void
    {
        $employee = $this->createEmployee();

        $record = $this->attendanceService->clockIn(
            new AttendancePunchDto(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                employeeId: $employee->id
            ),
            serverNow: now()->setTime(8, 30, 0)
        );

        $approval = $this->correctionService->requestCorrection(
            record: $record,
            newClockIn: now()->setTime(7, 0, 0),
            newClockOut: null,
            reason: 'Alasan mengada-ada',
            requesterUserId: $this->staffUser->id
        );

        $rejected = $this->correctionService->rejectCorrection(
            approval: $approval,
            approverUserId: $this->managerUser->id,
            rejectionReason: 'CCTV menunjukkan karyawan memang baru tiba jam 08:30'
        );

        $this->assertEquals(Approval::STATUS_REJECTED, $rejected->status);

        $record->refresh();
        $this->assertNotEquals(AttendanceStatusEnum::CORRECTED, $record->status);
        $this->assertNull($record->approval_id);
    }

    public function test_locked_attendance_record_cannot_be_corrected(): void
    {
        $employee = $this->createEmployee();

        $record = $this->attendanceService->clockIn(
            new AttendancePunchDto(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                employeeId: $employee->id
            ),
            serverNow: now()->setTime(7, 0, 0)
        );

        // Kunci record (misal sudah masuk batch payroll final)
        $record->update(['is_locked' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terkunci');

        $this->correctionService->requestCorrection(
            record: $record,
            newClockIn: now()->setTime(7, 0, 0),
            newClockOut: null,
            reason: 'Koreksi terlambat',
            requesterUserId: $this->staffUser->id
        );
    }

    public function test_correction_window_expired_rejected(): void
    {
        $employee = $this->createEmployee();

        // Absensi 10 hari yang lalu
        $pastDate = now()->subDays(10)->setTime(7, 0, 0);

        $record = $this->attendanceService->clockIn(
            new AttendancePunchDto(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                employeeId: $employee->id
            ),
            serverNow: $pastDate
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kadaluwarsa');

        $this->correctionService->requestCorrection(
            record: $record,
            newClockIn: $pastDate,
            newClockOut: null,
            reason: 'Koreksi kadaluwarsa',
            requesterUserId: $this->staffUser->id,
            maxWindowDays: 3
        );
    }

    public function test_timesheet_summary_calculation(): void
    {
        $employee = $this->createEmployee();

        // Hari 1: Hadir tepat waktu (07:00-15:00 = 480 menit, 0 break)
        $r1 = $this->attendanceService->clockIn(
            new AttendancePunchDto(tenantId: $this->tenant->id, storeId: $this->store->id, employeeId: $employee->id),
            serverNow: Carbon::parse('2026-11-01 07:00:00')
        );
        $this->attendanceService->clockOut(
            new AttendancePunchDto(tenantId: $this->tenant->id, storeId: $this->store->id, employeeId: $employee->id),
            serverNow: Carbon::parse('2026-11-01 15:00:00')
        );

        // Hari 2: Terlambat 30 menit (07:30-15:00 = 450 menit)
        $r2 = $this->attendanceService->clockIn(
            new AttendancePunchDto(tenantId: $this->tenant->id, storeId: $this->store->id, employeeId: $employee->id),
            serverNow: Carbon::parse('2026-11-02 07:30:00')
        );
        $this->attendanceService->clockOut(
            new AttendancePunchDto(tenantId: $this->tenant->id, storeId: $this->store->id, employeeId: $employee->id),
            serverNow: Carbon::parse('2026-11-02 15:00:00')
        );

        $summary = $this->correctionService->getTimesheetSummary($employee, '2026-11-01', '2026-11-02');

        $this->assertEquals(2, $summary['total_present_days']);
        $this->assertEquals(930, $summary['total_work_minutes']); // 480 + 450 = 930 menit = 15.5 jam
        $this->assertEquals(15.5, $summary['total_work_hours']);
        $this->assertEquals(0, $summary['total_absent_days']);
    }
}
