<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

class CheckoutCalculationBreakdownDto
{
    /**
     * @param array<int, array<string, mixed>> $lineBreakdowns
     * @param array<int, array<string, mixed>> $appliedPromotionsSummary
     */
    public function __construct(
        public float $lineSubtotal,
        public float $totalItemDiscounts,
        public float $orderSubtotal,
        public float $totalTransactionDiscounts,
        public float $dpp,
        public float $serviceChargeRate,
        public float $serviceChargeAmount,
        public float $taxRate,
        public bool $isTaxInclusive,
        public float $taxAmount,
        public float $cashRoundingAdjustment,
        public float $grandTotal,
        public array $lineBreakdowns = [],
        public array $appliedPromotionsSummary = []
    ) {}

    public function toArray(): array
    {
        return [
            'line_subtotal' => $this->lineSubtotal,
            'total_item_discounts' => $this->totalItemDiscounts,
            'order_subtotal' => $this->orderSubtotal,
            'total_transaction_discounts' => $this->totalTransactionDiscounts,
            'dpp' => $this->dpp,
            'service_charge_rate' => $this->serviceChargeRate,
            'service_charge_amount' => $this->serviceChargeAmount,
            'tax_rate' => $this->taxRate,
            'is_tax_inclusive' => $this->isTaxInclusive,
            'tax_amount' => $this->taxAmount,
            'cash_rounding_adjustment' => $this->cashRoundingAdjustment,
            'grand_total' => $this->grandTotal,
            'lines' => $this->lineBreakdowns,
            'applied_promotions_summary' => $this->appliedPromotionsSummary,
        ];
    }
}
