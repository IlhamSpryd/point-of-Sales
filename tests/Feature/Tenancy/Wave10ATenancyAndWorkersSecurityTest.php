<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Core\Context\TenantContext;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Pricing\MarginSimulationModal;
use App\Livewire\Sales\ReturnCreditNoteManager;
use App\Livewire\Subscription\SubscriptionPortal;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Jobs\RunMonthlyDepreciationJob;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Workforce\Enums\EmploymentStatusEnum;
use App\Modules\Workforce\Enums\EmploymentTypeEnum;
use App\Modules\Workforce\Enums\GenderEnum;
use App\Modules\Workforce\Enums\MaritalStatusEnum;
use App\Modules\Workforce\Enums\PayrollBatchStatusEnum;
use App\Modules\Workforce\Enums\ReligionEnum;
use App\Modules\Workforce\Enums\SalaryComponentTypeEnum;
use App\Modules\Workforce\Jobs\GeneratePayslipPdfJob;
use App\Modules\Workforce\Jobs\PostPayrollToGeneralLedgerJob;
use App\Modules\Workforce\Models\Department;
use App\Modules\Workforce\Models\Employee;
use App\Modules\Workforce\Models\JobTitle;
use App\Modules\Workforce\Models\PayrollBatch;
use App\Modules\Workforce\Models\PayrollItem;
use App\Modules\Workforce\Models\PayrollRecord;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Wave10ATenancyAndWorkersSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private Company $companyA;

    private User $ownerA;

    private User $cashierA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant Alpha '.uniqid(),
            'slug' => 'tenant-alpha-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant Beta '.uniqid(),
            'slug' => 'tenant-beta-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);

        $this->companyA = Company::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'company_code' => 'CMP-A-'.Str::random(4),
            'company_name' => 'Alpha Corp',
            'legal_name' => 'PT Alpha Nusantara',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->storeA = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha',
            'is_active' => true,
        ]);

        $this->storeB = $context->runAs($this->tenantB->id, null, fn () => Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Store Beta',
            'is_active' => true,
        ]));

        $ownerRole = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
            'role_code' => 'ROLE-OWN',
            'is_system_default' => 1,
            'permissions' => ['*'],
        ]);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view'],
        ]);

        $this->ownerA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->cashierA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);
        $this->cashierA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $context->setStoreId($this->storeA->id);
    }

    public function test_p0_04_tenant_a_cannot_evaluate_or_close_tenant_b_accounting_period(): void
    {
        $periodB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return AccountingPeriod::create([
                'tenant_id' => $this->tenantB->id,
                'period_code' => '2026-10',
                'fiscal_year' => 2026,
                'period_number' => 10,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'status' => AccountingPeriodStatusEnum::OPEN,
                'is_adjustment_period' => false,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $service = app(AccountingPeriodService::class);

        // 1. Cross-tenant evaluateClosingGates by ID & Model must throw ModelNotFoundException
        try {
            $service->evaluateClosingGates($periodB->id, $this->ownerA);
            $this->fail('Expected ModelNotFoundException when Tenant A evaluates Tenant B period ID.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $service->evaluateClosingGates($periodB, $this->ownerA);
            $this->fail('Expected ModelNotFoundException when Tenant A evaluates Tenant B period model.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // 2. Cross-tenant closePeriod by ID & Model must throw ModelNotFoundException
        try {
            $service->closePeriod($periodB->id, $this->ownerA);
            $this->fail('Expected ModelNotFoundException when Tenant A closes Tenant B period ID.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $service->closePeriod($periodB, $this->ownerA);
            $this->fail('Expected ModelNotFoundException when Tenant A closes Tenant B period model.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            AccountingPeriodStatusEnum::OPEN,
            AccountingPeriod::withoutTenantScope()->findOrFail($periodB->id)->status
        );
    }

    public function test_p0_05_async_workforce_jobs_succeed_with_empty_initial_tenant_context(): void
    {
        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function (): void {
            (new ChartOfAccountsSeeder)->seedForTenant($this->tenantA->id);

            AccountingPeriod::create([
                'tenant_id' => $this->tenantA->id,
                'period_code' => '2026-10',
                'fiscal_year' => 2026,
                'period_number' => 10,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'status' => AccountingPeriodStatusEnum::OPEN,
                'is_adjustment_period' => false,
            ]);
        });

        $dept = Department::create([
            'tenant_id' => $this->tenantA->id,
            'department_code' => 'OPS-W10A',
            'name' => 'Operations',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenantA->id,
            'department_id' => $dept->id,
            'title_code' => 'SUP-W10A',
            'name' => 'Supervisor',
        ]);

        $employee = Employee::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->ownerA->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-W10A-1',
            'full_name' => 'Siti Pekerja',
            'email' => 'siti.'.uniqid().'@alpha.test',
            'phone_number' => '081299990011',
            'national_id_number' => '3171010101900011',
            'birth_date' => '1991-04-10',
            'hire_date' => '2025-01-01',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $batch = PayrollBatch::create([
            'tenant_id' => $this->tenantA->id,
            'batch_number' => 'PAY-W10A-'.Str::random(5),
            'period_start_date' => '2026-10-01',
            'period_end_date' => '2026-10-31',
            'cutoff_date' => '2026-10-25',
            'payment_scheduled_date' => '2026-10-31',
            'status' => PayrollBatchStatusEnum::FINALIZED,
            'total_employees' => 1,
            'total_gross_amount' => '10000000.00',
            'total_tax_amount' => '250000.00',
            'total_bpjs_employer' => '0.00',
            'total_bpjs_employee' => '200000.00',
            'total_other_deductions' => '0.00',
            'total_net_amount' => '9550000.00',
            'created_by_user_id' => $this->ownerA->id,
            'finalized_at' => now(),
        ]);

        $record = PayrollRecord::create([
            'tenant_id' => $this->tenantA->id,
            'payroll_batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'employee_number_snapshot' => 'EMP-W10A-1',
            'full_name_snapshot' => 'Siti Pekerja',
            'department_name_snapshot' => 'Operations',
            'job_title_snapshot' => 'Supervisor',
            'primary_store_id' => $this->storeA->id,
            'primary_store_name_snapshot' => 'Store Alpha',
            'ptkp_status_snapshot' => 'TK/0',
            'tax_calculation_method_snapshot' => 'GROSS',
            'bank_name_snapshot' => 'BCA',
            'bank_account_number_snapshot' => '1234567890',
            'basic_salary_amount' => '10000000.00',
            'fixed_allowances_amount' => '0.00',
            'variable_allowances_amount' => '0.00',
            'overtime_amount' => '0.00',
            'commissions_amount' => '0.00',
            'gross_earnings_amount' => '10000000.00',
            'taxable_gross_amount' => '10000000.00',
            'tax_pph21_amount' => '250000.00',
            'tax_pph21_dtp_amount' => '0.00',
            'bpjs_ks_employer_amount' => '0.00',
            'bpjs_ks_employee_amount' => '100000.00',
            'bpjs_tk_employer_amount' => '0.00',
            'bpjs_tk_employee_amount' => '100000.00',
            'other_deductions_amount' => '0.00',
            'net_pay_amount' => '9550000.00',
            'payslip_token' => Str::random(64),
        ]);

        PayrollItem::create([
            'tenant_id' => $this->tenantA->id,
            'payroll_record_id' => $record->id,
            'component_code' => 'BASIC_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'amount' => '10000000.00',
            'is_taxable' => true,
        ]);

        // Clear TenantContext to simulate stateless Redis/DB queue worker
        $context = app(TenantContext::class);
        $context->clear();

        // 1. PostPayrollToGeneralLedgerJob must succeed and restore empty TenantContext
        $postJob = new PostPayrollToGeneralLedgerJob($batch->id, 'ACCRUAL', null, $this->ownerA->id, $this->tenantA->id);
        $this->app->call([$postJob, 'handle']);

        $this->assertNull($context->getTenantId(), 'TenantContext must be restored to null after job execution.');
        $batch->refresh();
        $this->assertNotNull($batch->gl_accrual_journal_id, 'GL Accrual journal must be posted even with empty initial TenantContext.');

        // 2. GeneratePayslipPdfJob must render valid PDF to tenant-scoped storage and restore TenantContext
        Storage::fake('local');
        $context->clear();
        $pdfJob = new GeneratePayslipPdfJob($record->id, $this->tenantA->id);
        $this->app->call([$pdfJob, 'handle']);

        $this->assertNull($context->getTenantId(), 'TenantContext must remain cleared after PDF job.');
        $expectedPath = "payslips/tenant_{$this->tenantA->id}/payslip_{$record->id}.pdf";
        Storage::disk('local')->assertExists($expectedPath);
        $pdfContent = Storage::disk('local')->get($expectedPath);
        $this->assertNotNull($pdfContent);
        $this->assertStringStartsWith('%PDF-', $pdfContent);

        // 3. RunMonthlyDepreciationJob must restore TenantContext even when period fails (no context poisoning)
        $context->clear();
        $depreciationJob = new RunMonthlyDepreciationJob($this->tenantA->id, 999999999, $this->ownerA->id);
        try {
            $this->app->call([$depreciationJob, 'handle']);
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($context->getTenantId(), 'RunMonthlyDepreciationJob must not leak TenantContext after failure.');
    }

    public function test_p0_05_suspended_tenant_grace_allows_subscription_portal_and_blocks_operations(): void
    {
        $starterPlan = Plan::firstOrCreate(
            ['code' => 'starter-plan'],
            ['name' => 'Starter Plan', 'price' => 299000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        TenantSubscription::create([
            'tenant_id' => $this->tenantA->id,
            'plan_id' => $starterPlan->id,
            'status' => 'suspended',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->tenantA->update(['access_status' => Tenant::ACCESS_SUSPENDED]);

        // Suspended owner MUST still access SubscriptionPortal Livewire actions without 403 logout
        Livewire::actingAs($this->ownerA)
            ->test(SubscriptionPortal::class)
            ->assertStatus(200)
            ->call('requestPlanUpgrade'); // asserts validation runs cleanly without 403 abort

        $this->assertAuthenticatedAs($this->ownerA);

        // Operational Livewire components MUST remain blocked (403) while suspended
        Livewire::actingAs($this->ownerA)
            ->test(CustomerManager::class)
            ->assertForbidden();
    }

    public function test_p1_04_livewire_components_enforce_tenant_context_and_abilities(): void
    {
        $campaignB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return PromotionCampaign::create([
                'tenant_id' => $this->tenantB->id,
                'campaign_code' => 'BETA-'.Str::random(4),
                'name' => 'Beta Campaign',
                'type' => PromotionCampaign::TYPE_AUTOMATIC_DISCOUNT,
                'status' => PromotionCampaign::STATUS_DRAFT,
                'start_datetime' => now()->toDateTimeString(),
                'end_datetime' => now()->addDays(10)->toDateTimeString(),
            ]);
        });

        $customerB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return Customer::create([
                'tenant_id' => $this->tenantB->id,
                'customer_code' => 'CUST-B-'.Str::random(4),
                'name' => 'Beta Customer',
                'is_active' => true,
            ]);
        });

        // 1. Unauthorized Cashier blocked on ReturnCreditNoteManager, MarginSimulationModal, CustomerManager, IngredientLedger
        Livewire::actingAs($this->cashierA)
            ->test(ReturnCreditNoteManager::class)
            ->assertForbidden();

        Livewire::actingAs($this->cashierA)
            ->test(MarginSimulationModal::class)
            ->assertForbidden();

        Livewire::actingAs($this->cashierA)
            ->test(CustomerManager::class)
            ->assertForbidden();

        Livewire::actingAs($this->cashierA)
            ->test(IngredientLedger::class)
            ->assertForbidden();

        // 2. Owner of Tenant A blocked (404 Not Found) from cross-tenant MarginSimulationModal & CustomerManager statement IDs
        Livewire::actingAs($this->ownerA)
            ->test(MarginSimulationModal::class)
            ->call('open', $campaignB->id)
            ->assertNotFound();

        Livewire::actingAs($this->ownerA)
            ->test(CustomerManager::class)
            ->call('openStatement', $customerB->id)
            ->assertNotFound();

        // 3. Custom role name with explicit 'inventory.view' ability succeeds on IngredientLedger (no hardcoded role-name array)
        $customWarehouseRole = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gudang Custom Lead',
            'permissions' => ['inventory.view'],
        ]);
        $warehouseUser = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $customWarehouseRole->id,
            'is_active' => true,
        ]);
        $warehouseUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        Livewire::actingAs($warehouseUser)
            ->test(IngredientLedger::class)
            ->assertOk();
    }
}
