<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\TaxTypeEnum;
use DomainException;

class RegionalTaxEngineService
{
    public const COUNTRY_INDONESIA = 'ID';
    public const COUNTRY_MALAYSIA = 'MY';
    public const COUNTRY_SINGAPORE = 'SG';
    public const COUNTRY_PHILIPPINES = 'PH';

    /**
     * Memvalidasi format Nomor Pokok Wajib Pajak / Nomor Registrasi Pajak regional Asia Tenggara.
     */
    public function validateTaxId(string $countryCode, string $taxId): bool
    {
        $country = strtoupper(trim($countryCode));
        $clean = trim($taxId);

        return match ($country) {
            self::COUNTRY_INDONESIA => $this->validateIndonesiaNpwp($clean),
            self::COUNTRY_MALAYSIA => $this->validateMalaysiaSst($clean),
            self::COUNTRY_SINGAPORE => $this->validateSingaporeGst($clean),
            self::COUNTRY_PHILIPPINES => $this->validatePhilippinesTin($clean),
            default => throw new DomainException("Unsupported country code for regional tax validation: {$country}"),
        };
    }

    protected function validateIndonesiaNpwp(string $taxId): bool
    {
        $digits = preg_replace('/\D/', '', $taxId);
        $len = strlen($digits);

        return $len === 15 || $len === 16;
    }

    protected function validateMalaysiaSst(string $taxId): bool
    {
        // Format SST Malaysia: W10-1808-32000000 atau format alfanumerik 15-17 karakter
        $pattern = '/^[A-Z0-9]{3}-?[0-9]{4}-?[0-9]{7,8}$/i';

        return preg_match($pattern, $taxId) === 1;
    }

    protected function validateSingaporeGst(string $taxId): bool
    {
        // Format UEN/GST Singapura: 9 atau 10 karakter alfanumerik, e.g. 201812345M, T08LL1234A, M90312345X
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $taxId);
        $len = strlen($clean);

        if ($len < 9 || $len > 10) {
            return false;
        }

        return preg_match('/^[0-9]{8,9}[A-Z]$|^[TSR][0-9]{2}[A-Z]{2}[0-9]{4}[A-Z]$|^M[0-9]{8}[A-Z]$/i', $clean) === 1;
    }

    protected function validatePhilippinesTin(string $taxId): bool
    {
        // Format BIR TIN Filipina: 9 atau 12 digit numerik (biasanya dipisahkan tanda strip 123-456-789-000)
        $digits = preg_replace('/\D/', '', $taxId);
        $len = strlen($digits);

        return $len === 9 || $len === 12;
    }

    /**
     * Dapatkan tarif standar regional per negara.
     *
     * @return array<string, string>
     */
    public function getStandardRates(string $countryCode): array
    {
        $country = strtoupper(trim($countryCode));

        return match ($country) {
            self::COUNTRY_INDONESIA => [
                TaxTypeEnum::VAT_PPN->value => '11.00',
                TaxTypeEnum::LOCAL_PB1->value => '10.00',
            ],
            self::COUNTRY_MALAYSIA => [
                TaxTypeEnum::SST_SALES->value => '10.00',
                TaxTypeEnum::SST_SERVICE->value => '8.00',
            ],
            self::COUNTRY_SINGAPORE => [
                TaxTypeEnum::GST_SG->value => '9.00',
            ],
            self::COUNTRY_PHILIPPINES => [
                TaxTypeEnum::VAT_PH->value => '12.00',
            ],
            default => throw new DomainException("Unsupported country code: {$country}"),
        };
    }

    /**
     * Hitung pajak regional menggunakan BCMath presisi tinggi.
     *
     * @return array{
     *     country_code: string,
     *     tax_type: string,
     *     rate_percent: string,
     *     taxable_amount: string,
     *     tax_amount: string,
     *     total_with_tax: string
     * }
     */
    public function calculateRegionalTax(
        string $countryCode,
        string $taxType,
        string $taxableAmount,
        ?string $customRate = null
    ): array {
        $country = strtoupper(trim($countryCode));
        $type = strtoupper(trim($taxType));

        $standardRates = $this->getStandardRates($country);
        $rate = $customRate ?? ($standardRates[$type] ?? null);

        if ($rate === null) {
            throw new DomainException("Unknown tax type [{$type}] for country [{$country}].");
        }

        $rateStr = bcadd($rate, '0', 2);
        $taxableStr = bcadd($taxableAmount, '0', 2);

        // Rumus: tax_amount = taxable_amount * (rate / 100)
        $taxFraction = bcdiv($rateStr, '100', 6);
        $taxAmount = bcmul($taxableStr, $taxFraction, 2);
        $totalWithTax = bcadd($taxableStr, $taxAmount, 2);

        return [
            'country_code' => $country,
            'tax_type' => $type,
            'rate_percent' => $rateStr,
            'taxable_amount' => $taxableStr,
            'tax_amount' => $taxAmount,
            'total_with_tax' => $totalWithTax,
        ];
    }

    /**
     * Mengagregasi data transaksi untuk persiapan SPT Pajak Malaysia SST-02 (Royal Malaysian Customs Department).
     *
     * @param array<int, array{type: 'sales'|'service'|'exempt', amount: string|float|int, rate?: string}> $lines
     * @return array{
     *     total_sales_value: string,
     *     taxable_sales_tax_amount: string,
     *     sales_tax_payable: string,
     *     taxable_service_tax_amount: string,
     *     service_tax_payable: string,
     *     exempt_sales_amount: string,
     *     total_sst_payable: string
     * }
     */
    public function prepareMalaysiaSst02Summary(array $lines): array
    {
        $totalSales = '0.00';
        $taxableSales = '0.00';
        $salesTaxPayable = '0.00';
        $taxableService = '0.00';
        $serviceTaxPayable = '0.00';
        $exemptSales = '0.00';

        foreach ($lines as $line) {
            $amt = bcadd((string) $line['amount'], '0', 2);
            $totalSales = bcadd($totalSales, $amt, 2);
            $type = $line['type'];

            if ($type === 'sales') {
                $rate = (string) ($line['rate'] ?? '10.00');
                $tax = bcmul($amt, bcdiv($rate, '100', 6), 2);
                $taxableSales = bcadd($taxableSales, $amt, 2);
                $salesTaxPayable = bcadd($salesTaxPayable, $tax, 2);
            } elseif ($type === 'service') {
                $rate = (string) ($line['rate'] ?? '8.00');
                $tax = bcmul($amt, bcdiv($rate, '100', 6), 2);
                $taxableService = bcadd($taxableService, $amt, 2);
                $serviceTaxPayable = bcadd($serviceTaxPayable, $tax, 2);
            } else {
                $exemptSales = bcadd($exemptSales, $amt, 2);
            }
        }

        $totalPayable = bcadd($salesTaxPayable, $serviceTaxPayable, 2);

        return [
            'total_sales_value' => $totalSales,
            'taxable_sales_tax_amount' => $taxableSales,
            'sales_tax_payable' => $salesTaxPayable,
            'taxable_service_tax_amount' => $taxableService,
            'service_tax_payable' => $serviceTaxPayable,
            'exempt_sales_amount' => $exemptSales,
            'total_sst_payable' => $totalPayable,
        ];
    }

    /**
     * Mengagregasi data transaksi untuk persiapan SPT Pajak Singapura IRAS GST F5 Return.
     *
     * @param array<int, array{type: 'standard'|'zero_rated'|'exempt', amount: string|float|int}> $lines
     * @return array{
     *     box_1_standard_rated_supplies: string,
     *     box_2_zero_rated_supplies: string,
     *     box_3_exempt_supplies: string,
     *     box_4_total_supplies: string,
     *     box_6_output_tax_due: string
     * }
     */
    public function prepareSingaporeGstF5Summary(array $lines): array
    {
        $box1 = '0.00';
        $box2 = '0.00';
        $box3 = '0.00';

        foreach ($lines as $line) {
            $amt = bcadd((string) $line['amount'], '0', 2);
            $type = $line['type'];

            if ($type === 'standard') {
                $box1 = bcadd($box1, $amt, 2);
            } elseif ($type === 'zero_rated') {
                $box2 = bcadd($box2, $amt, 2);
            } else {
                $box3 = bcadd($box3, $amt, 2);
            }
        }

        $box4 = bcadd(bcadd($box1, $box2, 2), $box3, 2);
        // Box 6: Output tax due = 9% dari Box 1
        $box6 = bcmul($box1, '0.09', 2);

        return [
            'box_1_standard_rated_supplies' => $box1,
            'box_2_zero_rated_supplies' => $box2,
            'box_3_exempt_supplies' => $box3,
            'box_4_total_supplies' => $box4,
            'box_6_output_tax_due' => $box6,
        ];
    }

    /**
     * Mengagregasi data transaksi untuk persiapan SPT Pajak Filipina BIR Form 2550M/Q.
     *
     * @param array<int, array{type: 'vatable'|'exempt'|'zero_rated', amount: string|float|int}> $lines
     * @return array{
     *     vatable_sales: string,
     *     vat_exempt_sales: string,
     *     zero_rated_sales: string,
     *     total_sales_php: string,
     *     output_vat_due: string
     * }
     */
    public function preparePhilippinesBir2550Summary(array $lines): array
    {
        $vatable = '0.00';
        $exempt = '0.00';
        $zeroRated = '0.00';

        foreach ($lines as $line) {
            $amt = bcadd((string) $line['amount'], '0', 2);
            $type = $line['type'];

            if ($type === 'vatable') {
                $vatable = bcadd($vatable, $amt, 2);
            } elseif ($type === 'zero_rated') {
                $zeroRated = bcadd($zeroRated, $amt, 2);
            } else {
                $exempt = bcadd($exempt, $amt, 2);
            }
        }

        $totalSales = bcadd(bcadd($vatable, $exempt, 2), $zeroRated, 2);
        // Output VAT = 12% dari vatable sales
        $outputVat = bcmul($vatable, '0.12', 2);

        return [
            'vatable_sales' => $vatable,
            'vat_exempt_sales' => $exempt,
            'zero_rated_sales' => $zeroRated,
            'total_sales_php' => $totalSales,
            'output_vat_due' => $outputVat,
        ];
    }
}
