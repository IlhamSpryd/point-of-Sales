<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Approval;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\OvertimeDayTypeEnum;
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
use App\Modules\HR\Services\OvertimeCalculationService;
use App\Modules\HR\Services\PayrollBatchService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Modules\HR\Services\StatutoryTaxService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 5 HR, Workforce & Payroll Management Feature Tests
 *
 * Menguji kepatuhan kriteria penerimaan Gelombang 5:
 * - T-HRM-01: Kalkulasi lembur PP 35/2021: gaji Rp 5.190.000 (upah/jam = 30.000), lembur 3 jam hari kerja = Rp 45.000 + Rp 120.000 = Rp 165.000.
 * - T-HRM-02: PPh 21 TER K/1 (Kategori B) bruto Rp 8.000.000 dipotong sesuai tabel tarif resmi PMK 168/2023 (1% = Rp 80.000).
 * - T-HRM-03: Posting GL penggajian seimbang (Debit beban gaji, lembur, BPJS perusahaan == Kredit hutang PPh 21, hutang BPJS, kas bank/hutang gaji).
 */
class Wave5HrPayrollTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected AccountingPeriod $period;

    protected OvertimeCalculationService $overtimeService;

    protected StatutoryTaxService $taxService;

    protected PayrollBatchService $batchService;

    protected PayrollPostingService $postingService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        // Seed parameter statuter (PPh 21 TER PMK 168/2023, BPJS, PTKP)
        $this->seed(StatutoryRuleVersionsSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Wave 5 HR Corporation',
            'code' => 'W5-HR-CORP',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Jakarta Sentra',
            'code' => 'JKT-01',
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);

        // Seed Chart of Accounts untuk tenant ini (Modul Keuangan GL)
        (new ChartOfAccountsSeeder)->seedForTenant($this->tenant->id);

        // Buat open accounting period untuk bulan Oktober 2026
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
            'department_code' => 'OPS-W5',
            'name' => 'Divisi Operasional',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'OPS-LEAD',
            'name' => 'Team Leader Operasional',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-W5-001',
            'full_name' => 'Rian Hidayat',
            'email' => 'rian.hidayat@wave5.corp',
            'phone_number' => '081288887777',
            'national_id_number' => '3171010101910001',
            'tax_id_number' => '091234567011000',
            'birth_date' => '1992-06-15',
            'hire_date' => '2023-01-01',
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $this->overtimeService = app(OvertimeCalculationService::class);
        $this->taxService = app(StatutoryTaxService::class);
        $this->batchService = app(PayrollBatchService::class);
        $this->postingService = app(PayrollPostingService::class);
    }

    /**
     * T-HRM-01: Kalkulasi lembur PP No. 35 Tahun 2021:
     * Gaji Rp 5.190.000 -> Upah sejam = 5.190.000 / 173 = Rp 30.000.
     * Lembur 3 jam hari kerja (WORKDAY):
     * - Jam ke-1: 1.0 jam * 1.5x = 1.5 jam bayar -> Rp 45.000
     * - Jam ke-2 & ke-3: 2.0 jam * 2.0x = 4.0 jam bayar -> Rp 120.000
     * - Total jam bayar = 5.5 jam bayar
     * - Total nominal lembur = Rp 165.000 (Rp 45.000 + Rp 120.000).
     */
    public function test_t_hrm_01_overtime_calculation_follows_indonesian_pp35_2021_regulations(): void
    {
        $monthlyWage = 5190000.00;
        $overtimeHours = 3.0;

        // 1. Hitung basis upah per jam standar Indonesia (1/173 x Gaji Sebulan)
        $hourlyRate = $this->overtimeService->calculateHourlyRateBasis($monthlyWage);
        $this->assertEquals(30000.00, $hourlyRate, 'Upah per jam dasar harus tepat Rp 30.000 (5.190.000 / 173).');

        // 2. Hitung jam bayar untuk 3 jam lembur di hari kerja biasa (WORKDAY)
        $payableHours = $this->overtimeService->calculatePayableHours(
            actualHours: $overtimeHours,
            dayType: OvertimeDayTypeEnum::WORKDAY
        );
        $this->assertEquals(5.5, $payableHours, '3 jam lembur hari kerja harus menghasilkan 5.5 jam bayar (1.5 + 4.0).');

        // 3. Verifikasi rincian sub-komponen jam ke-1 dan jam ke-2+
        $hour1Payable = $this->overtimeService->calculatePayableHours(1.0, OvertimeDayTypeEnum::WORKDAY);
        $hour1Amount = $this->overtimeService->calculateOvertimeAmount($hour1Payable, $hourlyRate);
        $this->assertEquals(1.5, $hour1Payable);
        $this->assertEquals(45000.00, $hour1Amount, 'Jam ke-1 harus bernilai Rp 45.000 (1.5 x 30.000).');

        $remainingPayable = $payableHours - $hour1Payable; // 4.0 jam bayar
        $remainingAmount = $this->overtimeService->calculateOvertimeAmount($remainingPayable, $hourlyRate);
        $this->assertEquals(4.0, $remainingPayable);
        $this->assertEquals(120000.00, $remainingAmount, 'Jam ke-2 & ke-3 harus bernilai Rp 120.000 (4.0 x 30.000).');

        // 4. Hitung total nominal uang lembur
        $totalOvertimeAmount = $this->overtimeService->calculateOvertimeAmount($payableHours, $hourlyRate);
        $this->assertEquals(165000.00, $totalOvertimeAmount, 'Total uang lembur 3 jam harus tepat Rp 165.000 (45.000 + 120.000).');
        $this->assertEquals($hour1Amount + $remainingAmount, $totalOvertimeAmount);
    }

    /**
     * T-HRM-02: PPh 21 TER K/1 (Kategori B) bruto Rp 8.000.000 dipotong sesuai tabel tarif resmi PMK 168/2023.
     * Status PTKP K/1 dipetakan ke Kategori TER B.
     * Pada Kategori B, rentang bruto Rp 7.300.000 s/d Rp 9.200.000 dikenakan tarif 1.00% (0.01).
     * Pajak terutang = Rp 8.000.000 x 1% = Rp 80.000.
     */
    public function test_t_hrm_02_pph21_ter_withholding_matches_pmk168_2023_official_brackets(): void
    {
        $grossSalary = 8000000.00;
        $ptkpStatus = 'K/1';
        $effectiveDate = '2026-10-01';

        $terResult = $this->taxService->calculateMonthlyTerTax(
            taxGrossAmount: $grossSalary,
            ptkpStatus: $ptkpStatus,
            effectiveDate: $effectiveDate,
            isDtpEligible: false,
            taxMethod: TaxCalculationMethodEnum::GROSS
        );

        // 1. Verifikasi pemetaan kategori TER: K/1 wajib masuk Kategori B
        $this->assertEquals('B', $terResult['category'], 'Status PTKP K/1 wajib dipetakan ke Kategori TER B.');

        // 2. Verifikasi tarif TER PMK 168/2023 untuk bruto 8.000.000 pada Kategori B adalah 1% (0.0100)
        $this->assertEquals(0.0100, (float) $terResult['rate'], 'Tarif TER Kategori B untuk bruto Rp 8.000.000 harus 1.00%.');

        // 3. Verifikasi nominal pemotongan pajak PPh 21: 8.000.000 * 1% = 80.000
        $this->assertEquals(80000.00, (float) $terResult['calculated_tax'], 'PPh 21 terutang harus tepat Rp 80.000.');
        $this->assertEquals(80000.00, (float) $terResult['deducted_tax'], 'PPh 21 yang dipotong harus tepat Rp 80.000.');
        $this->assertEquals(0.00, (float) $terResult['dtp_amount']);
    }

    /**
     * T-HRM-03: Posting GL penggajian seimbang:
     * Debit beban gaji, beban lembur, beban BPJS perusahaan == Kredit hutang PPh 21, hutang BPJS, kas bank/hutang gaji.
     */
    public function test_t_hrm_03_payroll_batch_approval_posts_balanced_general_ledger_journal_entry(): void
    {
        Carbon::setTestNow('2026-10-31 23:59:59');

        // Konfigurasi profil pajak K/1
        EmployeeTaxProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'ptkp_status' => PtkpStatusEnum::K_1,
            'calculation_method' => TaxCalculationMethodEnum::GROSS,
            'is_eligible_for_dtp' => false,
            'effective_from' => '2024-01-01',
        ]);

        // Konfigurasi profil BPJS
        EmployeeBpjsProfile::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'jkk_risk_grade' => 2,
            'is_bpjs_tk_active' => true,
            'is_bpjs_ks_active' => true,
        ]);

        // Konfigurasi gaji pokok Rp 8.000.000
        $salaryComp = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE-SAL-W5',
            'component_name' => 'Gaji Pokok Karyawan',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'salary_component_id' => $salaryComp->id,
            'amount' => 8000000.00,
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // 1. Buat Batch Penggajian Oktober 2026
        $batch = $this->batchService->createBatch(
            tenantId: $this->tenant->id,
            periodStart: Carbon::parse('2026-10-01'),
            periodEnd: Carbon::parse('2026-10-31'),
            cutoffDate: Carbon::parse('2026-10-25'),
            paymentDate: Carbon::parse('2026-10-31'),
            createdByUserId: $this->user->id
        );

        // 2. Eksekusi kalkulasi batch
        $batch = $this->batchService->executeCalculation($batch);

        // 3. Setujui dan finalisasi batch penggajian
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

        // 4. Posting jurnal akrual penggajian ke General Ledger
        $journal = $this->postingService->postAccrualJournal($finalizedBatch, $this->user->id);

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertGreaterThan(0, $journal->id);
        $this->assertEquals($this->tenant->id, $journal->tenant_id);

        // 5. Verifikasi Keseimbangan Akuntansi (Accounting Balance Invariant: Debit == Credit)
        $debitStr = is_numeric($journal->total_debit) ? (string) $journal->total_debit : '0.00';
        $creditStr = is_numeric($journal->total_credit) ? (string) $journal->total_credit : '0.00';
        $this->assertTrue(
            bccomp($debitStr, $creditStr, 2) === 0,
            "Total debit ({$journal->total_debit}) harus tepat sama dengan total kredit ({$journal->total_credit})."
        );
        $this->assertGreaterThan(0, (float) $journal->total_debit);

        // 6. Verifikasi relasi batch penggajian tertaut ke jurnal GL
        $finalizedBatch->refresh();
        $this->assertEquals($journal->id, $finalizedBatch->gl_accrual_journal_id);

        // 7. Verifikasi baris-baris jurnal akrual
        $lines = JournalLine::withoutTenantScope()->where('journal_entry_id', $journal->id)->get();
        $this->assertGreaterThanOrEqual(4, $lines->count());

        $hasSalaryDebit = $lines->contains(fn (JournalLine $l) => is_numeric($l->debit_amount) && bccomp((string) $l->debit_amount, '8000000.00', 2) === 0);
        $hasBpjsEmployerDebit = $lines->contains(fn (JournalLine $l) => (float) $l->debit_amount > 0 && str_contains($l->description ?? '', 'BPJS'));
        $hasPayrollPayableCredit = $lines->contains(fn (JournalLine $l) => (float) $l->credit_amount > 0 && str_contains($l->description ?? '', 'Utang Gaji'));
        $hasPph21Credit = $lines->contains(fn (JournalLine $l) => (float) $l->credit_amount > 0 && str_contains($l->description ?? '', 'PPh 21'));

        $this->assertTrue($hasSalaryDebit, 'Jurnal wajib mendebet Beban Gaji.');
        $this->assertTrue($hasBpjsEmployerDebit, 'Jurnal wajib mendebet Beban BPJS Pemberi Kerja.');
        $this->assertTrue($hasPayrollPayableCredit, 'Jurnal wajib mengkredit Utang Gaji (Net Pay).');
        $this->assertTrue($hasPph21Credit, 'Jurnal wajib mengkredit Utang PPh 21.');
    }
}
