<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use Carbon\CarbonInterface;

class StatutoryBpjsService
{
    public function __construct(
        protected StatutoryRuleRegistryService $registryService
    ) {}

    /**
     * Hitung iuran BPJS Ketenagakerjaan dan Kesehatan lengkap berdasarkan regulasi berversi.
     *
     * @return array<string, mixed>
     */
    public function calculateContributions(
        float $effectiveWage,
        CarbonInterface|string $effectiveDate,
        string $jkkRiskClass = 'class_1',
        string $jurisdiction = 'ID'
    ): array {
        $params = $this->registryService->getBpjsParameters($effectiveDate, $jurisdiction);
        $tkParams = $params['tk'];
        $ksParams = $params['ks'];

        // 1. BPJS Kesehatan (Plafon Rp12.000.000)
        $ksWageCap = (float) ($ksParams['wage_cap'] ?? 12000000.00);
        $ksWageBasis = min($effectiveWage, $ksWageCap);

        $ksEmployerRate = (float) ($ksParams['employer_rate'] ?? 0.040);
        $ksEmployeeRate = (float) ($ksParams['employee_rate'] ?? 0.010);

        $ksEmployer = round($ksWageBasis * $ksEmployerRate, 2);
        $ksEmployee = round($ksWageBasis * $ksEmployeeRate, 2);

        // 2. BPJS TK - JHT (Tanpa Plafon)
        $jhtEmployerRate = (float) ($tkParams['jht_employer_rate'] ?? 0.037);
        $jhtEmployeeRate = (float) ($tkParams['jht_employee_rate'] ?? 0.020);

        $jhtEmployer = round($effectiveWage * $jhtEmployerRate, 2);
        $jhtEmployee = round($effectiveWage * $jhtEmployeeRate, 2);

        // 3. BPJS TK - JP (Plafon Rp11.086.300 per 2026)
        $jpWageCap = (float) ($tkParams['jp_wage_cap'] ?? 11086300.00);
        $jpWageBasis = min($effectiveWage, $jpWageCap);

        $jpEmployerRate = (float) ($tkParams['jp_employer_rate'] ?? 0.020);
        $jpEmployeeRate = (float) ($tkParams['jp_employee_rate'] ?? 0.010);

        $jpEmployer = round($jpWageBasis * $jpEmployerRate, 2);
        $jpEmployee = round($jpWageBasis * $jpEmployeeRate, 2);

        // 4. BPJS TK - JKK (Tanpa Plafon, Kelompok Risiko)
        $jkkRates = $tkParams['jkk_rates'] ?? [];
        $jkkRate = (float) ($jkkRates[$jkkRiskClass] ?? 0.0024);
        $jkkEmployer = round($effectiveWage * $jkkRate, 2);

        // 5. BPJS TK - JKM (Tanpa Plafon, 0.30%)
        $jkmRate = (float) ($tkParams['jkm_rate'] ?? 0.0030);
        $jkmEmployer = round($effectiveWage * $jkmRate, 2);

        // Total
        $totalEmployer = $ksEmployer + $jhtEmployer + $jpEmployer + $jkkEmployer + $jkmEmployer;
        $totalEmployee = $ksEmployee + $jhtEmployee + $jpEmployee;

        return [
            'tk_version_id' => $params['tk_version_id'],
            'ks_version_id' => $params['ks_version_id'],
            'ks_employer' => $ksEmployer,
            'ks_employee' => $ksEmployee,
            'jht_employer' => $jhtEmployer,
            'jht_employee' => $jhtEmployee,
            'jp_employer' => $jpEmployer,
            'jp_employee' => $jpEmployee,
            'jkk_employer' => $jkkEmployer,
            'jkm_employer' => $jkmEmployer,
            'total_employer' => $totalEmployer,
            'total_employee' => $totalEmployee,
            'subject_to_tax_gross' => $ksEmployer + $jkkEmployer + $jkmEmployer, // Komponen ER yang menambah bruto pajak PPh 21
        ];
    }
}
