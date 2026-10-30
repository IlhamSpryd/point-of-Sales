<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Approval;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\AttendanceSourceEnum;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\OvertimeDayTypeEnum;
use App\Modules\HR\Enums\OvertimeStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\OvertimeRecord;
use App\Modules\HR\Services\OvertimeCalculationService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OvertimeCalculationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected AttendanceRecord $attendanceRecord;

    protected OvertimeCalculationService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Overtime Corp',
            'code' => 'OT-CORP',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Semanggi',
            'code' => 'SMG-01',
            'status' => 'ACTIVE',
        ]);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-01',
            'name' => 'Operasional',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'BAR-01',
            'name' => 'Head Barista',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-OT-001',
            'full_name' => 'Fajar Nugraha',
            'email' => 'fajar.ot@corp.com',
            'phone_number' => '081299990001',
            'national_id_number' => '3171010101900001',
            'tax_id_number' => '091234567011000',
            'birth_date' => '1990-05-15',
            'hire_date' => '2023-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $this->attendanceRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'employee_id' => $this->employee->id,
            'work_date' => '2027-02-15',
            'source' => AttendanceSourceEnum::POS_KIOSK,
            'clock_in_at' => '2027-02-15 08:00:00',
            'clock_out_at' => '2027-02-15 17:00:00',
            'status' => AttendanceStatusEnum::PRESENT,
        ]);

        $this->service = app(OvertimeCalculationService::class);
    }

    public function test_payable_hours_workday_formula(): void
    {
        // Jam ke-1 = 1.5x
        $this->assertEquals(1.5, $this->service->calculatePayableHours(1.0, OvertimeDayTypeEnum::WORKDAY));

        // 2 jam = 1.5 + (1 * 2.0) = 3.5
        $this->assertEquals(3.5, $this->service->calculatePayableHours(2.0, OvertimeDayTypeEnum::WORKDAY));

        // 3 jam = 1.5 + (2 * 2.0) = 5.5
        $this->assertEquals(5.5, $this->service->calculatePayableHours(3.0, OvertimeDayTypeEnum::WORKDAY));

        // 4 jam = 1.5 + (3 * 2.0) = 7.5
        $this->assertEquals(7.5, $this->service->calculatePayableHours(4.0, OvertimeDayTypeEnum::WORKDAY));
    }

    public function test_payable_hours_weekly_rest_formula_6_days(): void
    {
        // 7 jam pertama = 7 * 2.0 = 14.0
        $this->assertEquals(14.0, $this->service->calculatePayableHours(7.0, OvertimeDayTypeEnum::WEEKLY_REST, 6));

        // 8 jam = 14.0 + 1 * 3.0 = 17.0
        $this->assertEquals(17.0, $this->service->calculatePayableHours(8.0, OvertimeDayTypeEnum::WEEKLY_REST, 6));

        // 9 jam = 17.0 + 1 * 4.0 = 21.0
        $this->assertEquals(21.0, $this->service->calculatePayableHours(9.0, OvertimeDayTypeEnum::WEEKLY_REST, 6));
    }

    public function test_payable_hours_weekly_rest_formula_5_days(): void
    {
        // 8 jam pertama = 8 * 2.0 = 16.0
        $this->assertEquals(16.0, $this->service->calculatePayableHours(8.0, OvertimeDayTypeEnum::WEEKLY_REST, 5));

        // 9 jam = 16.0 + 1 * 3.0 = 19.0
        $this->assertEquals(19.0, $this->service->calculatePayableHours(9.0, OvertimeDayTypeEnum::WEEKLY_REST, 5));

        // 10 jam = 19.0 + 1 * 4.0 = 23.0
        $this->assertEquals(23.0, $this->service->calculatePayableHours(10.0, OvertimeDayTypeEnum::WEEKLY_REST, 5));
    }

    public function test_hourly_rate_basis_formula_1_per_173(): void
    {
        // Upah sebulan Rp 5.190.000 / 173 = Rp 30.000,00
        $rate = $this->service->calculateHourlyRateBasis(5190000.0);
        $this->assertEquals(30000.00, $rate);

        $amount = $this->service->calculateOvertimeAmount(3.5, $rate);
        $this->assertEquals(105000.00, $amount);
    }

    public function test_create_overtime_record_with_approved_spkl(): void
    {
        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'action_code' => 'OVERTIME',
            'action_type' => 'HUMAN_APPROVAL',
            'title' => 'Lembur Event Promo',
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $this->user->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'reason' => 'SPKL disetujui untuk lembur 2 jam',
            'decided_at' => now(),
        ]);

        $record = $this->service->createOvertimeRecord(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $this->employee->id,
            attendanceRecordId: $this->attendanceRecord->id,
            approvalId: $approval->id,
            overtimeDate: Carbon::parse('2027-02-15'),
            startTime: Carbon::parse('2027-02-15 17:00:00'),
            endTime: Carbon::parse('2027-02-15 19:00:00'),
            dayType: OvertimeDayTypeEnum::WORKDAY,
            monthlyFixedWage: 5190000.0,
            workDaysPerWeek: 6
        );

        $this->assertInstanceOf(OvertimeRecord::class, $record);
        $this->assertEquals(120, $record->actual_duration_minutes);
        $this->assertEquals(3.5, $record->payable_hours);
        $this->assertEquals(30000.00, $record->hourly_rate_basis);
        $this->assertEquals(105000.00, $record->calculated_amount);
        $this->assertEquals(OvertimeStatusEnum::APPROVED, $record->status);
    }

    public function test_rejects_overtime_without_valid_approved_spkl(): void
    {
        $pendingApproval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'action_code' => 'OVERTIME',
            'action_type' => 'HUMAN_APPROVAL',
            'title' => 'Lembur Menunggu',
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $this->user->id,
            'status' => 'PENDING',
            'reason' => 'Masih menunggu verifikasi',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->createOvertimeRecord(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $this->employee->id,
            attendanceRecordId: $this->attendanceRecord->id,
            approvalId: $pendingApproval->id,
            overtimeDate: Carbon::parse('2027-02-15'),
            startTime: Carbon::parse('2027-02-15 17:00:00'),
            endTime: Carbon::parse('2027-02-15 19:00:00'),
            dayType: OvertimeDayTypeEnum::WORKDAY,
            monthlyFixedWage: 5190000.0
        );
    }

    public function test_rejects_workday_overtime_exceeding_4_hours_daily(): void
    {
        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'action_code' => 'OVERTIME',
            'action_type' => 'HUMAN_APPROVAL',
            'title' => 'Lembur 5 Jam',
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $this->user->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'reason' => 'SPKL disetujui',
            'decided_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        // 17:00 to 22:00 = 5 jam (melebihi batas PP 35/2021)
        $this->service->createOvertimeRecord(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            employeeId: $this->employee->id,
            attendanceRecordId: $this->attendanceRecord->id,
            approvalId: $approval->id,
            overtimeDate: Carbon::parse('2027-02-15'),
            startTime: Carbon::parse('2027-02-15 17:00:00'),
            endTime: Carbon::parse('2027-02-15 22:00:00'),
            dayType: OvertimeDayTypeEnum::WORKDAY,
            monthlyFixedWage: 5190000.0
        );
    }
}
