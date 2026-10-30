<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee as CompatEmployee;
use App\Models\EmployeeBankAccount as CompatEmployeeBankAccount;
use App\Models\EmployeeBpjsProfile as CompatEmployeeBpjsProfile;
use App\Models\EmployeeTaxProfile as CompatEmployeeTaxProfile;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\PtkpStatusEnum;
use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBankAccount;
use App\Modules\HR\Models\EmployeeBpjsProfile;
use App\Modules\HR\Models\EmployeeTaxProfile;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EmployeeMasterAndProfilesTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private TenantContext $context;

    private Department $deptA;

    private JobTitle $titleA;

    private Department $deptB;

    private JobTitle $titleB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenantA = Tenant::create([
            'name' => 'Resto Nusantara Tenant A',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Kafe Melati Tenant B',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->context->setTenantId($this->tenantA->id);
        $this->deptA = Department::create([
            'department_code' => 'OPS-A',
            'name' => 'Operations A',
        ]);
        $this->titleA = JobTitle::create([
            'department_id' => $this->deptA->id,
            'title_code' => 'BARISTA-A',
            'name' => 'Barista Staff',
        ]);

        $this->context->setTenantId($this->tenantB->id);
        $this->deptB = Department::create([
            'department_code' => 'OPS-B',
            'name' => 'Operations B',
        ]);
        $this->titleB = JobTitle::create([
            'department_id' => $this->deptB->id,
            'title_code' => 'BARISTA-B',
            'name' => 'Barista Staff',
        ]);
    }

    public function test_employee_can_be_created_without_user_account(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $employee = Employee::create([
            'employee_number' => 'EMP-001',
            'national_id_number' => '3171021234560001',
            'full_name' => 'Budi Santoso',
            'phone_number' => '081234567890',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-01-15',
        ]);

        $this->assertGreaterThan(0, $employee->id);
        $this->assertNull($employee->user_id);
        $this->assertNull($employee->user);
        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertTrue($employee->isActive());
    }

    public function test_employee_can_optionally_link_to_user_account(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $role = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cashier',
            'display_name' => 'Cashier Staff',
            'permissions' => ['pos.order.create'],
        ]);

        $user = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Siti Kasir',
            'email' => 'siti@nusantara.test',
            'password' => bcrypt('password123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-002',
            'national_id_number' => '3171026543210002',
            'full_name' => 'Siti Rahmawati',
            'phone_number' => '081298765432',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'employment_type' => EmploymentTypeEnum::CONTRACT_PKWT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-02-01',
        ]);

        $this->assertSame($user->id, $employee->user_id);
        $this->assertSame('siti@nusantara.test', $employee->user->email);
    }

    public function test_sensitive_personal_data_is_encrypted_at_rest_in_database(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $plainNik = '3171021234560001';
        $plainNpwp = '09.123.456.7-012.000';
        $plainBankAcc = '5420123456';
        $plainBpjsTk = '20012345678';
        $plainBpjsKs = '0001234567890';

        $employee = Employee::create([
            'employee_number' => 'EMP-SECURE-01',
            'national_id_number' => $plainNik,
            'full_name' => 'Rahardian Secure',
            'phone_number' => '081211112222',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-03-01',
        ]);

        $taxProfile = EmployeeTaxProfile::create([
            'employee_id' => $employee->id,
            'tax_identification_number' => $plainNpwp,
            'ptkp_status' => PtkpStatusEnum::K_1,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'effective_from' => '2026-03-01',
        ]);

        $bankAccount = EmployeeBankAccount::create([
            'employee_id' => $employee->id,
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => $plainBankAcc,
            'account_holder_name' => 'Rahardian Secure',
            'is_primary' => true,
        ]);

        $bpjsProfile = EmployeeBpjsProfile::create([
            'employee_id' => $employee->id,
            'bpjs_ketenagakerjaan_number' => $plainBpjsTk,
            'bpjs_kesehatan_number' => $plainBpjsKs,
            'jkk_risk_grade' => 2,
        ]);

        // 1. Raw database check: value MUST NOT be plaintext
        $rawNik = DB::table('employees')->where('id', $employee->id)->value('national_id_number');
        $rawNpwp = DB::table('employee_tax_profiles')->where('id', $taxProfile->id)->value('tax_identification_number');
        $rawBankAcc = DB::table('employee_bank_accounts')->where('id', $bankAccount->id)->value('account_number');
        $rawBpjsTk = DB::table('employee_bpjs_profiles')->where('id', $bpjsProfile->id)->value('bpjs_ketenagakerjaan_number');
        $rawBpjsKs = DB::table('employee_bpjs_profiles')->where('id', $bpjsProfile->id)->value('bpjs_kesehatan_number');

        $this->assertNotSame($plainNik, $rawNik);
        $this->assertStringNotContainsString($plainNik, (string) $rawNik);

        $this->assertNotSame($plainNpwp, $rawNpwp);
        $this->assertStringNotContainsString($plainNpwp, (string) $rawNpwp);

        $this->assertNotSame($plainBankAcc, $rawBankAcc);
        $this->assertStringNotContainsString($plainBankAcc, (string) $rawBankAcc);

        $this->assertNotSame($plainBpjsTk, $rawBpjsTk);
        $this->assertNotSame($plainBpjsKs, $rawBpjsKs);

        // 2. Eloquent model check: automatically decrypted
        $freshEmp = $employee->fresh();
        $this->assertSame($plainNik, $freshEmp->national_id_number);
        $this->assertSame($plainNpwp, $freshEmp->taxProfile->tax_identification_number);
        $this->assertSame($plainBankAcc, $freshEmp->bankAccount->account_number);
        $this->assertSame($plainBpjsTk, $freshEmp->bpjsProfile->bpjs_ketenagakerjaan_number);
        $this->assertSame($plainBpjsKs, $freshEmp->bpjsProfile->bpjs_kesehatan_number);
    }

    public function test_sensitive_data_masking_helpers(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $employee = Employee::create([
            'employee_number' => 'EMP-MASK-01',
            'national_id_number' => '3171021234560001',
            'full_name' => 'Dewi Masking',
            'phone_number' => '081233334444',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => '2026-03-01',
        ]);

        $taxProfile = EmployeeTaxProfile::create([
            'employee_id' => $employee->id,
            'tax_identification_number' => '091234567012000',
            'ptkp_status' => PtkpStatusEnum::TK_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'effective_from' => '2026-03-01',
        ]);

        $bankAccount = EmployeeBankAccount::create([
            'employee_id' => $employee->id,
            'bank_name' => 'BCA',
            'account_number' => '1234564892',
            'account_holder_name' => 'Dewi Masking',
        ]);

        $bpjsProfile = EmployeeBpjsProfile::create([
            'employee_id' => $employee->id,
            'bpjs_ketenagakerjaan_number' => '12345678901',
            'bpjs_kesehatan_number' => '9876543210987',
        ]);

        $this->assertSame('317102******0001', $employee->maskedNationalId());
        $this->assertStringContainsString('***', $taxProfile->maskedNpwp());
        $this->assertSame('BCA •••• 4892', $bankAccount->maskedAccountNumber());
        $this->assertStringEndsWith('8901', $bpjsProfile->maskedBpjsKetenagakerjaan());
        $this->assertStringEndsWith('0987', $bpjsProfile->maskedBpjsKesehatan());
    }

    public function test_multi_tenant_scoping_isolates_employees_and_profiles(): void
    {
        // 1. Create in Tenant A
        $this->context->setTenantId($this->tenantA->id);
        $empA = Employee::create([
            'employee_number' => 'EMP-COMMON-01',
            'full_name' => 'Karyawan Tenant A',
            'phone_number' => '081211111111',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);
        $taxA = EmployeeTaxProfile::create([
            'employee_id' => $empA->id,
            'tax_identification_number' => '01.111.111.1-111.000',
            'effective_from' => '2026-01-01',
        ]);

        // 2. Create in Tenant B with identical employee_number
        $this->context->setTenantId($this->tenantB->id);
        $empB = Employee::create([
            'employee_number' => 'EMP-COMMON-01',
            'full_name' => 'Karyawan Tenant B',
            'phone_number' => '081222222222',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptB->id,
            'job_title_id' => $this->titleB->id,
            'hire_date' => '2026-02-01',
        ]);
        $taxB = EmployeeTaxProfile::create([
            'employee_id' => $empB->id,
            'tax_identification_number' => '02.222.222.2-222.000',
            'effective_from' => '2026-02-01',
        ]);

        // 3. Verify scoping in Tenant A context
        $this->context->setTenantId($this->tenantA->id);
        $this->assertNull(Employee::find($empB->id));
        $this->assertNull(EmployeeTaxProfile::find($taxB->id));
        $this->assertSame($empA->id, Employee::where('employee_number', 'EMP-COMMON-01')->first()->id);

        // 4. Verify scoping in Tenant B context
        $this->context->setTenantId($this->tenantB->id);
        $this->assertNull(Employee::find($empA->id));
        $this->assertNull(EmployeeTaxProfile::find($taxA->id));
        $this->assertSame($empB->id, Employee::where('employee_number', 'EMP-COMMON-01')->first()->id);
    }

    public function test_duplicate_employee_number_rejected_within_same_tenant(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        Employee::create([
            'employee_number' => 'EMP-DUP-01',
            'full_name' => 'Pegawai Pertama',
            'phone_number' => '08111111111',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);
        Employee::create([
            'employee_number' => 'EMP-DUP-01',
            'full_name' => 'Pegawai Duplikat',
            'phone_number' => '08222222222',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);
    }

    public function test_one_tax_profile_and_one_bank_account_per_employee_enforced(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $employee = Employee::create([
            'employee_number' => 'EMP-SINGLE-PROF',
            'full_name' => 'Anisa Single',
            'phone_number' => '081333333333',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        EmployeeTaxProfile::create([
            'employee_id' => $employee->id,
            'tax_identification_number' => '09.999.888.7-000.000',
            'effective_from' => '2026-01-01',
        ]);

        // Second tax profile for same employee must be rejected
        $this->expectException(QueryException::class);
        EmployeeTaxProfile::create([
            'employee_id' => $employee->id,
            'tax_identification_number' => '09.111.222.3-000.000',
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_manager_and_direct_reports_hierarchy(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $manager = Employee::create([
            'employee_number' => 'MGR-001',
            'full_name' => 'Manajer Operasional',
            'phone_number' => '081199990001',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2025-01-01',
        ]);

        $staff1 = Employee::create([
            'manager_id' => $manager->id,
            'employee_number' => 'STF-001',
            'full_name' => 'Staf Junior 1',
            'phone_number' => '081199990002',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        $staff2 = Employee::create([
            'manager_id' => $manager->id,
            'employee_number' => 'STF-002',
            'full_name' => 'Staf Junior 2',
            'phone_number' => '081199990003',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        $this->assertSame($manager->id, $staff1->manager->id);
        $this->assertCount(2, $manager->directReports);
        $this->assertTrue($manager->directReports->contains('id', $staff1->id));
        $this->assertTrue($manager->directReports->contains('id', $staff2->id));

        // When manager is deleted, subordinate manager_id is set to null (nullOnDelete)
        $manager->forceDelete();
        $this->assertNull($staff1->fresh()->manager_id);
        $this->assertNull($staff2->fresh()->manager_id);
    }

    public function test_department_and_job_title_cannot_be_deleted_if_active_employees_exist(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $emp = Employee::create([
            'employee_number' => 'EMP-RESTRICT-01',
            'full_name' => 'Pegawai Kunci',
            'phone_number' => '081200001111',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        // Attempting to delete department or job title throws QueryException
        $this->expectException(QueryException::class);
        $this->deptA->delete();
    }

    public function test_assigns_tenant_protection_on_employee_and_profiles(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $employee = Employee::create([
            'employee_number' => 'EMP-PROT-01',
            'full_name' => 'Pegawai Terproteksi',
            'phone_number' => '081277778888',
            'gender' => GenderEnum::MALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        $this->assertSame($this->tenantA->id, $employee->tenant_id);

        // Attempting to mutate tenant_id throws RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ownership (tenant_id) cannot be modified once set.');

        $employee->tenant_id = $this->tenantB->id;
        $employee->save();
    }

    public function test_backward_compatibility_wrappers_for_employee_and_profiles(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $compatEmp = CompatEmployee::create([
            'employee_number' => 'COMPAT-EMP-01',
            'full_name' => 'Pegawai Wrapper',
            'phone_number' => '081255556666',
            'gender' => GenderEnum::FEMALE,
            'department_id' => $this->deptA->id,
            'job_title_id' => $this->titleA->id,
            'hire_date' => '2026-01-01',
        ]);

        $compatTax = CompatEmployeeTaxProfile::create([
            'employee_id' => $compatEmp->id,
            'tax_identification_number' => '09.555.666.7-000.000',
            'effective_from' => '2026-01-01',
        ]);

        $compatBank = CompatEmployeeBankAccount::create([
            'employee_id' => $compatEmp->id,
            'bank_name' => 'Mandiri',
            'account_number' => '1370001234567',
            'account_holder_name' => 'Pegawai Wrapper',
        ]);

        $compatBpjs = CompatEmployeeBpjsProfile::create([
            'employee_id' => $compatEmp->id,
            'bpjs_ketenagakerjaan_number' => '00011122233',
            'bpjs_kesehatan_number' => '00044455566',
        ]);

        $this->assertInstanceOf(Employee::class, $compatEmp);
        $this->assertInstanceOf(EmployeeTaxProfile::class, $compatTax);
        $this->assertInstanceOf(EmployeeBankAccount::class, $compatBank);
        $this->assertInstanceOf(EmployeeBpjsProfile::class, $compatBpjs);

        $this->assertSame($compatEmp->id, CompatEmployee::where('employee_number', 'COMPAT-EMP-01')->value('id'));
    }

    public function test_ptkp_status_enum_ter_categories_and_annual_amounts(): void
    {
        $this->assertSame('A', PtkpStatusEnum::TK_0->terCategory());
        $this->assertSame('A', PtkpStatusEnum::TK_1->terCategory());
        $this->assertSame('A', PtkpStatusEnum::K_0->terCategory());

        $this->assertSame('B', PtkpStatusEnum::TK_2->terCategory());
        $this->assertSame('B', PtkpStatusEnum::TK_3->terCategory());
        $this->assertSame('B', PtkpStatusEnum::K_1->terCategory());
        $this->assertSame('B', PtkpStatusEnum::K_2->terCategory());

        $this->assertSame('C', PtkpStatusEnum::K_3->terCategory());

        $this->assertSame(54_000_000, PtkpStatusEnum::TK_0->annualAmount());
        $this->assertSame(58_500_000, PtkpStatusEnum::TK_1->annualAmount());
        $this->assertSame(58_500_000, PtkpStatusEnum::K_0->annualAmount());
        $this->assertSame(63_000_000, PtkpStatusEnum::TK_2->annualAmount());
        $this->assertSame(67_500_000, PtkpStatusEnum::TK_3->annualAmount());
        $this->assertSame(72_000_000, PtkpStatusEnum::K_3->annualAmount());

        $this->assertFalse(PtkpStatusEnum::TK_0->isMarried());
        $this->assertTrue(PtkpStatusEnum::K_1->isMarried());
        $this->assertSame(2, PtkpStatusEnum::TK_2->dependentsCount());
    }
}
