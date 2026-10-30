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
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Enums\PtkpStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Enums\SalaryComponentTypeEnum;
use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBpjsProfile;
use App\Modules\HR\Models\EmployeeCompensation;
use App\Modules\HR\Models\EmployeeTaxProfile;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\PayrollRecord;
use App\Modules\HR\Models\SalaryComponent;
use App\Modules\HR\Services\PayrollBatchService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollFinalizationAndPayslipTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected PayrollBatchService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->seed(StatutoryRuleVersionsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Payroll Finalize Tenant',
            'code' => 'FIN-TENANT',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Mega Kuningan',
            'code' => 'KNG-01',
            'status' => 'ACTIVE',
        ]);
        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'HR-09',
            'name' => 'HR Department',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'SPV-01',
            'name' => 'Store Supervisor',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-FIN-001',
            'full_name' => 'Rian Hidayat',
            'email' => 'rian.fin@corp.com',
            'phone_number' => '081299990099',
            'national_id_number' => '3171010101900099',
            'tax_id_number' => '091234567011099',
            'birth_date' => '1991-04-10',
            'hire_date' => '2022-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'ptkp_status' => PtkpStatusEnum::K_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'is_eligible_for_dtp' => false,
            'effective_from' => '2024-01-01',
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'jkk_risk_grade' => 1,
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
        ]);

        $baseSalary = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'salary_component_id' => $baseSalary->id,
            'amount' => 8000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->service = app(PayrollBatchService::class);
    }

    public function test_batch_lifecycle_and_immutability(): void
    {
        // 1. Buat Batch Baru
        $batch = $this->service->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $this->assertEquals(PayrollBatchStatusEnum::DRAFT, $batch->status);

        // 2. Eksekusi Perhitungan
        $batch = $this->service->executeCalculation($batch);
        $this->assertEquals(PayrollBatchStatusEnum::CALCULATED, $batch->status);
        $this->assertEquals(1, $batch->total_employees);
        $this->assertGreaterThan(0, $batch->total_net_amount);

        // Periksa Record Gaji Terbuat
        $record = PayrollRecord::where('payroll_batch_id', $batch->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('EMP-FIN-001', $record->employee_number_snapshot);
        $this->assertEquals(8000000.00, $record->basic_salary_amount);
        $this->assertNotEmpty($record->payslip_token);

        // Periksa Item Gaji Terbuat
        $this->assertGreaterThan(0, $record->items()->count());

        // 3. Akses Slip Gaji via Token
        $retrievedPayslip = $this->service->getPayslipByToken($record->payslip_token);
        $this->assertEquals($record->id, $retrievedPayslip->id);

        // 4. Finalisasi Batch dengan Approval
        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'action_code' => 'PAYROLL_EXECUTION',
            'action_type' => 'HUMAN_APPROVAL',
            'title' => 'Otorisasi Batch Payroll',
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $this->user->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'reason' => 'Otorisasi eksekusi batch gaji Oktober',
            'decided_at' => now(),
        ]);

        $finalizedBatch = $this->service->finalizeBatch((int) $batch->id, (int) $this->user->id, (int) $approval->id);
        $this->assertEquals(PayrollBatchStatusEnum::FINALIZED, $finalizedBatch->status);

        // 5. Uji Trigger Imutabilitas: UPDATE pada payroll_records harus ditolak MySQL
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PAYROLL-001: Cannot update payroll record once batch is FINALIZED or PAID.');

        $record->net_pay_amount = 9999999.00;
        $record->save();
    }
}
