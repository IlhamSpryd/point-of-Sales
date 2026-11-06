<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Department as CompatDepartment;
use App\Models\JobTitle as CompatJobTitle;
use App\Models\Tenant;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobTitle;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class DepartmentAndJobTitleTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private TenantContext $context;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenantA = Tenant::create([
            'name' => 'Tenant Alpha Corp',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant Beta Ltd',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);
    }

    public function test_multi_tenant_scoping_isolates_departments_and_job_titles(): void
    {
        // 1. Seed data for Tenant A
        $this->context->setTenantId($this->tenantA->id);

        $deptA = Department::create([
            'department_code' => 'ENG',
            'name' => 'Engineering Alpha',
            'description' => 'Software engineering team',
            'is_active' => true,
        ]);

        $jobTitleA = JobTitle::create([
            'department_id' => $deptA->id,
            'title_code' => 'SE-01',
            'name' => 'Senior Backend Engineer',
            'grade_level' => 4,
            'is_active' => true,
        ]);

        // 2. Seed data for Tenant B
        $this->context->setTenantId($this->tenantB->id);

        $deptB = Department::create([
            'department_code' => 'ENG',
            'name' => 'Engineering Beta',
            'description' => 'Beta engineering team',
            'is_active' => true,
        ]);

        $jobTitleB = JobTitle::create([
            'department_id' => $deptB->id,
            'title_code' => 'SE-01',
            'name' => 'Lead Architect',
            'grade_level' => 6,
            'is_active' => true,
        ]);

        // 3. Verify scoping when switched to Tenant A
        $this->context->setTenantId($this->tenantA->id);

        $deptIdsA = Department::pluck('id')->all();
        $this->assertContains($deptA->id, $deptIdsA);
        $this->assertNotContains($deptB->id, $deptIdsA);
        $this->assertNull(Department::find($deptB->id));

        $jobTitleIdsA = JobTitle::pluck('id')->all();
        $this->assertContains($jobTitleA->id, $jobTitleIdsA);
        $this->assertNotContains($jobTitleB->id, $jobTitleIdsA);
        $this->assertNull(JobTitle::find($jobTitleB->id));

        // 4. Verify scoping when switched to Tenant B
        $this->context->setTenantId($this->tenantB->id);

        $deptIdsB = Department::pluck('id')->all();
        $this->assertContains($deptB->id, $deptIdsB);
        $this->assertNotContains($deptA->id, $deptIdsB);
        $this->assertNull(Department::find($deptA->id));

        $jobTitleIdsB = JobTitle::pluck('id')->all();
        $this->assertContains($jobTitleB->id, $jobTitleIdsB);
        $this->assertNotContains($jobTitleA->id, $jobTitleIdsB);
        $this->assertNull(JobTitle::find($jobTitleA->id));

        // 5. Explicit bypass retrieves all records
        $allDepts = Department::withoutTenantScope()->pluck('id')->all();
        $this->assertContains($deptA->id, $allDepts);
        $this->assertContains($deptB->id, $allDepts);
    }

    public function test_code_duplication_prevented_within_tenant_but_allowed_across_tenants(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $deptA = Department::create([
            'department_code' => 'FIN',
            'name' => 'Finance Dept A',
        ]);

        JobTitle::create([
            'department_id' => $deptA->id,
            'title_code' => 'FIN-MGR',
            'name' => 'Finance Manager',
        ]);

        // Duplicate department_code in Tenant A must fail
        $this->expectException(QueryException::class);
        Department::create([
            'department_code' => 'FIN',
            'name' => 'Duplicate Finance Dept',
        ]);
    }

    public function test_job_title_code_duplication_prevented_within_same_tenant(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'OPS',
            'name' => 'Operations',
        ]);

        JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'OPS-LEAD',
            'name' => 'Operations Lead',
        ]);

        // Duplicate title_code in Tenant A must fail
        $this->expectException(QueryException::class);
        JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'OPS-LEAD',
            'name' => 'Operations Lead Duplicate',
        ]);
    }

    public function test_same_department_and_job_title_codes_allowed_in_different_tenants(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $deptA = Department::create([
            'department_code' => 'HRD',
            'name' => 'Human Resources A',
        ]);

        $titleA = JobTitle::create([
            'department_id' => $deptA->id,
            'title_code' => 'HR-MGR',
            'name' => 'HR Manager A',
        ]);

        // Switch to Tenant B and create with identical codes
        $this->context->setTenantId($this->tenantB->id);

        $deptB = Department::create([
            'department_code' => 'HRD',
            'name' => 'Human Resources B',
        ]);

        $titleB = JobTitle::create([
            'department_id' => $deptB->id,
            'title_code' => 'HR-MGR',
            'name' => 'HR Manager B',
        ]);

        $this->assertGreaterThan(0, $deptB->id);
        $this->assertGreaterThan(0, $titleB->id);
        $this->assertSame('HRD', $deptA->department_code);
        $this->assertSame('HRD', $deptB->department_code);
        $this->assertSame('HR-MGR', $titleA->title_code);
        $this->assertSame('HR-MGR', $titleB->title_code);
    }

    public function test_department_parent_and_children_hierarchy(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $headOffice = Department::create([
            'department_code' => 'HQ',
            'name' => 'Head Office',
        ]);

        $finance = Department::create([
            'parent_id' => $headOffice->id,
            'department_code' => 'FIN',
            'name' => 'Finance & Accounting',
        ]);

        $accounting = Department::create([
            'parent_id' => $finance->id,
            'department_code' => 'ACC',
            'name' => 'General Accounting',
        ]);

        // Verify parent relations
        $this->assertNull($headOffice->parent);
        $this->assertSame($headOffice->id, $finance->parent->id);
        $this->assertSame($finance->id, $accounting->parent->id);

        // Verify children collections
        $headOfficeChildren = $headOffice->children;
        $this->assertCount(1, $headOfficeChildren);
        $this->assertSame($finance->id, $headOfficeChildren->first()->id);

        $financeChildren = $finance->children;
        $this->assertCount(1, $financeChildren);
        $this->assertSame($accounting->id, $financeChildren->first()->id);

        // Deleting parent sets children parent_id to null (nullOnDelete)
        $headOffice->delete();
        $this->assertNull($finance->fresh()->parent_id);
    }

    public function test_foreign_key_integrity_prevents_deleting_department_with_active_job_titles(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'KITCHEN',
            'name' => 'Kitchen Operations',
        ]);

        $jobTitle = JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'CHEF-01',
            'name' => 'Head Chef',
        ]);

        // Attempting to delete department must fail due to restrictOnDelete
        $this->expectException(QueryException::class);
        $dept->delete();
    }

    public function test_department_can_be_deleted_after_job_titles_are_removed(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'SERVICE',
            'name' => 'Service Operations',
        ]);

        $jobTitle = JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'WAIT-01',
            'name' => 'Head Waiter',
        ]);

        $this->assertCount(1, $dept->jobTitles);

        // Remove job title first
        $jobTitle->delete();

        // Now department deletion succeeds
        $this->assertTrue($dept->delete());
        $this->assertNull(Department::find($dept->id));
    }

    public function test_assigns_tenant_protection_auto_assigns_tenant_id_on_create(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'MKT',
            'name' => 'Marketing',
        ]);

        $jobTitle = JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'MKT-SPEC',
            'name' => 'Marketing Specialist',
        ]);

        $this->assertSame($this->tenantA->id, $dept->tenant_id);
        $this->assertSame($this->tenantA->id, $jobTitle->tenant_id);
    }

    public function test_assigns_tenant_protection_rejects_manual_mutation_of_department_tenant_id(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'LEGAL',
            'name' => 'Legal & Compliance',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ownership (tenant_id) cannot be modified once set.');

        $dept->tenant_id = $this->tenantB->id;
        $dept->save();
    }

    public function test_assigns_tenant_protection_rejects_manual_mutation_of_job_title_tenant_id(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'PROC',
            'name' => 'Procurement',
        ]);

        $jobTitle = JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'PROC-BUYER',
            'name' => 'Buyer',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ownership (tenant_id) cannot be modified once set.');

        $jobTitle->tenant_id = $this->tenantB->id;
        $jobTitle->save();
    }

    public function test_assigns_tenant_rejects_cross_tenant_creation_in_active_context(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot create record for tenant {$this->tenantB->id} while in context of tenant {$this->tenantA->id}");

        Department::create([
            'tenant_id' => $this->tenantB->id,
            'department_code' => 'ROGUE',
            'name' => 'Rogue Department',
        ]);
    }

    public function test_backward_compatibility_wrappers(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $compatDept = CompatDepartment::create([
            'department_code' => 'COMPAT-D',
            'name' => 'Compatibility Department',
        ]);

        $compatTitle = CompatJobTitle::create([
            'department_id' => $compatDept->id,
            'title_code' => 'COMPAT-T',
            'name' => 'Compatibility Title',
        ]);

        $this->assertInstanceOf(Department::class, $compatDept);
        $this->assertInstanceOf(JobTitle::class, $compatTitle);

        $foundDept = CompatDepartment::where('department_code', 'COMPAT-D')->first();
        $this->assertNotNull($foundDept);
        $this->assertSame($compatDept->id, $foundDept->id);

        $foundTitle = CompatJobTitle::where('title_code', 'COMPAT-T')->first();
        $this->assertNotNull($foundTitle);
        $this->assertSame($compatTitle->id, $foundTitle->id);
    }

    public function test_employment_status_enum_cases_labels_and_colors(): void
    {
        $expectedCases = [
            'DRAFT' => ['label' => 'Draft', 'badgeColor' => 'secondary'],
            'ACTIVE' => ['label' => 'Active', 'badgeColor' => 'success'],
            'ON_PROBATION' => ['label' => 'On Probation', 'badgeColor' => 'warning'],
            'SUSPENDED' => ['label' => 'Suspended', 'badgeColor' => 'danger'],
            'RESIGNED' => ['label' => 'Resigned', 'badgeColor' => 'warning'],
            'TERMINATED' => ['label' => 'Terminated', 'badgeColor' => 'danger'],
            'ARCHIVED' => ['label' => 'Archived', 'badgeColor' => 'neutral'],
        ];

        $actualCases = EmploymentStatusEnum::cases();
        $this->assertCount(7, $actualCases);

        foreach ($expectedCases as $value => $meta) {
            $enum = EmploymentStatusEnum::from($value);
            $this->assertSame($value, $enum->value);
            $this->assertSame($meta['label'], $enum->label());
            $this->assertSame($meta['badgeColor'], $enum->badgeColor());
        }
    }

    public function test_employment_type_enum_cases_and_labels(): void
    {
        $expectedCases = [
            'PERMANENT' => 'Permanent (PKWTT)',
            'CONTRACT_PKWT' => 'Contract (PKWT)',
            'PROBATION' => 'Probation',
            'INTERN' => 'Internship',
            'DAILY_WORKER' => 'Daily Worker',
        ];

        $actualCases = EmploymentTypeEnum::cases();
        $this->assertCount(5, $actualCases);

        foreach ($expectedCases as $value => $label) {
            $enum = EmploymentTypeEnum::from($value);
            $this->assertSame($value, $enum->value);
            $this->assertSame($label, $enum->label());
        }
    }

    public function test_model_casts_and_attributes(): void
    {
        $this->context->setTenantId($this->tenantA->id);

        $dept = Department::create([
            'department_code' => 'CAST-DEPT',
            'name' => 'Cast Department',
            'is_active' => 1,
        ]);

        $jobTitle = JobTitle::create([
            'department_id' => $dept->id,
            'title_code' => 'CAST-TITLE',
            'name' => 'Cast Job Title',
            'grade_level' => '5',
            'is_active' => '0',
        ]);

        $this->assertTrue($dept->is_active);
        $this->assertSame(5, $jobTitle->grade_level);
        $this->assertFalse($jobTitle->is_active);

        // Verify relationships
        $this->assertSame($dept->id, $jobTitle->department->id);
        $this->assertTrue($dept->jobTitles->contains('id', $jobTitle->id));
        $this->assertSame($this->tenantA->id, $dept->tenant->id);
        $this->assertSame($this->tenantA->id, $jobTitle->tenant->id);
    }
}
