<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Workforce\Enums\EmploymentStatusEnum;
use App\Modules\Workforce\Enums\EmploymentTypeEnum;
use App\Modules\Workforce\Enums\GenderEnum;
use App\Modules\Workforce\Enums\MaritalStatusEnum;
use App\Modules\Workforce\Enums\PayrollBatchStatusEnum;
use App\Modules\Workforce\Models\Department;
use App\Modules\Workforce\Models\Employee;
use App\Modules\Workforce\Models\JobTitle;
use App\Modules\Workforce\Models\PayrollBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MultiCompanySchemaIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        $this->tenantA = Tenant::create([
            'name' => 'Corporate Group Alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Corporate Group Beta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'company_code' => 'PT-ALPHA-01',
            'company_name' => 'PT Alpha Retail Nusantara',
            'legal_name' => 'PT Alpha Retail Nusantara TBK',
            'is_default' => true,
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenantB->id);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'company_code' => 'PT-BETA-01',
            'company_name' => 'PT Beta Global Niaga',
            'legal_name' => 'PT Beta Global Niaga TBK',
            'is_default' => true,
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);
    }

    public function test_models_have_company_relationship_and_fillable_company_id(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $store = Store::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Branch Megastore',
            'code' => 'BR-MEGA-01',
        ]);

        $this->assertEquals($this->companyA->id, $store->company_id);
        $this->assertNotNull($store->company);
        $this->assertSame('PT Alpha Retail Nusantara', $store->company->company_name);
    }

    public function test_composite_foreign_key_blocks_cross_tenant_company_hijacking(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // Attempt to create a store under Tenant A referencing Company B (which belongs to Tenant B)
        $this->expectException(QueryException::class);

        Store::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyB->id, // CROSS-TENANT VIOLATION!
            'name' => 'Malicious Hijack Store',
            'code' => 'HIJACK-01',
        ]);
    }

    public function test_legal_and_financial_models_persist_company_id(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // 1. Employee
        $dept = Department::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Finance Department',
            'department_code' => 'FIN-01',
        ]);
        $title = JobTitle::create([
            'tenant_id' => $this->tenantA->id,
            'department_id' => $dept->id,
            'name' => 'Staff Accounting',
            'title_code' => 'ACC-01',
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'employee_number' => 'EMP-'.uniqid(),
            'full_name' => 'Siti Nurhaliza',
            'phone_number' => '081234567890',
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'hire_date' => now(),
        ]);

        $this->assertEquals($this->companyA->id, $employee->company_id);
        $this->assertSame($this->companyA->id, $employee->company->id);

        $userA = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'HR Manager Alpha',
            'email' => 'hr.alpha.'.uniqid().'@example.com',
            'password' => bcrypt('Secret123!'),
        ]);

        // 2. Payroll Batch
        $batch = PayrollBatch::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'batch_number' => 'PAY-'.uniqid(),
            'period_start_date' => now()->startOfMonth(),
            'period_end_date' => now()->endOfMonth(),
            'cutoff_date' => now(),
            'payment_scheduled_date' => now()->addDays(5),
            'status' => PayrollBatchStatusEnum::DRAFT,
            'total_employees' => 1,
            'total_gross_amount' => '10000000.00',
            'total_tax_amount' => '500000.00',
            'total_bpjs_employer' => '400000.00',
            'total_bpjs_employee' => '100000.00',
            'total_other_deductions' => '0.00',
            'total_net_amount' => '9400000.00',
            'created_by_user_id' => $userA->id,
        ]);

        $this->assertEquals($this->companyA->id, $batch->company_id);
        $this->assertSame($this->companyA->id, $batch->company->id);
    }
}
