<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;

interface PromotionRuleEvaluatorInterface
{
    /**
     * Mengevaluasi apakah kampanye promosi memenuhi syarat untuk konteks keranjang belanja ini.
     * Mengembalikan list AppliedPromotionDto jika memenuhi syarat, atau array kosong jika tidak memenuhi syarat.
     *
     * @return array<AppliedPromotionDto>
     */
    public function evaluate(PromotionCampaign $campaign, CartEvaluationContextDto $context): array;
}
