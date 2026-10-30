<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Approval;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Enums\PtkpStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Enums\SalaryComponentTypeEnum;
use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use App\Modules\HR\Jobs\PostPayrollToGeneralLedgerJob;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBpjsProfile;
use App\Modules\HR\Models\EmployeeCompensation;
use App\Modules\HR\Models\EmployeeTaxProfile;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\SalaryComponent;
use App\Modules\HR\Services\PayrollBatchService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollPostingIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected AccountingPeriod $period;

    protected PayrollBatchService $batchService;

    protected PayrollPostingService $postingService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->seed(StatutoryRuleVersionsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Payroll GL Tenant',
            'code' => 'GL-TENANT',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GL Store Headquarter',
            'code' => 'GL-HQ',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Seed Chart of Accounts untuk tenant ini
        (new ChartOfAccountsSeeder)->seedForTenant($this->tenant->id);

        // Buat open accounting period
        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-10',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'fiscal_year' => 2026,
            'period_number' => 10,
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-GL',
            'name' => 'Operations GL',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'MGR-GL',
            'name' => 'Store Manager',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-GL-001',
            'full_name' => 'Budi Santoso',
            'email' => 'budi.gl@corp.com',
            'phone_number' => '081299990099',
            'national_id_number' => '3171010101900099',
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
            'jkk_risk_grade' => 2,
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
        ]);

        $comp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE-SAL-GL',
            'component_name' => 'Gaji Pokok Manager',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'salary_component_id' => $comp->id,
            'amount' => 15000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->batchService = app(PayrollBatchService::class);
        $this->postingService = app(PayrollPostingService::class);
    }

    public function test_post_accrual_journal_creates_balanced_gl_entry_and_links_to_batch(): void
    {
        Carbon::setTestNow('2026-10-31 23:59:59');

        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $batch = $this->batchService->executeCalculation($batch);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'document_type' => 'PAYROLL_BATCH',
            'document_id' => $batch->id,
            'action_code' => 'PAYROLL_EXECUTION',
            'status' => 'APPROVED',
            'approver_user_id' => $this->user->id,
            'approved_at' => now(),
        ]);

        $finalizedBatch = $this->batchService->finalizeBatch($batch->id, $this->user->id, $approval->id);

        $journal = $this->postingService->postAccrualJournal($finalizedBatch, $this->user->id);

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertGreaterThan(0, $journal->id);
        $this->assertEquals($this->tenant->id, $journal->tenant_id);
        $this->assertEquals("Akrual Beban Penggajian Batch {$finalizedBatch->batch_number}", $journal->notes);

        // Verifikasi Keseimbangan Akuntansi: Debit == Credit
        $this->assertTrue(bccomp((string) $journal->total_debit, (string) $journal->total_credit, 2) === 0);
        $this->assertGreaterThan(0, (float) $journal->total_debit);

        // Verifikasi relasi batch ke jurnal GL
        $finalizedBatch->refresh();
        $this->assertEquals($journal->id, $finalizedBatch->gl_accrual_journal_id);

        // Verifikasi baris-baris jurnal
        $lines = JournalLine::withoutTenantScope()->where('journal_entry_id', $journal->id)->get();
        $this->assertGreaterThanOrEqual(4, $lines->count());

        $hasSalaryDebit = $lines->contains(fn (JournalLine $l) => bccomp((string) $l->debit_amount, '15000000.00', 2) === 0);
        $hasBpjsEmployerDebit = $lines->contains(fn (JournalLine $l) => (float) $l->debit_amount > 0 && str_contains($l->description ?? '', 'BPJS'));
        $hasPayrollPayableCredit = $lines->contains(fn (JournalLine $l) => (float) $l->credit_amount > 0 && str_contains($l->description ?? '', 'Utang Gaji'));
        $hasPph21Credit = $lines->contains(fn (JournalLine $l) => (float) $l->credit_amount > 0 && str_contains($l->description ?? '', 'PPh 21'));

        $this->assertTrue($hasSalaryDebit, 'Harus mendebet Beban Gaji.');
        $this->assertTrue($hasBpjsEmployerDebit, 'Harus mendebet Beban BPJS ER.');
        $this->assertTrue($hasPayrollPayableCredit, 'Harus mengkredit Utang Gaji.');
        $this->assertTrue($hasPph21Credit, 'Harus mengkredit Utang PPh 21.');
    }

    public function test_post_accrual_journal_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-31 23:59:59');

        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $batch = $this->batchService->executeCalculation($batch);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'document_type' => 'PAYROLL_BATCH',
            'document_id' => $batch->id,
            'action_code' => 'PAYROLL_EXECUTION',
            'status' => 'APPROVED',
            'approver_user_id' => $this->user->id,
            'approved_at' => now(),
        ]);

        $finalizedBatch = $this->batchService->finalizeBatch($batch->id, $this->user->id, $approval->id);

        $journal1 = $this->postingService->postAccrualJournal($finalizedBatch, $this->user->id);
        $journal2 = $this->postingService->postAccrualJournal($finalizedBatch, $this->user->id);

        $this->assertEquals($journal1->id, $journal2->id);

        $count = JournalEntry::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "PAYROLL-{$this->tenant->id}-{$finalizedBatch->id}-ACCRUAL")
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_post_payment_journal_records_cash_disbursement_and_updates_batch_to_paid(): void
    {
        Carbon::setTestNow('2026-10-31 23:59:59');

        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $batch = $this->batchService->executeCalculation($batch);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'document_type' => 'PAYROLL_BATCH',
            'document_id' => $batch->id,
            'action_code' => 'PAYROLL_EXECUTION',
            'status' => 'APPROVED',
            'approver_user_id' => $this->user->id,
            'approved_at' => now(),
        ]);

        $finalizedBatch = $this->batchService->finalizeBatch($batch->id, $this->user->id, $approval->id);

        // Ambil akun Kas/Bank
        $cashAccount = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('system_account_key', 'CASH_MAIN')
            ->firstOrFail();

        $paymentJournal = $this->postingService->postPaymentJournal($finalizedBatch, $cashAccount->id, $this->user->id);

        $this->assertInstanceOf(JournalEntry::class, $paymentJournal);
        $this->assertTrue(bccomp((string) $paymentJournal->total_debit, (string) $paymentJournal->total_credit, 2) === 0);
        $this->assertTrue(bccomp((string) $paymentJournal->total_debit, (string) $finalizedBatch->total_net_amount, 2) === 0);

        $finalizedBatch->refresh();
        $this->assertEquals(PayrollBatchStatusEnum::PAID, $finalizedBatch->status);
        $this->assertEquals($paymentJournal->id, $finalizedBatch->gl_payment_journal_id);
        $this->assertNotNull($finalizedBatch->paid_at);
    }

    public function test_cannot_post_accrual_on_unfinalized_batch(): void
    {
        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('harus berstatus FINALIZED');

        $this->postingService->postAccrualJournal($batch, $this->user->id);
    }

    public function test_post_payroll_to_general_ledger_job_handles_accrual_async(): void
    {
        Carbon::setTestNow('2026-10-31 23:59:59');

        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        $batch = $this->batchService->executeCalculation($batch);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'document_type' => 'PAYROLL_BATCH',
            'document_id' => $batch->id,
            'action_code' => 'PAYROLL_EXECUTION',
            'status' => 'APPROVED',
            'approver_user_id' => $this->user->id,
            'approved_at' => now(),
        ]);

        $finalizedBatch = $this->batchService->finalizeBatch($batch->id, $this->user->id, $approval->id);

        $job = new PostPayrollToGeneralLedgerJob($finalizedBatch->id, 'ACCRUAL', null, $this->user->id);
        $job->handle($this->postingService);

        $finalizedBatch->refresh();
        $this->assertNotNull($finalizedBatch->gl_accrual_journal_id);
    }
}
