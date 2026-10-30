<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

class MarginSimulationResultDto
{
    /**
     * @param array<int, array{
     *     product_id: int,
     *     product_name: string,
     *     unit_price: float,
     *     standard_cost: float,
     *     discount_amount: float,
     *     discounted_price: float,
     *     estimated_qty: float,
     *     baseline_revenue: float,
     *     proposed_revenue: float,
     *     cogs: float,
     *     proposed_gross_profit: float,
     *     proposed_margin_percent: float,
     *     is_negative_margin: bool
     * }> $itemSimulations
     */
    public function __construct(
        public float $baselineRevenue,
        public float $baselineCogs,
        public float $baselineGrossProfit,
        public float $baselineMarginPercent,
        public float $proposedRevenue,
        public float $proposedCogs,
        public float $proposedGrossProfit,
        public float $proposedMarginPercent,
        public float $discountExposure,
        public float $profitVariance,
        public bool $hasNegativeMarginItems,
        public bool $isOverallNegativeMargin,
        public bool $requiresOwnerOverride,
        public array $itemSimulations = []
    ) {}

    public function toArray(): array
    {
        return [
            'baseline_revenue' => $this->baselineRevenue,
            'baseline_cogs' => $this->baselineCogs,
            'baseline_gross_profit' => $this->baselineGrossProfit,
            'baseline_margin_percent' => $this->baselineMarginPercent,
            'proposed_revenue' => $this->proposedRevenue,
            'proposed_cogs' => $this->proposedCogs,
            'proposed_gross_profit' => $this->proposedGrossProfit,
            'proposed_margin_percent' => $this->proposedMarginPercent,
            'discount_exposure' => $this->discountExposure,
            'profit_variance' => $this->profitVariance,
            'has_negative_margin_items' => $this->hasNegativeMarginItems,
            'is_overall_negative_margin' => $this->isOverallNegativeMargin,
            'requires_owner_override' => $this->requiresOwnerOverride,
            'items' => $this->itemSimulations,
        ];
    }
}
