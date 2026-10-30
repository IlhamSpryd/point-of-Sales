<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\DataTransferObjects\AttendancePunchDto;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\AttendanceSourceEnum;
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
use App\Modules\HR\Services\AttendanceService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    private Store $store;

    private Department $dept;

    private JobTitle $jobTitle;

    private ShiftTemplate $shiftPagi;

    private AttendanceService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Attendance Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Plaza Senayan',
        ]);

        $this->dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'D-OPS',
            'name' => 'Operations',
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

        $this->service = app(AttendanceService::class);
    }

    private function createEmployee(string $number = 'EMP-ATT1', string $name = 'Budi Hadir'): Employee
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'employee_number' => $number,
            'full_name' => $name,
            'phone_number' => '081234567890',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'department_id' => $this->dept->id,
            'job_title_id' => $this->jobTitle->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-01',
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

    public function test_clock_in_on_time_marks_present_and_zero_late_minutes(): void
    {
        $employee = $this->createEmployee();

        // Buat jadwal kerja 07:00-15:00 pada 2026-11-01
        $schedule = WorkSchedule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'shift_template_id' => $this->shiftPagi->id,
            'schedule_date' => '2026-11-01',
        ]);

        // Masuk jam 06:55 (5 menit sebelum mulai)
        $punchTime = Carbon::parse('2026-11-01 06:55:00');

        $dto = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id,
            source: AttendanceSourceEnum::POS_KIOSK,
            notes: 'Clock in terminal kasir 1'
        );

        $record = $this->service->clockIn($dto, serverNow: $punchTime);

        $this->assertEquals(AttendanceStatusEnum::PRESENT, $record->status);
        $this->assertEquals(0, $record->late_minutes);
        $this->assertEquals($schedule->id, $record->work_schedule_id);
        $this->assertEquals('2026-11-01 06:55:00', $record->clock_in_at->toDateTimeString());
        $this->assertNull($record->clock_out_at);
    }

    public function test_clock_in_late_past_grace_period_calculates_late_minutes(): void
    {
        $employee = $this->createEmployee();

        WorkSchedule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'shift_template_id' => $this->shiftPagi->id,
            'schedule_date' => '2026-11-02',
        ]);

        // Shift mulai 07:00, toleransi s/d 07:15. Datang jam 07:35 (terlambat 35 menit)
        $punchTime = Carbon::parse('2026-11-02 07:35:00');

        $dto = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id,
            source: AttendanceSourceEnum::BRANCH_TABLET
        );

        $record = $this->service->clockIn($dto, serverNow: $punchTime);

        $this->assertEquals(AttendanceStatusEnum::LATE, $record->status);
        $this->assertEquals(35, $record->late_minutes);
    }

    public function test_debounce_rejects_duplicate_clock_in_within_5_minutes(): void
    {
        $employee = $this->createEmployee();

        $t1 = Carbon::parse('2026-11-03 07:00:00');
        $dto = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id
        );

        $this->service->clockIn($dto, serverNow: $t1);

        // Punch lagi setelah 2 menit (07:02) -> harus ditolak oleh debounce
        $t2 = Carbon::parse('2026-11-03 07:02:00');
        $this->expectException(InvalidArgumentException::class);
        $this->service->clockIn($dto, serverNow: $t2);
    }

    public function test_clock_out_calculates_work_duration_and_early_departure(): void
    {
        $employee = $this->createEmployee();

        WorkSchedule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'shift_template_id' => $this->shiftPagi->id,
            'schedule_date' => '2026-11-04',
        ]);

        $inTime = Carbon::parse('2026-11-04 07:00:00');
        $dtoIn = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id
        );
        $record = $this->service->clockIn($dtoIn, serverNow: $inTime);

        // Istirahat jam 12:00 s/d 13:00 (60 menit)
        $this->service->startBreak($record->id, serverNow: Carbon::parse('2026-11-04 12:00:00'));
        $this->service->endBreak($record->id, serverNow: Carbon::parse('2026-11-04 13:00:00'));

        // Pulang lebih awal jam 14:30 (jadwal harusnya 15:00 -> early departure 30 menit)
        $outTime = Carbon::parse('2026-11-04 14:30:00');
        $dtoOut = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id,
            notes: 'Izin pulang awal 30 menit'
        );

        $completed = $this->service->clockOut($dtoOut, serverNow: $outTime);

        $this->assertEquals(AttendanceStatusEnum::EARLY_LEAVE, $completed->status);
        $this->assertEquals(30, $completed->early_departure_minutes);
        $this->assertEquals(60, $completed->total_break_minutes);
        // Durasi kotor = 07:00 s/d 14:30 = 7.5 jam = 450 menit. Netto = 450 - 60 = 390 menit
        $this->assertEquals(390, $completed->net_work_minutes);
    }

    public function test_clock_out_automatically_closes_open_break(): void
    {
        $employee = $this->createEmployee();

        $inTime = Carbon::parse('2026-11-05 07:00:00');
        $dto = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $employee->id
        );
        $record = $this->service->clockIn($dto, serverNow: $inTime);

        // Mulai istirahat jam 12:00 tapi lupa endBreak
        $this->service->startBreak($record->id, serverNow: Carbon::parse('2026-11-05 12:00:00'));

        // Langsung clock-out jam 15:00
        $outTime = Carbon::parse('2026-11-05 15:00:00');
        $completed = $this->service->clockOut($dto, serverNow: $outTime);

        $this->assertEquals(180, $completed->total_break_minutes); // 12:00 s/d 15:00 = 180 menit
        $this->assertEquals(300, $completed->net_work_minutes); // 480 - 180 = 300 menit
    }

    public function test_reconcile_daily_attendance_marks_unpunched_schedule_as_absent(): void
    {
        $employee = $this->createEmployee();

        // Jadwal kemarin yang tidak di-punch in sama sekali
        $schedule = WorkSchedule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'shift_template_id' => $this->shiftPagi->id,
            'schedule_date' => '2026-10-30',
        ]);

        $absentCount = $this->service->reconcileDailyAttendance($this->tenant->id, $this->store->id, '2026-10-30');

        $this->assertEquals(1, $absentCount);

        $this->assertDatabaseHas('attendance_records', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'status' => AttendanceStatusEnum::ABSENT->value,
        ]);
    }

    public function test_multi_tenant_isolation_on_attendance(): void
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
            'name' => 'Outlet Bekasi',
        ]);
        $this->context->setTenantId($this->tenant->id);

        $employee = $this->createEmployee('EMP-ISO', 'Karyawan Alpha');

        $dto = new AttendancePunchDto(
            tenantId: $this->tenant->id,
            storeId: $storeOther->id, // Store milik tenant lain
            employeeId: $employee->id
        );

        $this->expectException(ModelNotFoundException::class);
        $this->service->clockIn($dto, serverNow: Carbon::parse('2026-11-01 07:00:00'));
    }
}
