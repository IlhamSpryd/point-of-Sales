<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Services;

use App\Modules\Workforce\DTOs\PayrollCalculationItemDto;
use App\Modules\Workforce\DTOs\PayrollCalculationResultDto;
use App\Modules\Workforce\Enums\AttendanceStatusEnum;
use App\Modules\Workforce\Enums\CommissionRecordStatusEnum;
use App\Modules\Workforce\Enums\EmploymentStatusEnum;
use App\Modules\Workforce\Enums\OvertimeStatusEnum;
use App\Modules\Workforce\Enums\PayrollBatchStatusEnum;
use App\Modules\Workforce\Enums\SalaryComponentTypeEnum;
use App\Modules\Workforce\Enums\TaxCalculationMethodEnum;
use App\Modules\Workforce\Models\AttendanceRecord;
use App\Modules\Workforce\Models\CommissionRecord;
use App\Modules\Workforce\Models\Employee;
use App\Modules\Workforce\Models\EmployeeCompensation;
use App\Modules\Workforce\Models\OvertimeRecord;
use App\Modules\Workforce\Models\PayrollRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PayrollCalculationPipeline
{
    public function __construct(
        protected StatutoryBpjsService $bpjsService,
        protected StatutoryTaxService $taxService
    ) {}

    /**
     * Eksekusi Pipeline Penggajian Deterministik 16-Langkah untuk seorang karyawan.
     *
     * @param  array<string, mixed>  $extraContext
     */
    public function calculateEmployeePayroll(
        Employee $employee,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        array $extraContext = []
    ): ?PayrollCalculationResultDto {
        // Step 1: Evaluasi Kelayakan Karyawan (Active Employment Check)
        if (in_array($employee->employment_status, [EmploymentStatusEnum::TERMINATED, EmploymentStatusEnum::ARCHIVED], true)) {
            return null;
        }

        // Step 2: Pembekuan Data Master & Kompensasi (Snapshot Base & Fixed Allowances)
        $compensations = EmployeeCompensation::where('employee_id', $employee->id)
            ->activeOn($periodEnd)
            ->with('component')
            ->get();

        $baseSalaryRaw = 0.00;
        $fixedAllowances = 0.00;
        $variableAllowances = 0.00;

        /** @var array<int, PayrollCalculationItemDto> $items */
        $items = [];

        foreach ($compensations as $comp) {
            $component = $comp->component;
            $compCode = $component !== null ? $component->component_code : 'CUSTOM';
            $compName = $component !== null ? $component->component_name : 'Kompensasi';
            $compType = $component !== null ? $component->type : SalaryComponentTypeEnum::EARNING;
            $isFixed = $component !== null ? $component->is_fixed : true;
            $isTaxable = $component !== null ? $component->is_taxable : true;

            if ($compCode === 'BASE_SALARY') {
                $baseSalaryRaw += $comp->amount;
            } elseif ($compType === SalaryComponentTypeEnum::EARNING) {
                if ($isFixed) {
                    $fixedAllowances += $comp->amount;
                } else {
                    $variableAllowances += $comp->amount;
                }
            }
        }

        // Override jika ada di extraContext (misal untuk golden test cases terisolasi)
        if (isset($extraContext['base_salary'])) {
            $baseSalaryRaw = (float) $extraContext['base_salary'];
        }
        if (isset($extraContext['fixed_allowance'])) {
            $fixedAllowances = (float) $extraContext['fixed_allowance'];
        }
        if (isset($extraContext['variable_allowance'])) {
            $variableAllowances = (float) $extraContext['variable_allowance'];
        }

        // Step 3: Evaluasi Hari Kerja Kalender & Pro-Rata (Mid-month Join/Resign)
        $baseSalary = $baseSalaryRaw;
        $hireDate = Carbon::instance($employee->hire_date);
        if ($hireDate->gt($periodStart) && $hireDate->lte($periodEnd)) {
            $totalDaysInMonth = (int) $periodEnd->daysInMonth;
            $daysEmployed = $hireDate->diffInDays($periodEnd) + 1;
            $ratio = min(1.0, max(0.0, $daysEmployed / $totalDaysInMonth));
            $baseSalary = round($baseSalaryRaw * $ratio, 2);
        }

        if ($baseSalary > 0) {
            $items[] = new PayrollCalculationItemDto('BASE_SALARY', 'Gaji Pokok', SalaryComponentTypeEnum::EARNING, $baseSalary);
        }
        if ($fixedAllowances > 0) {
            $items[] = new PayrollCalculationItemDto('FIXED_ALLOWANCE', 'Tunjangan Tetap', SalaryComponentTypeEnum::EARNING, $fixedAllowances);
        }

        // Step 4: Snapshot Timesheet Kehadiran (Approved Hours, Tardiness & Days Worked)
        $attendanceDays = isset($extraContext['attendance_days'])
            ? (int) $extraContext['attendance_days']
            : AttendanceRecord::where('employee_id', $employee->id)
                ->whereBetween('clock_in_at', [$periodStart->startOfDay()->toDateTimeString(), $periodEnd->endOfDay()->toDateTimeString()])
                ->whereIn('status', [AttendanceStatusEnum::PRESENT, AttendanceStatusEnum::LATE, AttendanceStatusEnum::EARLY_LEAVE, AttendanceStatusEnum::CORRECTED])
                ->count();

        // Step 5: Kalkulasi Tunjangan Variabel (Makan & Transport Harian)
        if ($variableAllowances > 0) {
            $items[] = new PayrollCalculationItemDto('VARIABLE_ALLOWANCE', 'Tunjangan Variabel', SalaryComponentTypeEnum::EARNING, $variableAllowances);
        }

        // Step 6: Kalkulasi Upah Lembur PP 35/2021 (Approved Overtime Records)
        $overtimeAmount = isset($extraContext['overtime_amount'])
            ? (float) $extraContext['overtime_amount']
            : (float) OvertimeRecord::where('employee_id', $employee->id)
                ->whereBetween('overtime_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->where('status', OvertimeStatusEnum::APPROVED)
                ->sum('calculated_amount');

        if ($overtimeAmount > 0) {
            $items[] = new PayrollCalculationItemDto('OVERTIME_PAY', 'Upah Lembur PP 35/2021', SalaryComponentTypeEnum::EARNING, $overtimeAmount);
        }

        // Step 7: Kalkulasi Komisi Penjualan POS (Final Sales & Returns Reversals)
        $commissionAmount = isset($extraContext['commission_amount'])
            ? (float) $extraContext['commission_amount']
            : (float) CommissionRecord::where('employee_id', $employee->id)
                ->whereNull('payroll_batch_id')
                ->whereIn('status', [
                    CommissionRecordStatusEnum::APPROVED,
                    CommissionRecordStatusEnum::PENDING,
                    CommissionRecordStatusEnum::EARNED,
                    CommissionRecordStatusEnum::CLAWBACK,
                ])
                ->whereBetween('created_at', [$periodStart->toDateTimeString(), $periodEnd->toDateTimeString()])
                ->sum('commission_amount');

        if ($commissionAmount !== 0.0) {
            $items[] = new PayrollCalculationItemDto('SALES_COMMISSION', 'Komisi Penjualan POS', SalaryComponentTypeEnum::EARNING, $commissionAmount);
        }

        // Step 8: Penyesuaian Manual & Kasbon (Approved Payroll Adjustments & Loans)
        $manualAdjustments = (float) ($extraContext['adjustments'] ?? 0.00);
        $loanDeductions = (float) ($extraContext['loan_deductions'] ?? 0.00);
        $tardinessDeductions = (float) ($extraContext['tardiness_deductions'] ?? 0.00);

        if ($manualAdjustments !== 0.0) {
            $items[] = new PayrollCalculationItemDto('PAYROLL_ADJUSTMENT', 'Penyesuaian Manual Disetujui', SalaryComponentTypeEnum::EARNING, $manualAdjustments);
        }

        // Step 9: Perhitungan Akumulasi Penghasilan Kotor (Gross Earnings Calculation)
        $grossEarnings = $baseSalary + $fixedAllowances + $variableAllowances + $overtimeAmount + $commissionAmount + $manualAdjustments;

        // Step 10 & 11: Kalkulasi Iuran BPJS Ketenagakerjaan & BPJS Kesehatan
        $bpjsProfile = $employee->bpjsProfile;
        $jkkGrade = $bpjsProfile !== null ? (int) $bpjsProfile->jkk_risk_grade : 1;
        $jkkClass = $extraContext['jkk_class'] ?? "class_{$jkkGrade}";

        $isBpjsTkActive = $bpjsProfile !== null ? (bool) $bpjsProfile->is_bpjs_tk_active : true;
        $isBpjsKsActive = $bpjsProfile !== null ? (bool) $bpjsProfile->is_bpjs_ks_active : true;

        // Dasar upah tetap untuk BPJS = Gaji Pokok + Tunjangan Tetap
        $fixedWageBasis = $baseSalary + $fixedAllowances;

        $employeeAge = null;
        if (isset($extraContext['employee_age'])) {
            $employeeAge = (int) $extraContext['employee_age'];
        } elseif ($employee->birth_date !== null) {
            $employeeAge = (int) Carbon::parse($employee->birth_date)->diffInYears($periodEnd);
        }

        $bpjsContrib = $this->bpjsService->calculateContributions(
            effectiveWage: $fixedWageBasis,
            effectiveDate: $periodEnd,
            jkkRiskClass: (string) $jkkClass,
            jurisdiction: 'ID',
            employeeAge: $employeeAge
        );

        $bpjsKsEr = $isBpjsKsActive ? (float) $bpjsContrib['ks_employer'] : 0.00;
        $bpjsKsEe = $isBpjsKsActive ? (float) $bpjsContrib['ks_employee'] : 0.00;
        $bpjsJhtEr = $isBpjsTkActive ? (float) $bpjsContrib['jht_employer'] : 0.00;
        $bpjsJhtEe = $isBpjsTkActive ? (float) $bpjsContrib['jht_employee'] : 0.00;
        $bpjsJpEr = $isBpjsTkActive ? (float) $bpjsContrib['jp_employer'] : 0.00;
        $bpjsJpEe = $isBpjsTkActive ? (float) $bpjsContrib['jp_employee'] : 0.00;
        $bpjsJkkEr = $isBpjsTkActive ? (float) $bpjsContrib['jkk_employer'] : 0.00;
        $bpjsJkmEr = $isBpjsTkActive ? (float) $bpjsContrib['jkm_employer'] : 0.00;

        $totalBpjsEmployer = $bpjsKsEr + $bpjsJhtEr + $bpjsJpEr + $bpjsJkkEr + $bpjsJkmEr;
        $totalBpjsEmployee = $bpjsKsEe + $bpjsJhtEe + $bpjsJpEe;

        // Catat kontribusi BPJS perusahaan ke snapshot
        if ($bpjsKsEr > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_KS_ER', 'BPJS Kesehatan (Perusahaan 4%)', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $bpjsKsEr, true, (int) $bpjsContrib['ks_version_id']);
        }
        if ($bpjsJkkEr > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JKK_ER', 'BPJS TK JKK (Perusahaan)', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $bpjsJkkEr, true, (int) $bpjsContrib['tk_version_id']);
        }
        if ($bpjsJkmEr > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JKM_ER', 'BPJS TK JKM (Perusahaan 0.3%)', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $bpjsJkmEr, true, (int) $bpjsContrib['tk_version_id']);
        }
        if ($bpjsJhtEr > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JHT_ER', 'BPJS TK JHT (Perusahaan 3.7%)', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $bpjsJhtEr, false, (int) $bpjsContrib['tk_version_id']);
        }
        if ($bpjsJpEr > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JP_ER', 'BPJS TK JP (Perusahaan 2%)', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $bpjsJpEr, false, (int) $bpjsContrib['tk_version_id']);
        }

        // Catat potongan BPJS pekerja ke snapshot
        if ($bpjsKsEe > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_KS_EE', 'Iuran BPJS Kesehatan (Pekerja 1%)', SalaryComponentTypeEnum::DEDUCTION, $bpjsKsEe, false, (int) $bpjsContrib['ks_version_id']);
        }
        if ($bpjsJhtEe > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JHT_EE', 'Iuran BPJS TK JHT (Pekerja 2%)', SalaryComponentTypeEnum::DEDUCTION, $bpjsJhtEe, false, (int) $bpjsContrib['tk_version_id']);
        }
        if ($bpjsJpEe > 0) {
            $items[] = new PayrollCalculationItemDto('BPJS_TK_JP_EE', 'Iuran BPJS TK JP (Pekerja 1%)', SalaryComponentTypeEnum::DEDUCTION, $bpjsJpEe, false, (int) $bpjsContrib['tk_version_id']);
        }

        // Step 12: Kalkulasi Dasar Pengenaan Pajak Bruto PPh 21
        // Bruto Pajak = Gross Earnings + BPJS KS ER + JKK ER + JKM ER
        $taxGross = $grossEarnings + $bpjsKsEr + $bpjsJkkEr + $bpjsJkmEr;

        // Step 13: Eksekusi Engine Pajak PPh 21 (Metode TER / Pasal 17 Tahunan PMK 168/2023)
        $taxProfile = $employee->taxProfile;
        $ptkpStatus = $extraContext['ptkp_status'] ?? ($taxProfile !== null ? $taxProfile->ptkp_status->value : 'TK/0');
        $taxMethod = $extraContext['tax_method'] ?? ($taxProfile !== null ? $taxProfile->calculation_method : TaxCalculationMethodEnum::GROSS);
        if (is_string($taxMethod)) {
            $taxMethod = TaxCalculationMethodEnum::tryFrom($taxMethod) ?? TaxCalculationMethodEnum::GROSS;
        }
        $isDtpEligible = (bool) ($extraContext['is_dtp_eligible'] ?? ($taxProfile !== null ? $taxProfile->is_eligible_for_dtp : false));

        // Deteksi Masa Pajak Terakhir (Desember / Resign / Final Month) per PMK 168/2023
        $isFinalMonth = ($periodEnd->month === 12
            || $employee->employment_status === EmploymentStatusEnum::RESIGNED
            || ($employee->termination_date !== null && Carbon::parse($employee->termination_date)->between($periodStart, $periodEnd))
            || ! empty($extraContext['is_final_month']));

        $taxCalculated = 0.00;
        $taxDtp = 0.00;
        $taxDeducted = 0.00;
        $taxAllowance = 0.00;

        if ($isFinalMonth) {
            // Hitung akumulasi masa pajak 1 s.d. 11 tahun berjalan
            $currentYear = (int) $periodEnd->year;
            $priorRecords = PayrollRecord::where('employee_id', $employee->id)
                ->whereHas('batch', function ($q) use ($currentYear) {
                    $q->whereYear('period_end_date', $currentYear)
                        ->whereIn('status', [PayrollBatchStatusEnum::FINALIZED, PayrollBatchStatusEnum::PAID]);
                })
                ->get();

            $priorTaxesPaid = (float) ($extraContext['prior_taxes_paid'] ?? $priorRecords->sum('tax_pph21_amount'));
            $priorGross = (float) ($extraContext['prior_gross'] ?? $priorRecords->sum('taxable_gross_amount'));
            $priorJhtJpEe = (float) ($extraContext['prior_jht_jp_ee'] ?? $priorRecords->sum(fn ($r) => (float) $r->bpjs_tk_employee_amount));

            $annualGross = $priorGross + $taxGross;
            $annualJhtJpEe = $priorJhtJpEe + ($bpjsJhtEe + $bpjsJpEe);

            $p17Result = $this->taxService->calculateAnnualPasal17Tax(
                annualGross: $annualGross,
                annualJhtJpEmployee: $annualJhtJpEe,
                ptkpStatus: (string) $ptkpStatus,
                priorTaxesPaid: $priorTaxesPaid,
                effectiveDate: $periodEnd,
                jurisdiction: 'ID'
            );

            $taxCalculated = (float) $p17Result['total_annual_tax'];
            $decemberTax = (float) $p17Result['december_tax'];
            $taxDeducted = $decemberTax;

            if ($taxDeducted > 0) {
                $items[] = new PayrollCalculationItemDto('TAX_PPH21', 'Pajak PPh 21 Pasal 17 Tahunan', SalaryComponentTypeEnum::DEDUCTION, $taxDeducted, false);
            } elseif ($taxDeducted < 0) {
                // Koreksi #2: Lebih potong Desember (PPh 21 < 0) -> kembalikan ke THP karyawan
                $refundAmount = abs($taxDeducted);
                $items[] = new PayrollCalculationItemDto('TAX_PPH21_REFUND', 'Pengembalian Lebih Potong PPh 21 Desember', SalaryComponentTypeEnum::EARNING, $refundAmount, false);
            }
        } else {
            $taxResult = $this->taxService->calculateMonthlyTerTax(
                taxGrossAmount: $taxGross,
                ptkpStatus: (string) $ptkpStatus,
                effectiveDate: $periodEnd,
                isDtpEligible: $isDtpEligible,
                taxMethod: $taxMethod
            );

            $taxCalculated = (float) $taxResult['calculated_tax'];
            $taxDtp = (float) $taxResult['dtp_amount'];
            $taxDeducted = (float) $taxResult['deducted_tax'];
            $taxAllowance = (float) ($taxResult['tax_allowance'] ?? 0.00);

            // Koreksi Gross-Up: Masukkan tax_allowance ke grossEarnings + PayrollItem TAX_ALLOWANCE
            if ($taxMethod === TaxCalculationMethodEnum::GROSS_UP && $taxAllowance > 0) {
                $grossEarnings += $taxAllowance;
                $taxGross += $taxAllowance;
                $items[] = new PayrollCalculationItemDto('TAX_ALLOWANCE', 'Tunjangan Pajak (Gross-Up)', SalaryComponentTypeEnum::EARNING, $taxAllowance, true);
            }

            if ($taxDtp > 0) {
                $items[] = new PayrollCalculationItemDto('TAX_PPH21_DTP', 'Insentif PPh 21 DTP PMK 105/2025', SalaryComponentTypeEnum::EMPLOYER_CONTRIBUTION, $taxDtp, false, (int) $taxResult['ter_version_id']);
            }

            if ($taxDeducted > 0) {
                $items[] = new PayrollCalculationItemDto('TAX_PPH21', 'Pajak PPh 21 TER', SalaryComponentTypeEnum::DEDUCTION, $taxDeducted, false, (int) $taxResult['ter_version_id']);
            }
        }

        // Step 14: Kalkulasi Total Potongan Karyawan (Total Deductions)
        $otherDeductions = $loanDeductions + $tardinessDeductions;
        if ($loanDeductions > 0) {
            $items[] = new PayrollCalculationItemDto('LOAN_INTERNAL', 'Cicilan Pinjaman Internal', SalaryComponentTypeEnum::DEDUCTION, $loanDeductions);
        }
        if ($tardinessDeductions > 0) {
            $items[] = new PayrollCalculationItemDto('TARDINESS_PENALTY', 'Denda Keterlambatan Absensi', SalaryComponentTypeEnum::DEDUCTION, $tardinessDeductions);
        }

        // Jika taxDeducted < 0 (lebih potong), tidak boleh menjadi potongan (malah menambah THP)
        $positiveTaxDeduction = $taxDeducted > 0 ? $taxDeducted : 0.00;
        $totalDeductions = $positiveTaxDeduction + $totalBpjsEmployee + $otherDeductions;

        // Step 15: Perhitungan Gaji Bersih Final (Net Take-Home Pay)
        $taxRefundAddon = $taxDeducted < 0 ? abs($taxDeducted) : 0.00;
        $netPay = round($grossEarnings - $totalDeductions + $taxRefundAddon, 0);

        // Step 16: Rekonsiliasi, Pembulatan Rupiah & Pembekuan Snapshot
        return new PayrollCalculationResultDto(
            employeeId: $employee->id,
            baseSalaryAmount: $baseSalary,
            fixedAllowanceAmount: $fixedAllowances,
            variableAllowanceAmount: $variableAllowances,
            overtimeAmount: $overtimeAmount,
            commissionAmount: $commissionAmount,
            grossEarningsAmount: $grossEarnings,
            taxGrossAmount: $taxGross,
            taxPph21Calculated: $taxCalculated,
            taxPph21DtpAmount: $taxDtp,
            taxPph21Deducted: $taxDeducted,
            bpjsEmployerTotal: $totalBpjsEmployer,
            bpjsEmployeeTotal: $totalBpjsEmployee,
            bpjsKsEmployer: $bpjsKsEr,
            bpjsKsEmployee: $bpjsKsEe,
            bpjsTkJhtEmployer: $bpjsJhtEr,
            bpjsTkJhtEmployee: $bpjsJhtEe,
            bpjsTkJpEmployer: $bpjsJpEr,
            bpjsTkJpEmployee: $bpjsJpEe,
            bpjsTkJkkEmployer: $bpjsJkkEr,
            bpjsTkJkmEmployer: $bpjsJkmEr,
            otherDeductionsAmount: $otherDeductions,
            totalDeductionsAmount: $totalDeductions,
            netPayAmount: $netPay,
            items: $items
        );
    }
}
