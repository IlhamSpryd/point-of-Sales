<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Jobs;

use App\Core\Context\TenantContext;
use App\Modules\Workforce\Enums\SalaryComponentTypeEnum;
use App\Modules\Workforce\Models\PayrollRecord;
use App\Platform\Tenancy\TenantAwareJob;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GeneratePayslipPdfJob extends TenantAwareJob
{
    public function __construct(
        public int $payrollRecordId,
        ?int $tenantId = null
    ) {
        $resolvedTenantId = $tenantId
            ?? app(TenantContext::class)->getTenantId()
            ?? (int) DB::table('payroll_records')->where('id', $payrollRecordId)->value('tenant_id');

        parent::__construct(tenantId: (int) $resolvedTenantId);
    }

    /**
     * Renders and persists an official employee payslip PDF under tenant-isolated storage.
     */
    protected function execute(): void
    {
        /** @var PayrollRecord|null $record */
        $record = PayrollRecord::with(['items', 'batch', 'tenant'])
            ->where('tenant_id', $this->tenantId)
            ->find($this->payrollRecordId);

        if (! $record) {
            return;
        }

        $earnings = [];
        $deductions = [];

        foreach ($record->items as $item) {
            $entry = [
                'label' => $item->component_name,
                'amount' => (float) $item->amount,
            ];
            if ($item->type === SalaryComponentTypeEnum::DEDUCTION) {
                $deductions[] = $entry;
            } else {
                $earnings[] = $entry;
            }
        }

        if (empty($earnings)) {
            $earnings = [
                ['label' => 'Basic Salary', 'amount' => (float) $record->basic_salary_amount],
                ['label' => 'Fixed Allowances', 'amount' => (float) $record->fixed_allowances_amount],
                ['label' => 'Variable Allowances', 'amount' => (float) $record->variable_allowances_amount],
                ['label' => 'Overtime', 'amount' => (float) $record->overtime_amount],
                ['label' => 'Commissions', 'amount' => (float) $record->commissions_amount],
            ];
            $earnings = array_values(array_filter($earnings, fn (array $row): bool => $row['amount'] > 0));
        }

        if (empty($deductions)) {
            $deductions = [
                ['label' => 'PPh 21 Income Tax', 'amount' => (float) $record->tax_pph21_amount],
                ['label' => 'BPJS Kesehatan (Employee)', 'amount' => (float) $record->bpjs_ks_employee_amount],
                ['label' => 'BPJS Ketenagakerjaan (Employee)', 'amount' => (float) $record->bpjs_tk_employee_amount],
                ['label' => 'Other Deductions', 'amount' => (float) $record->other_deductions_amount],
            ];
            $deductions = array_values(array_filter($deductions, fn (array $row): bool => $row['amount'] > 0));
        }

        $periodLabel = $record->batch !== null
            ? $record->batch->period_start_date->format('d M Y').' - '.$record->batch->period_end_date->format('d M Y')
            : '-';

        $pdfBytes = Pdf::loadView('pdf.workforce-payslip', [
            'companyName' => $record->tenant !== null ? $record->tenant->name : ($record->primary_store_name_snapshot ?: 'Apeiron Enterprise'),
            'period' => $periodLabel,
            'employeeName' => $record->full_name_snapshot,
            'employeeId' => $record->employee_number_snapshot,
            'department' => $record->department_name_snapshot,
            'position' => $record->job_title_snapshot,
            'ptkpStatus' => $record->ptkp_status_snapshot,
            'bankName' => $record->bank_name_snapshot,
            'bankAccount' => $record->bank_account_number_snapshot,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'totalEarnings' => (float) $record->gross_earnings_amount,
            'totalDeductions' => (float) $record->tax_pph21_amount + (float) $record->bpjs_ks_employee_amount + (float) $record->bpjs_tk_employee_amount + (float) $record->other_deductions_amount,
            'netPay' => (float) $record->net_pay_amount,
        ])->setPaper('a4')->output();

        $path = "payslips/tenant_{$this->tenantId}/payslip_{$record->id}.pdf";
        Storage::disk('local')->put($path, $pdfBytes);
    }
}
