<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Approval;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRecord;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollBatchService
{
    public function __construct(
        protected PayrollCalculationPipeline $pipeline
    ) {}

    /**
     * Buat Batch Penggajian Baru (DRAFT).
     */
    public function createBatch(
        int $tenantId,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        CarbonInterface $cutoffDate,
        CarbonInterface $paymentDate,
        int $createdByUserId,
        ?int $payrollPeriodId = null
    ): PayrollBatch {
        $yearMonth = $periodEnd->format('Ym');
        $randomSuffix = strtoupper(Str::random(4));
        $batchNumber = "PAY-{$yearMonth}-{$randomSuffix}";

        return PayrollBatch::create([
            'tenant_id' => $tenantId,
            'batch_number' => $batchNumber,
            'payroll_period_id' => $payrollPeriodId,
            'period_start_date' => $periodStart->toDateString(),
            'period_end_date' => $periodEnd->toDateString(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'payment_scheduled_date' => $paymentDate->toDateString(),
            'status' => PayrollBatchStatusEnum::DRAFT,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Eksekusi Perhitungan Gaji Seluruh Karyawan Aktif dalam Batch.
     *
     * @throws DomainException
     */
    public function executeCalculation(PayrollBatch $batch): PayrollBatch
    {
        if ($batch->status->isImmutable()) {
            throw new DomainException("Batch [{$batch->batch_number}] telah terkunci (status: {$batch->status->value}) dan tidak dapat dihitung ulang.");
        }

        $batch->status = PayrollBatchStatusEnum::CALCULATING;
        $batch->save();

        return DB::transaction(function () use ($batch) {
            // Bersihkan data kalkulasi sebelumnya jika ada
            PayrollRecord::where('payroll_batch_id', $batch->id)->delete();

            $employees = Employee::where('tenant_id', $batch->tenant_id)
                ->whereIn('employment_status', [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION])
                ->with(['department', 'jobTitle', 'taxProfile', 'bankAccount', 'bpjsProfile', 'branchAssignments.store'])
                ->get();

            $totalEmployees = 0;
            $totalGross = 0.00;
            $totalTax = 0.00;
            $totalBpjsEr = 0.00;
            $totalBpjsEe = 0.00;
            $totalOtherDeductions = 0.00;
            $totalNet = 0.00;

            foreach ($employees as $employee) {
                $calcResult = $this->pipeline->calculateEmployeePayroll(
                    $employee,
                    $batch->period_start_date,
                    $batch->period_end_date
                );

                if (! $calcResult) {
                    continue;
                }

                $primaryBranch = $employee->branchAssignments->firstWhere('is_primary', true);
                $storeId = $primaryBranch !== null ? (int) $primaryBranch->store_id : 1;
                $store = $primaryBranch !== null ? $primaryBranch->store : null;
                $storeName = $store !== null ? $store->name : 'Kantor Pusat';

                $deptName = $employee->department->name;
                $jobTitleName = $employee->jobTitle->name;

                $taxProfile = $employee->taxProfile;
                $ptkpSnapshot = $taxProfile !== null ? $taxProfile->ptkp_status->value : 'TK/0';
                $methodSnapshot = $taxProfile !== null ? $taxProfile->calculation_method->value : 'GROSS';

                $bankAccount = $employee->bankAccount;
                $bankName = $bankAccount !== null ? $bankAccount->bank_name : 'CASH';
                $bankAccNumber = $bankAccount !== null ? $bankAccount->account_number : '-';

                $record = PayrollRecord::create([
                    'tenant_id' => $batch->tenant_id,
                    'payroll_batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                    'employee_number_snapshot' => $employee->employee_number,
                    'full_name_snapshot' => $employee->full_name,
                    'department_name_snapshot' => $deptName,
                    'job_title_snapshot' => $jobTitleName,
                    'primary_store_id' => $storeId,
                    'primary_store_name_snapshot' => $storeName,
                    'ptkp_status_snapshot' => $ptkpSnapshot,
                    'tax_calculation_method_snapshot' => $methodSnapshot,
                    'bank_name_snapshot' => $bankName,
                    'bank_account_number_snapshot' => $bankAccNumber,
                    'basic_salary_amount' => $calcResult->baseSalaryAmount,
                    'fixed_allowances_amount' => $calcResult->fixedAllowanceAmount,
                    'variable_allowances_amount' => $calcResult->variableAllowanceAmount,
                    'overtime_amount' => $calcResult->overtimeAmount,
                    'commissions_amount' => $calcResult->commissionAmount,
                    'gross_earnings_amount' => $calcResult->grossEarningsAmount,
                    'taxable_gross_amount' => $calcResult->taxGrossAmount,
                    'tax_pph21_amount' => $calcResult->taxPph21Deducted,
                    'tax_pph21_dtp_amount' => $calcResult->taxPph21DtpAmount,
                    'bpjs_ks_employer_amount' => $calcResult->bpjsKsEmployer,
                    'bpjs_ks_employee_amount' => $calcResult->bpjsKsEmployee,
                    'bpjs_tk_employer_amount' => $calcResult->bpjsTkJhtEmployer + $calcResult->bpjsTkJpEmployer + $calcResult->bpjsTkJkkEmployer + $calcResult->bpjsTkJkmEmployer,
                    'bpjs_tk_employee_amount' => $calcResult->bpjsTkJhtEmployee + $calcResult->bpjsTkJpEmployee,
                    'other_deductions_amount' => $calcResult->otherDeductionsAmount,
                    'net_pay_amount' => $calcResult->netPayAmount,
                    'payslip_token' => Str::random(64),
                ]);

                foreach ($calcResult->items as $item) {
                    PayrollItem::create([
                        'tenant_id' => $batch->tenant_id,
                        'payroll_record_id' => $record->id,
                        'component_code' => $item->componentCode,
                        'component_name' => $item->componentName,
                        'type' => $item->type,
                        'amount' => $item->amount,
                        'is_taxable' => $item->isTaxable,
                        'statutory_rule_version_id' => $item->statutoryRuleVersionId,
                        'notes' => $item->notes,
                    ]);
                }

                $totalEmployees++;
                $totalGross += $calcResult->grossEarningsAmount;
                $totalTax += $calcResult->taxPph21Deducted;
                $totalBpjsEr += $calcResult->bpjsEmployerTotal;
                $totalBpjsEe += $calcResult->bpjsEmployeeTotal;
                $totalOtherDeductions += $calcResult->otherDeductionsAmount;
                $totalNet += $calcResult->netPayAmount;
            }

            $batch->total_employees = $totalEmployees;
            $batch->total_gross_amount = $totalGross;
            $batch->total_tax_amount = $totalTax;
            $batch->total_bpjs_employer = $totalBpjsEr;
            $batch->total_bpjs_employee = $totalBpjsEe;
            $batch->total_other_deductions = $totalOtherDeductions;
            $batch->total_net_amount = $totalNet;
            $batch->status = PayrollBatchStatusEnum::CALCULATED;
            $batch->save();

            return $batch;
        });
    }

    /**
     * Finalisasi Batch Penggajian (FINALIZED) dan Kunci Imutabilitas.
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function finalizeBatch(int $batchId, int $approverUserId, int $approvalId): PayrollBatch
    {
        /** @var Approval|null $approval */
        $approval = Approval::find($approvalId);
        if (! $approval) {
            throw new DomainException("Dokumen Otorisasi [ID: {$approvalId}] tidak ditemukan.");
        }

        if ($approval->action_code !== 'PAYROLL_EXECUTION') {
            throw ValidationException::withMessages([
                'approval_id' => "Aksi persetujuan harus bertipe 'PAYROLL_EXECUTION', ditemukan: {$approval->action_code}.",
            ]);
        }

        if ($approval->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'approval_id' => 'Dokumen persetujuan payroll belum berstatus APPROVED.',
            ]);
        }

        /** @var PayrollBatch $batch */
        $batch = PayrollBatch::findOrFail($batchId);

        if ($batch->status === PayrollBatchStatusEnum::FINALIZED) {
            throw new DomainException("Batch [{$batch->batch_number}] sudah dalam status FINALIZED.");
        }

        $batch->status = PayrollBatchStatusEnum::FINALIZED;
        $batch->approved_by_user_id = $approverUserId;
        $batch->finalized_at = now();
        $batch->save();

        return $batch;
    }

    /**
     * Akses slip gaji aman melalui token rahasia.
     */
    public function getPayslipByToken(string $token): PayrollRecord
    {
        return PayrollRecord::where('payslip_token', $token)
            ->with(['items', 'batch', 'employee.department', 'employee.jobTitle'])
            ->firstOrFail();
    }
}
