<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DataTransferObjects;

final readonly class CartEvaluationResultDto
{
    /**
     * @param  array<AppliedPromotionDto>  $appliedPromotions
     * @param  array<int, string>  $eligibleCampaigns
     */
    public function __construct(
        public array $appliedPromotions = [],
        public float $totalItemDiscounts = 0.0,
        public float $totalOrderDiscounts = 0.0,
        public array $eligibleCampaigns = [],
    ) {}

    public function getTotalDiscounts(): float
    {
        return round($this->totalItemDiscounts + $this->totalOrderDiscounts, 2);
    }
}
