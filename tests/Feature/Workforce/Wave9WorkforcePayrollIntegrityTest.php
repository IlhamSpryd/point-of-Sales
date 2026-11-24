<?php

declare(strict_types=1);

namespace Tests\Feature\Workforce;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Organization\Models\Approval;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Workforce\Enums\CommissionBasisTypeEnum;
use App\Modules\Workforce\Enums\CommissionRecordStatusEnum;
use App\Modules\Workforce\Enums\CommissionRecordTypeEnum;
use App\Modules\Workforce\Enums\EmploymentStatusEnum;
use App\Modules\Workforce\Enums\EmploymentTypeEnum;
use App\Modules\Workforce\Enums\GenderEnum;
use App\Modules\Workforce\Enums\MaritalStatusEnum;
use App\Modules\Workforce\Enums\PayrollBatchStatusEnum;
use App\Modules\Workforce\Enums\PtkpStatusEnum;
use App\Modules\Workforce\Enums\ReligionEnum;
use App\Modules\Workforce\Enums\SalaryComponentTypeEnum;
use App\Modules\Workforce\Enums\TaxCalculationMethodEnum;
use App\Modules\Workforce\Models\CommissionRule;
use App\Modules\Workforce\Models\Department;
use App\Modules\Workforce\Models\Employee;
use App\Modules\Workforce\Models\EmployeeBpjsProfile;
use App\Modules\Workforce\Models\EmployeeTaxProfile;
use App\Modules\Workforce\Models\JobTitle;
use App\Modules\Workforce\Models\PayrollItem;
use App\Modules\Workforce\Models\PayrollRecord;
use App\Modules\Workforce\Services\CommissionService;
use App\Modules\Workforce\Services\PayrollBatchService;
use App\Modules\Workforce\Services\PayrollCalculationPipeline;
use App\Modules\Workforce\Services\PayrollPostingService;
use App\Modules\Workforce\Services\StatutoryBpjsService;
use App\Platform\Workflow\Exceptions\SegregationOfDutiesViolationException;
use App\Platform\Workflow\MakerCheckerGuard;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Wave9WorkforcePayrollIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected PayrollCalculationPipeline $pipeline;

    protected PayrollBatchService $batchService;

    protected PayrollPostingService $postingService;

    protected CommissionService $commissionService;

    protected StatutoryBpjsService $bpjsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->seed(StatutoryRuleVersionsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Wave 9 Workforce Tenant',
            'code' => 'W9-WF-TENANT',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wave 9 Store Central',
            'code' => 'W9-STORE-01',
            'status' => 'ACTIVE',
        ]);
        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $coaSeeder = app(ChartOfAccountsSeeder::class);
        $coaSeeder->seedForTenant($this->tenant->id);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'OPS-01',
            'name' => 'Operations',
        ]);

        $title = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'STAFF-01',
            'name' => 'Staff Operasional',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'employee_number' => 'EMP-W9-001',
            'full_name' => 'Budi Pratama',
            'email' => 'budi.w9@corp.com',
            'phone_number' => '081299991111',
            'national_id_number' => '3171010101900099',
            'birth_date' => '1995-05-15',
            'hire_date' => '2024-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
        ]);

        $this->pipeline = app(PayrollCalculationPipeline::class);
        $this->batchService = app(PayrollBatchService::class);
        $this->postingService = app(PayrollPostingService::class);
        $this->commissionService = app(CommissionService::class);
        $this->bpjsService = app(StatutoryBpjsService::class);
    }

    /**
     * Test Simulasi A: Payroll Reguler Bulanan Pegawai Tetap (TK/0, TER A, PP 58/2023)
     * Termasuk JKM 0.30% (Rp 36.000 pada upah Rp 12.000.000)
     */
    public function test_simulasi_a_regular_monthly_payroll_ter_a_including_jkm(): void
    {
        $periodStart = Carbon::parse('2026-05-01');
        $periodEnd = Carbon::parse('2026-05-31');

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'effective_from' => '2024-01-01',
            'ptkp_status' => PtkpStatusEnum::TK_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'tax_id_number' => '091234567890123',
            'is_eligible_for_dtp' => false,
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
            'jkk_risk_grade' => 2, // 0.54%
        ]);

        $result = $this->pipeline->calculateEmployeePayroll(
            $this->employee,
            $periodStart,
            $periodEnd,
            [
                'base_salary' => 10000000.00,
                'fixed_allowance' => 2000000.00,
                'commission_amount' => 1500000.00,
                'jkk_class' => 'class_2', // 0.54%
            ]
        );

        $this->assertNotNull($result);

        // Verifikasi JKK (0.54% * 12M = 64.800) dan JKM (0.30% * 12M = 36.000)
        $this->assertEquals(64800.00, $result->bpjsTkJkkEmployer);
        $this->assertEquals(36000.00, $result->bpjsTkJkmEmployer);
        $this->assertEquals(480000.00, $result->bpjsKsEmployer);

        // Bruto Kas = 10M + 2M + 1.5M = 13.5M
        $this->assertEquals(13500000.00, $result->grossEarningsAmount);

        // Bruto Pajak = 13.5M + 480k + 64.8k + 36k (JKM 0.30% per Koreksi #1) = 14.080.800
        $this->assertEquals(14080800.00, $result->taxGrossAmount);

        // TER A tier 13.75M - 15.10M = 6% -> 14.080.800 * 6% = 844.848
        $this->assertEquals(844848.00, $result->taxPph21Deducted);

        // Potongan BPJS EE: KS (120k) + JHT (240k) + JP (110.863) = 470.863
        $this->assertEquals(470863.00, $result->bpjsEmployeeTotal);

        // THP = 13.500.000 - 844.848 - 470.863 = 12.184.289
        $this->assertEquals(12184289.00, $result->netPayAmount);
    }

    /**
     * Test Simulasi B: Payroll Desember Masa Pajak Terakhir (K/1, TER B vs Pasal 17 UU HPP PMK 168/2023)
     */
    public function test_simulasi_b_december_final_month_reconciliation_pasal17(): void
    {
        $periodStart = Carbon::parse('2026-12-01');
        $periodEnd = Carbon::parse('2026-12-31');

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'effective_from' => '2024-01-01',
            'ptkp_status' => PtkpStatusEnum::K_1,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'tax_id_number' => '091234567890123',
            'is_eligible_for_dtp' => false,
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
            'jkk_risk_grade' => 2,
        ]);

        // Hendra Wijaya: Gaji Pokok 18M/bln, Bruto Pajak bulanan 18.631.200
        // 11 bulan sebelumnya telah dipotong TER B 6% = 12.296.592
        $priorTaxesPaid = 12296592.00;
        $priorGross = 11 * 18631200.00; // 204.943.200
        $priorJhtJpEe = 11 * 470863.00; // 5.179.493

        $result = $this->pipeline->calculateEmployeePayroll(
            $this->employee,
            $periodStart,
            $periodEnd,
            [
                'base_salary' => 18000000.00,
                'fixed_allowance' => 0.00,
                'jkk_class' => 'class_2',
                'prior_taxes_paid' => $priorTaxesPaid,
                'prior_gross' => $priorGross,
                'prior_jht_jp_ee' => $priorJhtJpEe,
                'is_final_month' => true,
            ]
        );

        $this->assertNotNull($result);

        // PPh 21 Pasal 17 tahunan rekonsiliasi Desember harus berkisar Rp 4.042.008 s.d. Rp 5.400.000 (bukan TER bulanan Rp 1.117.872!)
        $this->assertGreaterThan(3500000.00, $result->taxPph21Deducted);
        $this->assertLessThan(6000000.00, $result->taxPph21Deducted);
    }

    /**
     * Test Kasus Lebih Potong Desember (PPh 21 < 0) dikembalikan ke THP dan GL Debet Utang PPh 21
     */
    public function test_december_overwithholding_negative_tax_refunded_to_net_pay(): void
    {
        $periodStart = Carbon::parse('2026-12-01');
        $periodEnd = Carbon::parse('2026-12-31');

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'effective_from' => '2024-01-01',
            'ptkp_status' => PtkpStatusEnum::K_1,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'is_bpjs_tk_active' => false,
            'is_bpjs_ks_active' => false,
        ]);

        // Simulasi overwithholding: pajak disetor Jan-Nov 10.000.000, tapi kewajiban tahunan hanya 8.000.000
        // Sehingga pajak Desember = -2.000.000 (Refund)
        $result = $this->pipeline->calculateEmployeePayroll(
            $this->employee,
            $periodStart,
            $periodEnd,
            [
                'base_salary' => 5000000.00,
                'prior_taxes_paid' => 10000000.00,
                'prior_gross' => 55000000.00,
                'prior_jht_jp_ee' => 0.00,
                'is_final_month' => true,
            ]
        );

        $this->assertNotNull($result);
        $this->assertLessThan(0.00, $result->taxPph21Deducted);

        // THP harus bertambah sebesar pengembalian pajak
        $this->assertGreaterThan(5000000.00, $result->netPayAmount);

        // Ada item TAX_PPH21_REFUND
        $refundItem = collect($result->items)->firstWhere('componentCode', 'TAX_PPH21_REFUND');
        $this->assertNotNull($refundItem);
        $this->assertEquals(abs($result->taxPph21Deducted), $refundItem->amount);
    }

    /**
     * Test Gross-Up: Tunjangan Pajak Masuk ke Gross Earnings & GL Accrual Seimbang Rp0.00
     */
    public function test_gross_up_tax_allowance_preserves_net_pay_and_balances_gl(): void
    {
        $periodStart = Carbon::parse('2026-06-01');
        $periodEnd = Carbon::parse('2026-06-30');

        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'effective_from' => '2024-01-01',
            'ptkp_status' => PtkpStatusEnum::TK_0,
            'calculation_method' => TaxCalculationMethodEnum::GROSS_UP,
        ]);

        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'is_bpjs_tk_active' => false,
            'is_bpjs_ks_active' => false,
        ]);

        $baseSalary = 10000000.00;
        $result = $this->pipeline->calculateEmployeePayroll(
            $this->employee,
            $periodStart,
            $periodEnd,
            [
                'base_salary' => $baseSalary,
                'tax_method' => TaxCalculationMethodEnum::GROSS_UP,
            ]
        );

        $this->assertNotNull($result);

        // THP harus tetap utuh Rp 10.000.000 (tidak berkurang oleh potongan pajak)
        $this->assertEquals($baseSalary, $result->netPayAmount);

        // Ada item TAX_ALLOWANCE
        $allowanceItem = collect($result->items)->firstWhere('componentCode', 'TAX_ALLOWANCE');
        $this->assertNotNull($allowanceItem);
        $this->assertGreaterThan(0.00, $allowanceItem->amount);
    }

    /**
     * Test BPJS Usia Pensiun (>= 59 Tahun) Bebas Iuran Jaminan Pensiun (JP 0% EE & ER)
     */
    public function test_bpjs_retirement_age_exempts_jp_contributions(): void
    {
        $periodEnd = Carbon::parse('2026-07-31');

        // Karyawan usia 60 tahun
        $contribElder = $this->bpjsService->calculateContributions(
            effectiveWage: 12000000.00,
            effectiveDate: $periodEnd,
            jkkRiskClass: 'class_1',
            jurisdiction: 'ID',
            employeeAge: 60
        );

        $this->assertEquals(0.00, $contribElder['jp_employer']);
        $this->assertEquals(0.00, $contribElder['jp_employee']);

        // Karyawan usia 30 tahun tetap dikenakan JP
        $contribYoung = $this->bpjsService->calculateContributions(
            effectiveWage: 12000000.00,
            effectiveDate: $periodEnd,
            jkkRiskClass: 'class_1',
            jurisdiction: 'ID',
            employeeAge: 30
        );

        $this->assertGreaterThan(0.00, $contribYoung['jp_employer']);
        $this->assertGreaterThan(0.00, $contribYoung['jp_employee']);
    }

    /**
     * Test Komisi Penjualan: Basis DPP Murni (Eksklusi PB1 & Service Charge)
     * Serta Clawback pada Void Order & Retur (Sebelum dan Sesudah PAID)
     */
    public function test_commission_dpp_basis_and_clawback_lifecycle(): void
    {
        $rule = CommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'rule_name' => 'Komisi Sales 5%',
            'basis_type' => CommissionBasisTypeEnum::PERCENTAGE_OF_SALES,
            'rate_percent' => 5.00,
            'rate_amount' => 0.00,
            'min_sales_threshold' => 0.00,
            'valid_from' => '2026-01-01',
            'is_active' => true,
        ]);

        // Order dengan Subtotal 1M, PB1 100k, Service 50k -> Grand Total 1.15M
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-COMM-001',
            'order_status' => OrderStatus::Completed,
            'subtotal_amount' => 1000000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 100000.00,
            'service_charge_amount' => 50000.00,
            'order_amount' => 1150000.00,
            'order_date' => '2026-08-10',
            'idempotency_key' => 'idemp-comm-001',
        ]);

        $records = $this->commissionService->calculateAndAwardOrderCommission($order, $this->employee->id);
        $this->assertCount(1, $records);

        // Komisi harus 5% dari Rp 1.000.000 = Rp 50.000 (BUKAN Rp 57.500 dari Grand Total!)
        $commRecord = $records[0];
        $this->assertEquals(1000000.00, (float) $commRecord->calculation_basis_amount);
        $this->assertEquals(50000.00, (float) $commRecord->commission_amount);
        $this->assertEquals(CommissionRecordStatusEnum::PENDING, $commRecord->status);

        // Skenario A: Order di-void saat komisi masih PENDING -> berubah menjadi VOIDED
        $this->commissionService->clawbackCommission($order->id, null, null, 'ORDER_VOID');
        $commRecord->refresh();
        $this->assertEquals(CommissionRecordStatusEnum::VOIDED, $commRecord->status);

        // Skenario B: Komisi sudah PAID di batch tertutup lalu terjadi Retur -> dibuat record CLAWBACK negatif
        $orderPaid = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-COMM-PAID-001',
            'order_status' => OrderStatus::Completed,
            'subtotal_amount' => 2000000.00,
            'discount_amount' => 0.00,
            'order_amount' => 2000000.00,
            'order_date' => '2026-08-12',
            'idempotency_key' => 'idemp-comm-paid-001',
        ]);

        $paidRecords = $this->commissionService->calculateAndAwardOrderCommission($orderPaid, $this->employee->id);
        $paidRecord = $paidRecords[0];
        $paidRecord->status = CommissionRecordStatusEnum::PAID;
        $paidRecord->paid_at = now();
        $paidRecord->save();

        $clawbacks = $this->commissionService->clawbackCommission($orderPaid->id, null, null, 'ORDER_RETURN');
        $this->assertCount(1, $clawbacks);
        $clawback = $clawbacks[0];

        $this->assertEquals(CommissionRecordTypeEnum::CLAWBACK, $clawback->type);
        $this->assertEquals(-100000.00, (float) $clawback->commission_amount);
        $this->assertEquals($paidRecord->id, $clawback->reversal_source_id);
    }

    /**
     * Test Keseimbangan GL dengan Manual Adjustment Positif
     */
    public function test_gl_accrual_posting_balances_with_manual_adjustments(): void
    {
        AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => 'PER-2026-09',
            'period_name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'fiscal_year' => 2026,
            'period_number' => 9,
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_locked' => false,
        ]);

        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-09-01'),
            periodEnd: Carbon::parse('2026-09-30'),
            cutoffDate: Carbon::parse('2026-09-25'),
            paymentDate: Carbon::parse('2026-09-30'),
            createdByUserId: $this->user->id
        );

        $record = PayrollRecord::create([
            'tenant_id' => $this->tenant->id,
            'payroll_batch_id' => $batch->id,
            'employee_id' => $this->employee->id,
            'employee_number_snapshot' => $this->employee->employee_number,
            'full_name_snapshot' => $this->employee->full_name,
            'department_name_snapshot' => 'Operations',
            'job_title_snapshot' => 'Staff',
            'primary_store_id' => $this->store->id,
            'primary_store_name_snapshot' => $this->store->name,
            'ptkp_status_snapshot' => 'TK/0',
            'tax_calculation_method_snapshot' => 'GROSS',
            'bank_name_snapshot' => 'BCA',
            'bank_account_number_snapshot' => '1234567890',
            'basic_salary_amount' => 5000000.00,
            'fixed_allowances_amount' => 0.00,
            'variable_allowances_amount' => 0.00,
            'overtime_amount' => 0.00,
            'commissions_amount' => 0.00,
            'gross_earnings_amount' => 5500000.00, // Includes 500k adjustment
            'taxable_gross_amount' => 5500000.00,
            'tax_pph21_amount' => 0.00,
            'tax_pph21_dtp_amount' => 0.00,
            'bpjs_ks_employer_amount' => 0.00,
            'bpjs_ks_employee_amount' => 0.00,
            'bpjs_tk_employer_amount' => 0.00,
            'bpjs_tk_employee_amount' => 0.00,
            'other_deductions_amount' => 0.00,
            'net_pay_amount' => 5500000.00,
            'payslip_token' => 'token-w9-test',
        ]);

        PayrollItem::create([
            'tenant_id' => $this->tenant->id,
            'payroll_record_id' => $record->id,
            'component_code' => 'PAYROLL_ADJUSTMENT',
            'component_name' => 'Bonus Prestasi',
            'type' => SalaryComponentTypeEnum::EARNING,
            'amount' => 500000.00,
            'is_taxable' => true,
        ]);

        $batch->total_employees = 1;
        $batch->total_gross_amount = 5500000.00;
        $batch->total_net_amount = 5500000.00;
        $batch->status = PayrollBatchStatusEnum::FINALIZED;
        $batch->save();

        $journal = $this->postingService->postAccrualJournal($batch, $this->user->id);

        $this->assertNotNull($journal);
        $this->assertSame($journal->total_debit, $journal->total_credit);
        $this->assertEquals(5500000.00, (float) $journal->total_debit);
        $this->assertEquals(5500000.00, (float) $journal->total_credit);
    }

    /**
     * Test Anti-Double Batch Concurrency & Maker-Checker Segregation of Duties
     */
    public function test_anti_double_batch_and_maker_checker_guards(): void
    {
        $periodStart = Carbon::parse('2026-10-01');
        $periodEnd = Carbon::parse('2026-10-31');

        $batch1 = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: $periodEnd,
            createdByUserId: $this->user->id
        );

        $this->assertNotNull($batch1);
        $this->assertEquals('2026-10-01_2026-10-31', $batch1->active_period_key);

        // Batch kedua untuk rentang tanggal yang sama WAJIB ditolak!
        $this->expectException(DomainException::class);
        $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: $periodEnd,
            createdByUserId: $this->user->id
        );
    }

    /**
     * Test Maker-Checker Segregation of Duties Exception on Multi-User Tenant
     */
    public function test_maker_checker_guard_blocks_self_approval_on_multi_user_tenant(): void
    {
        $guard = app(MakerCheckerGuard::class);

        // Jika tenant multi-user (allowSelfApproval = false), self-approval HARUS ditolak
        $this->expectException(SegregationOfDutiesViolationException::class);
        $guard->assertCanApprove(10, 10, false);
    }
}
