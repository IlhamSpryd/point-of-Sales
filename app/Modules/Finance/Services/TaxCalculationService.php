<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\TaxCalculationServiceInterface;
use InvalidArgumentException;

class TaxCalculationService implements TaxCalculationServiceInterface
{
    /**
     * Hitung pajak untuk satu nilai basis pajak (DPP) dengan presisi desimal eksak.
     *
     * @return array{
     *     taxable_base: string,
     *     tax_amount: string,
     *     total_amount: string,
     *     effective_rate: string
     * }
     */
    public function calculateTax(
        float|string $taxableAmount,
        float|string $taxRatePercent,
        bool $isInclusive = false
    ): array {
        $amountStr = (string) $taxableAmount;
        $rateStr = (string) $taxRatePercent;

        if (bccomp($rateStr, '0', 4) < 0) {
            throw new InvalidArgumentException('Tax rate cannot be negative.');
        }

        if (bccomp($amountStr, '0', 4) < 0) {
            throw new InvalidArgumentException('Taxable amount cannot be negative.');
        }

        if (bccomp($rateStr, '0', 4) === 0) {
            $baseFormatted = number_format((float) $amountStr, 2, '.', '');

            return [
                'taxable_base' => $baseFormatted,
                'tax_amount' => '0.00',
                'total_amount' => $baseFormatted,
                'effective_rate' => '0.0000',
            ];
        }

        if (! $isInclusive) {
            // Nilai belum termasuk pajak (Exclusive)
            // DPP = taxableAmount
            // PPN = round(DPP * rate / 100, 2)
            // Total = DPP + PPN
            $base = number_format((float) $amountStr, 2, '.', '');
            $taxDecimal = bcmul($base, bcdiv($rateStr, '100', 6), 4);
            $taxRounded = number_format(round((float) $taxDecimal, 2), 2, '.', '');
            $total = number_format((float) bcadd($base, $taxRounded, 4), 2, '.', '');

            return [
                'taxable_base' => $base,
                'tax_amount' => $taxRounded,
                'total_amount' => $total,
                'effective_rate' => number_format((float) $rateStr, 4, '.', ''),
            ];
        }

        // Nilai sudah termasuk pajak (Inclusive per PMK Indonesia)
        // DPP = round(total / (1 + rate / 100), 2)
        // PPN = total - DPP
        $totalFormatted = number_format((float) $amountStr, 2, '.', '');
        $divisor = bcadd('1', bcdiv($rateStr, '100', 6), 6);
        $baseRaw = bcdiv($totalFormatted, $divisor, 4);
        $baseRounded = number_format(round((float) $baseRaw, 2), 2, '.', '');
        $taxRounded = number_format((float) bcsub($totalFormatted, $baseRounded, 4), 2, '.', '');

        return [
            'taxable_base' => $baseRounded,
            'tax_amount' => $taxRounded,
            'total_amount' => $totalFormatted,
            'effective_rate' => number_format((float) $rateStr, 4, '.', ''),
        ];
    }

    /**
     * Hitung rincian pajak untuk kumpulan baris transaksi.
     */
    public function calculateLineItemsTax(array $items, bool $isInclusive = false): array
    {
        $lines = [];
        $totalTaxableBase = '0.00';
        $totalTaxAmount = '0.00';
        $grandTotal = '0.00';

        foreach ($items as $item) {
            $unitPrice = (string) $item['unit_price'];
            $quantity = (string) $item['quantity'];
            $discount = (string) ($item['discount_amount'] ?? '0.00');
            $rate = (string) $item['tax_rate_percent'];

            $lineGross = bcmul($unitPrice, $quantity, 4);
            $lineNet = bcsub($lineGross, $discount, 4);
            if (bccomp($lineNet, '0', 4) < 0) {
                $lineNet = '0.00';
            }

            $taxCalc = $this->calculateTax($lineNet, $rate, $isInclusive);

            $lines[] = [
                'line_id' => $item['line_id'] ?? null,
                'taxable_base' => $taxCalc['taxable_base'],
                'tax_amount' => $taxCalc['tax_amount'],
                'line_total' => $taxCalc['total_amount'],
            ];

            $totalTaxableBase = bcadd($totalTaxableBase, $taxCalc['taxable_base'], 2);
            $totalTaxAmount = bcadd($totalTaxAmount, $taxCalc['tax_amount'], 2);
            $grandTotal = bcadd($grandTotal, $taxCalc['total_amount'], 2);
        }

        return [
            'lines' => $lines,
            'total_taxable_base' => number_format((float) $totalTaxableBase, 2, '.', ''),
            'total_tax_amount' => number_format((float) $totalTaxAmount, 2, '.', ''),
            'grand_total' => number_format((float) $grandTotal, 2, '.', ''),
        ];
    }
}
