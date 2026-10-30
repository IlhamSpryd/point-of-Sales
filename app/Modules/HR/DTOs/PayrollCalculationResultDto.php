<?php

declare(strict_types=1);

namespace App\Modules\HR\DTOs;

readonly class PayrollCalculationResultDto
{
    /**
     * @param  array<int, PayrollCalculationItemDto>  $items
     */
    public function __construct(
        public int $employeeId,
        public float $baseSalaryAmount,
        public float $fixedAllowanceAmount,
        public float $variableAllowanceAmount,
        public float $overtimeAmount,
        public float $commissionAmount,
        public float $grossEarningsAmount,
        public float $taxGrossAmount,
        public float $taxPph21Calculated,
        public float $taxPph21DtpAmount,
        public float $taxPph21Deducted,
        public float $bpjsEmployerTotal,
        public float $bpjsEmployeeTotal,
        public float $bpjsKsEmployer,
        public float $bpjsKsEmployee,
        public float $bpjsTkJhtEmployer,
        public float $bpjsTkJhtEmployee,
        public float $bpjsTkJpEmployer,
        public float $bpjsTkJpEmployee,
        public float $bpjsTkJkkEmployer,
        public float $bpjsTkJkmEmployer,
        public float $otherDeductionsAmount,
        public float $totalDeductionsAmount,
        public float $netPayAmount,
        public array $items = []
    ) {}
}
