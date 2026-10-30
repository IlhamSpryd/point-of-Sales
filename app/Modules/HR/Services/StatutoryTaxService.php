<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use Carbon\CarbonInterface;

class StatutoryTaxService
{
    public function __construct(
        protected StatutoryRuleRegistryService $registryService
    ) {}

    /**
     * Hitung PPh 21 Bulanan Skema Tarif Efektif Rata-Rata (TER) PP 58/2023 & PMK 168/2023.
     *
     * @return array<string, mixed>
     */
    public function calculateMonthlyTerTax(
        float $taxGrossAmount,
        string $ptkpStatus,
        CarbonInterface|string $effectiveDate,
        bool $isDtpEligible = false,
        TaxCalculationMethodEnum $taxMethod = TaxCalculationMethodEnum::GROSS,
        string $jurisdiction = 'ID'
    ): array {
        $terData = $this->registryService->getTerParameters($effectiveDate, $jurisdiction);
        $rules = $terData['rules'];
        $mapping = $rules['category_mapping'] ?? [];

        $ptkpNormalized = str_replace('_', '/', $ptkpStatus);

        // 1. Tentukan Kategori TER (A, B, atau C)
        $category = 'A';
        if (in_array($ptkpNormalized, $mapping['B'] ?? [], true) || in_array($ptkpStatus, $mapping['B'] ?? [], true)) {
            $category = 'B';
        } elseif (in_array($ptkpNormalized, $mapping['C'] ?? [], true) || in_array($ptkpStatus, $mapping['C'] ?? [], true)) {
            $category = 'C';
        }

        $bracketsKey = 'brackets_'.strtolower($category);
        $brackets = $rules[$bracketsKey] ?? [];

        // 2. Evaluasi lapisan tarif TER
        $rate = 0.00;
        foreach ($brackets as $bracket) {
            $min = (float) $bracket['min'];
            $max = $bracket['max'] !== null ? (float) $bracket['max'] : null;

            if ($taxGrossAmount > $min && ($max === null || $taxGrossAmount <= $max)) {
                $rate = (float) $bracket['rate'];
                break;
            }
        }

        $calculatedTax = round($taxGrossAmount * $rate, 0);

        // 3. Perlakuan DTP (Ditanggung Pemerintah PMK 105/2025)
        $dtpAmount = 0.00;
        $deductedTax = $calculatedTax;

        if ($isDtpEligible && $taxGrossAmount <= 10000000.00) {
            $dtpAmount = $calculatedTax;
            $deductedTax = 0.00;
        }

        // 4. Perlakuan Metode Pemotongan (Gross vs Gross-Up vs Net)
        $taxAllowance = 0.00;
        if ($taxMethod === TaxCalculationMethodEnum::GROSS_UP && ! $isDtpEligible) {
            // Hitung tunjangan pajak iteratif deterministik
            $taxAllowance = $this->calculateGrossUpAllowance($taxGrossAmount, $category, $brackets);
            $deductedTax = $taxAllowance;
        }

        return [
            'ter_version_id' => $terData['version_id'],
            'category' => $category,
            'rate' => $rate,
            'tax_gross' => $taxGrossAmount,
            'calculated_tax' => $calculatedTax,
            'dtp_amount' => $dtpAmount,
            'deducted_tax' => $deductedTax,
            'tax_allowance' => $taxAllowance,
        ];
    }

    /**
     * Hitung PPh 21 Tahunan / Masa Pajak Desember Skema Progresif Pasal 17 UU HPP.
     *
     * @return array<string, mixed>
     */
    public function calculateAnnualPasal17Tax(
        float $annualGross,
        float $annualJhtJpEmployee,
        string $ptkpStatus,
        float $priorTaxesPaid,
        CarbonInterface|string $effectiveDate,
        string $jurisdiction = 'ID'
    ): array {
        $p17Data = $this->registryService->getPasal17Parameters($effectiveDate, $jurisdiction);
        $p17Rules = $p17Data['rules'];

        $ptkpData = $this->registryService->getPtkpValues($effectiveDate, $jurisdiction);
        $ptkpValues = $ptkpData['values'];
        $ptkpAmount = (float) ($ptkpValues[$ptkpStatus] ?? 54000000.00);

        // Biaya Jabatan: 5% dari Bruto, maks Rp6.000.000 per tahun
        $biayaJabatanRate = (float) ($p17Rules['biaya_jabatan']['rate'] ?? 0.05);
        $biayaJabatanMaxAnnual = (float) ($p17Rules['biaya_jabatan']['max_annual'] ?? 6000000.00);
        $biayaJabatan = min(round($annualGross * $biayaJabatanRate, 2), $biayaJabatanMaxAnnual);

        // Penghasilan Neto Setahun
        $netIncome = max(0.0, $annualGross - $biayaJabatan - $annualJhtJpEmployee);

        // Penghasilan Kena Pajak (PKP) dibulatkan ke bawah ke ribuan terdekat
        $pkpRaw = max(0.0, $netIncome - $ptkpAmount);
        $pkp = floor($pkpRaw / 1000) * 1000;

        // Hitung Pajak Progresif 5 Lapisan
        $brackets = $p17Rules['brackets'] ?? [];
        $totalAnnualTax = 0.00;
        $remainingPkp = $pkp;

        foreach ($brackets as $bracket) {
            if ($remainingPkp <= 0) {
                break;
            }

            $min = (float) $bracket['min'];
            $max = $bracket['max'] !== null ? (float) $bracket['max'] : null;
            $rate = (float) $bracket['rate'];

            $layerWidth = $max !== null ? ($max - $min) : $remainingPkp;
            $taxableInLayer = min($remainingPkp, $layerWidth);

            $totalAnnualTax += round($taxableInLayer * $rate, 2);
            $remainingPkp -= $taxableInLayer;
        }

        // PPh 21 Masa Desember = Pajak Tahunan - Pajak yang sudah dipotong Jan-Nov
        $decemberTax = round($totalAnnualTax - $priorTaxesPaid, 2);

        return [
            'annual_gross' => $annualGross,
            'biaya_jabatan' => $biayaJabatan,
            'net_income' => $netIncome,
            'ptkp_amount' => $ptkpAmount,
            'pkp' => $pkp,
            'total_annual_tax' => $totalAnnualTax,
            'prior_taxes_paid' => $priorTaxesPaid,
            'december_tax' => $decemberTax,
            'is_refund' => $decemberTax < 0,
        ];
    }

    /**
     * Hitung tunjangan pajak gross-up secara iteratif hingga konvergen (< Rp1 selisih).
     *
     * @param  array<int, array{min: float|int, max: float|int|null, rate: float|int}>  $brackets
     */
    protected function calculateGrossUpAllowance(float $taxGross, string $category, array $brackets): float
    {
        $allowance = 0.00;
        for ($i = 0; $i < 10; $i++) {
            $augmentedGross = $taxGross + $allowance;
            $rate = 0.00;
            foreach ($brackets as $bracket) {
                $min = (float) $bracket['min'];
                $max = $bracket['max'] !== null ? (float) $bracket['max'] : null;
                if ($augmentedGross > $min && ($max === null || $augmentedGross <= $max)) {
                    $rate = (float) $bracket['rate'];
                    break;
                }
            }
            $targetTax = round($augmentedGross * $rate, 2);
            if (abs($targetTax - $allowance) < 1.00) {
                return $targetTax;
            }
            $allowance = $targetTax;
        }

        return $allowance;
    }
}
