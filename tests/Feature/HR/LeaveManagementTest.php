<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Approval;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\LeaveRequestStatusEnum;
use App\Modules\HR\Enums\LeaveTransactionTypeEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Services\LeaveService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected LeavePolicy $annualPolicy;

    protected LeavePolicy $maternityPolicy;

    protected LeaveService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Leave Test Corp',
            'code' => 'LTC-01',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Jakarta',
            'code' => 'JKT-01',
            'status' => 'ACTIVE',
        ]);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'HR-01',
            'name' => 'Human Resources',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'HR-STAFF',
            'name' => 'HR Staff',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-LV-001',
            'full_name' => 'Doni Saputra',
            'email' => 'doni.lv@corp.com',
            'phone_number' => '081299990002',
            'national_id_number' => '3171010101900002',
            'tax_id_number' => '091234567011002',
            'birth_date' => '1992-06-20',
            'hire_date' => '2022-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $this->annualPolicy = LeavePolicy::create([
            'tenant_id' => $this->tenant->id,
            'policy_code' => 'ANNUAL',
            'policy_name' => 'Cuti Tahunan',
            'is_paid' => true,
            'default_days_per_year' => 12.00,
            'is_gender_specific' => false,
            'requires_attachment' => false,
            'expiration_policy' => 'FORFEIT',
            'is_active' => true,
        ]);

        $this->maternityPolicy = LeavePolicy::create([
            'tenant_id' => $this->tenant->id,
            'policy_code' => 'MATERNITY',
            'policy_name' => 'Cuti Melahirkan',
            'is_paid' => true,
            'default_days_per_year' => 90.00,
            'is_gender_specific' => true,
            'applicable_gender' => 'FEMALE',
            'requires_attachment' => true,
            'expiration_policy' => 'FORFEIT',
            'is_active' => true,
        ]);

        $this->service = app(LeaveService::class);
    }

    public function test_accrue_annual_leave_creates_balance_and_append_only_transaction(): void
    {
        $balance = $this->service->accrueLeave(
            tenantId: $this->tenant->id,
            employeeId: $this->employee->id,
            policyId: $this->annualPolicy->id,
            year: 2027,
            days: 12.00,
            actorUserId: $this->user->id,
            reason: 'Hak Cuti Tahunan 2027'
        );

        $this->assertInstanceOf(LeaveBalance::class, $balance);
        $this->assertEquals(12.00, $balance->allocated_days);
        $this->assertEquals(12.00, $balance->remaining_days);
        $this->assertEquals(0.00, $balance->used_days);

        $this->assertDatabaseHas('leave_transactions', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'leave_policy_id' => $this->annualPolicy->id,
            'transaction_type' => LeaveTransactionTypeEnum::ACCRUAL->value,
            'days_delta' => 12.00,
        ]);

        $ledgerSum = $this->service->calculateLedgerBalance($this->tenant->id, $this->employee->id, $this->annualPolicy->id);
        $this->assertEquals(12.00, $ledgerSum);
    }

    public function test_submit_leave_request_checks_balance_and_requires_attachment(): void
    {
        // 1. Submit exceeding balance (0 days currently available)
        $this->expectException(ValidationException::class);

        $this->service->submitLeaveRequest(
            tenantId: $this->tenant->id,
            employeeId: $this->employee->id,
            policyId: $this->annualPolicy->id,
            startDate: Carbon::parse('2027-04-01'),
            endDate: Carbon::parse('2027-04-03'),
            totalDays: 3.00,
            reason: 'Liburan Keluarga',
            actorUserId: $this->user->id
        );
    }

    public function test_approve_leave_request_deducts_balance_and_records_usage(): void
    {
        // Berikan saldo 12 hari
        $this->service->accrueLeave(
            tenantId: $this->tenant->id,
            employeeId: $this->employee->id,
            policyId: $this->annualPolicy->id,
            year: 2027,
            days: 12.00,
            actorUserId: $this->user->id
        );

        // Ajukan 3 hari
        $request = $this->service->submitLeaveRequest(
            tenantId: $this->tenant->id,
            employeeId: $this->employee->id,
            policyId: $this->annualPolicy->id,
            startDate: Carbon::parse('2027-04-01'),
            endDate: Carbon::parse('2027-04-03'),
            totalDays: 3.00,
            reason: 'Liburan Keluarga',
            actorUserId: $this->user->id
        );

        $this->assertEquals(LeaveRequestStatusEnum::PENDING, $request->status);

        // Buat approval yang disetujui
        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'action_code' => 'LEAVE_REQUEST',
            'action_type' => 'HUMAN_APPROVAL',
            'title' => 'Permohonan Cuti Doni',
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $this->user->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'reason' => 'Disetujui manajer',
            'decided_at' => now(),
        ]);

        $approvedRequest = $this->service->approveLeaveRequest(
            tenantId: $this->tenant->id,
            requestId: $request->id,
            approvalId: $approval->id,
            approverUserId: $this->user->id
        );

        $this->assertEquals(LeaveRequestStatusEnum::APPROVED, $approvedRequest->status);

        // Periksa saldo
        $balance = LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_policy_id', $this->annualPolicy->id)
            ->where('year', 2027)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(3.00, $balance->used_days);
        $this->assertEquals(9.00, $balance->remaining_days);

        // Periksa buku besar
        $ledgerSum = $this->service->calculateLedgerBalance($this->tenant->id, $this->employee->id, $this->annualPolicy->id);
        $this->assertEquals(9.00, $ledgerSum);

        // Batalkan cuti -> Reversal
        $cancelled = $this->service->cancelLeaveRequest(
            tenantId: $this->tenant->id,
            requestId: $approvedRequest->id,
            actorUserId: $this->user->id,
            reason: 'Acara dibatalkan'
        );

        $this->assertEquals(LeaveRequestStatusEnum::CANCELLED, $cancelled->status);

        $balance->refresh();
        $this->assertEquals(0.00, $balance->used_days);
        $this->assertEquals(12.00, $balance->remaining_days);

        $ledgerSumAfterCancel = $this->service->calculateLedgerBalance($this->tenant->id, $this->employee->id, $this->annualPolicy->id);
        $this->assertEquals(12.00, $ledgerSumAfterCancel);
    }

    public function test_gender_specific_leave_rejection(): void
    {
        $this->expectException(ValidationException::class);

        // Employee adalah MALE, melamar cuti bersalin (FEMALE only)
        $this->service->submitLeaveRequest(
            tenantId: $this->tenant->id,
            employeeId: $this->employee->id,
            policyId: $this->maternityPolicy->id,
            startDate: Carbon::parse('2027-05-01'),
            endDate: Carbon::parse('2027-07-30'),
            totalDays: 90.00,
            reason: 'Melahirkan',
            actorUserId: $this->user->id,
            attachmentPath: 'medical/surat_hamil.pdf'
        );
    }
}
