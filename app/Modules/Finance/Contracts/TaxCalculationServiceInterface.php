<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

interface TaxCalculationServiceInterface
{
    /**
     * Hitung pajak untuk satu nilai basis pajak (DPP).
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
    ): array;

    /**
     * Hitung rincian pajak untuk kumpulan baris transaksi.
     *
     * @param array<int, array{
     *     line_id?: int|string,
     *     unit_price: float|string,
     *     quantity: float|string,
     *     discount_amount?: float|string,
     *     tax_rate_percent: float|string
     * }> $items
     * @return array{
     *     lines: array<int, array{
     *         line_id: int|string|null,
     *         taxable_base: string,
     *         tax_amount: string,
     *         line_total: string
     *     }>,
     *     total_taxable_base: string,
     *     total_tax_amount: string,
     *     grand_total: string
     * }
     */
    public function calculateLineItemsTax(array $items, bool $isInclusive = false): array;
}
