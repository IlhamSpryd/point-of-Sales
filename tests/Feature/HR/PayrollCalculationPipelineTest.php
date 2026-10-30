<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
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
use App\Modules\HR\Models\SalaryComponent;
use App\Modules\HR\Services\PayrollCalculationPipeline;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollCalculationPipelineTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected PayrollCalculationPipeline $pipeline;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->seed(StatutoryRuleVersionsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Payroll Master Tenant',
            'code' => 'PAY-TENANT',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Thamrin',
            'code' => 'THM-01',
            'status' => 'ACTIVE',
        ]);
        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->pipeline = app(PayrollCalculationPipeline::class);
    }

    /**
     * KASUS UJI EMAS 1: Karyawan Tetap Status K/0 (Gaji Rp15.000.000,00 - Masa Pajak Reguler)
     *
     * Dokumen Acuan: implementationPlanModul9.md Bab 33.1.1
     * Gaji Pokok: Rp12.000.000, Tunjangan Jabatan: Rp3.000.000
     * BPJS KS ER: Rp480.000, JKK ER: Rp36.000, JKM ER: Rp45.000
     * Bruto Pajak: Rp15.561.000 -> TER A 6% = Rp933.660,00
     * Potongan BPJS EE: KS (Rp120.000) + JHT (Rp300.000) + JP (Rp110.863) = Rp530.863,00
     * Total Potongan: Rp1.464.523,00
     * Net Take-Home Pay: Rp13.535.477,00 (Presisi Selisih Rp0,00!)
     */
    public function test_golden_case_1_permanent_employee_k0_15_million(): void
    {
        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'FIN-01',
            'name' => 'Finance',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'MGR-01',
            'name' => 'Finance Manager',
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-GOLDEN-01',
            'full_name' => 'Ahmad Fauzi',
            'email' => 'ahmad.golden@corp.com',
            'phone_number' => '081299990010',
            'national_id_number' => '3171010101900010',
            'tax_id_number' => '091234567011010',
            'birth_date' => '1988-03-25',
            'hire_date' => '2020-01-01', // Lama bekerja > 1 tahun, full month
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'ptkp_status' => PtkpStatusEnum::K_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'is_eligible_for_dtp' => false,
            'effective_from' => '2024-01-01',
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'jkk_risk_grade' => 1, // class_1 = 0.24%
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
        ]);

        $baseComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        $fixedComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'FIXED_ALLOWANCE',
            'component_name' => 'Tunjangan Jabatan',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'salary_component_id' => $baseComp->id,
            'amount' => 12000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'salary_component_id' => $fixedComp->id,
            'amount' => 3000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Eksekusi Pipeline untuk Masa Pajak Oktober 2026
        $periodStart = Carbon::parse('2026-10-01');
        $periodEnd = Carbon::parse('2026-10-31');

        $result = $this->pipeline->calculateEmployeePayroll($employee, $periodStart, $periodEnd);

        $this->assertNotNull($result);

        // 1. Gross Earnings = 15.000.000,00
        $this->assertEquals(15000000.00, $result->grossEarningsAmount);

        // 2. BPJS Kesehatan Employer (4% plafon 12jt) = 480.000,00
        $this->assertEquals(480000.00, $result->bpjsKsEmployer);

        // 3. BPJS TK JKK Employer (0.24% of 15jt) = 36.000,00
        $this->assertEquals(36000.00, $result->bpjsTkJkkEmployer);

        // 4. BPJS TK JKM Employer (0.30% of 15jt) = 45.000,00
        $this->assertEquals(45000.00, $result->bpjsTkJkmEmployer);

        // 5. Bruto Pajak = 15.561.000,00
        $this->assertEquals(15561000.00, $result->taxGrossAmount);

        // 6. Pajak PPh 21 TER A (6.00%) = 933.660,00
        $this->assertEquals(933660.00, $result->taxPph21Calculated);
        $this->assertEquals(933660.00, $result->taxPph21Deducted);

        // 7. Potongan BPJS Karyawan:
        // - BPJS KS EE (1% plafon 12jt) = 120.000,00
        $this->assertEquals(120000.00, $result->bpjsKsEmployee);
        // - BPJS TK JHT EE (2% of 15jt) = 300.000,00
        $this->assertEquals(300000.00, $result->bpjsTkJhtEmployee);
        // - BPJS TK JP EE (1% plafon 11.086.300) = 110.863,00
        $this->assertEquals(110863.00, $result->bpjsTkJpEmployee);
        // - Total BPJS EE = 530.863,00
        $this->assertEquals(530863.00, $result->bpjsEmployeeTotal);

        // 8. Total Potongan = 933.660 + 530.863 = 1.464.523,00
        $this->assertEquals(1464523.00, $result->totalDeductionsAmount);

        // 9. Net Take Home Pay = 15.000.000 - 1.464.523 = 13.535.477,00
        $this->assertEquals(13535477.00, $result->netPayAmount);
        $this->assertSame(0.0, abs($result->netPayAmount - 13535477.00));
    }

    /**
     * KASUS UJI EMAS 2: Karyawan F&B Memperoleh Insentif PPh 21 DTP PMK 105/2025 (Gaji Rp7.500.000,00 - TK/0)
     *
     * Dokumen Acuan: implementationPlanModul9.md Bab 33.1.2
     * Gaji Pokok: Rp6.000.000, Tunjangan Makan & Transport: Rp1.500.000
     * BPJS KS ER: Rp300.000, JKK ER: Rp18.000, JKM ER: Rp22.500
     * Bruto Pajak: Rp7.840.500 -> TER A 1.50% = Rp117.608,00
     * Fasilitas DTP: Pajak ditanggung pemerintah (tax_pph21_dtp = 117.608, tax_deducted = 0)
     * Potongan BPJS EE: KS (75.000) + JHT (150.000) + JP (75.000) = Rp300.000,00
     * Net Take-Home Pay: Rp7.200.000,00 (Pajak PPh 21 tidak memotong gaji!)
     */
    public function test_golden_case_2_fnb_employee_with_dtp_incentive(): void
    {
        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-02',
            'name' => 'Store Operations',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'BAR-02',
            'name' => 'Barista',
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-GOLDEN-02',
            'full_name' => 'Dewi Lestari',
            'email' => 'dewi.golden@corp.com',
            'phone_number' => '081299990020',
            'national_id_number' => '3171010101900020',
            'tax_id_number' => '091234567011020',
            'birth_date' => '1998-11-12',
            'hire_date' => '2023-05-01',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'ptkp_status' => PtkpStatusEnum::TK_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'is_eligible_for_dtp' => true, // Memenuhi PMK 105/2025 DTP
            'effective_from' => '2025-01-01',
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'jkk_risk_grade' => 1, // 0.24%
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
        ]);

        $baseComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        $varComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'VARIABLE_ALLOWANCE',
            'component_name' => 'Tunjangan Makan & Transport',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => false,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'salary_component_id' => $baseComp->id,
            'amount' => 6000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'salary_component_id' => $varComp->id,
            'amount' => 1500000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Eksekusi Pipeline (Dasar upah BPJS F&B mencakup upah teratur Rp 7.500.000)
        $periodStart = Carbon::parse('2026-10-01');
        $periodEnd = Carbon::parse('2026-10-31');

        $result = $this->pipeline->calculateEmployeePayroll($employee, $periodStart, $periodEnd, [
            // Pada kasus uji 2, dasar iuran BPJS adalah Rp 7.500.000
            'fixed_allowance' => 1500000.00,
            'variable_allowance' => 0.00,
        ]);

        $this->assertNotNull($result);

        // 1. Gross Earnings = 7.500.000,00
        $this->assertEquals(7500000.00, $result->grossEarningsAmount);

        // 2. BPJS ER: KS (4% of 7.5jt) = 300.000, JKK (0.24%) = 18.000, JKM (0.30%) = 22.500
        $this->assertEquals(300000.00, $result->bpjsKsEmployer);
        $this->assertEquals(18000.00, $result->bpjsTkJkkEmployer);
        $this->assertEquals(22500.00, $result->bpjsTkJkmEmployer);

        // 3. Bruto Pajak = 7.500.000 + 300.000 + 18.000 + 22.500 = 7.840.500,00
        $this->assertEquals(7840500.00, $result->taxGrossAmount);

        // 4. TER A (1.50%) = 117.608,00
        $this->assertEquals(117608.00, $result->taxPph21Calculated);

        // 5. Fasilitas DTP:
        $this->assertEquals(117608.00, $result->taxPph21DtpAmount);
        $this->assertEquals(0.00, $result->taxPph21Deducted);

        // 6. Potongan BPJS EE: KS (75.000) + JHT (150.000) + JP (75.000) = 300.000,00
        $this->assertEquals(75000.00, $result->bpjsKsEmployee);
        $this->assertEquals(150000.00, $result->bpjsTkJhtEmployee);
        $this->assertEquals(75000.00, $result->bpjsTkJpEmployee);
        $this->assertEquals(300000.00, $result->bpjsEmployeeTotal);

        // 7. Total Potongan = 300.000,00 (Pajak PPh 21 DTP 0 potongan!)
        $this->assertEquals(300000.00, $result->totalDeductionsAmount);

        // 8. Net Take Home Pay = 7.500.000 - 300.000 = 7.200.000,00
        $this->assertEquals(7200000.00, $result->netPayAmount);
        $this->assertSame(0.0, abs($result->netPayAmount - 7200000.00));
    }

    public function test_prorated_salary_for_mid_month_join(): void
    {
        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-03',
            'name' => 'Store Operations 3',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'BAR-03',
            'name' => 'Barista',
        ]);

        // Karyawan mulai kerja tanggal 16 Oktober (16 hari dari 31 hari di bulan Oktober)
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-PRORATA-01',
            'full_name' => 'Budi Prorata',
            'email' => 'budi.pro@corp.com',
            'phone_number' => '081299990030',
            'national_id_number' => '3171010101900030',
            'birth_date' => '1995-01-01',
            'hire_date' => '2026-10-16',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $baseComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'salary_component_id' => $baseComp->id,
            'amount' => 6200000.00,
            'effective_date' => '2026-10-01',
            'is_active' => true,
        ]);

        $periodStart = Carbon::parse('2026-10-01');
        $periodEnd = Carbon::parse('2026-10-31');

        $result = $this->pipeline->calculateEmployeePayroll($employee, $periodStart, $periodEnd);

        $this->assertNotNull($result);
        // 16 hari kerja / 31 hari di Oktober * 6.200.000 = 3.200.000
        $expectedProrata = round(6200000.00 * (16 / 31), 2);
        $this->assertEquals($expectedProrata, $result->baseSalaryAmount);
    }

    public function test_terminated_employee_is_skipped(): void
    {
        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-04',
            'name' => 'Store Operations 4',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'BAR-04',
            'name' => 'Barista',
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-TERM-01',
            'full_name' => 'Eko Terminated',
            'email' => 'eko.term@corp.com',
            'phone_number' => '081299990040',
            'national_id_number' => '3171010101900040',
            'birth_date' => '1990-01-01',
            'hire_date' => '2023-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::TERMINATED,
            'is_active' => false,
        ]);

        $periodStart = Carbon::parse('2026-10-01');
        $periodEnd = Carbon::parse('2026-10-31');

        $result = $this->pipeline->calculateEmployeePayroll($employee, $periodStart, $periodEnd);

        $this->assertNull($result);
    }
}
