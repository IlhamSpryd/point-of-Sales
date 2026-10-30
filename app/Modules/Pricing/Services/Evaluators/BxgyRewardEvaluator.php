<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services\Evaluators;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;

class BxgyRewardEvaluator extends BasePromotionEvaluator
{
    public function evaluate(PromotionCampaign $campaign, CartEvaluationContextDto $context): array
    {
        if (! $this->isCampaignEligible($campaign, $context)) {
            return [];
        }

        $applied = [];

        foreach ($campaign->rules as $rule) {
            /** @var \App\Modules\Pricing\Models\PromotionRule $rule */
            $triggerProductIds = array_map(fn ($id) => (int) $id, $rule->trigger_product_ids ?? []);
            $triggerCategoryIds = array_map(fn ($id) => (int) $id, $rule->trigger_category_ids ?? []);
            $reqTriggerQty = max(1, (int) ($rule->min_order_qty ?? 1));

            // 1. Hitung total kuantitas pemicu di keranjang
            $qTrigger = 0;
            foreach ($context->items as $item) {
                $isMatch = false;
                if (! empty($triggerProductIds) && in_array((int) $item['productId'], $triggerProductIds, true)) {
                    $isMatch = true;
                } elseif (! empty($triggerCategoryIds) && isset($item['categoryId']) && in_array((int) $item['categoryId'], $triggerCategoryIds, true)) {
                    $isMatch = true;
                }

                if ($isMatch) {
                    $qTrigger += (int) $item['quantity'];
                }
            }

            $numSets = (int) floor($qTrigger / $reqTriggerQty);
            if ($numSets <= 0) {
                continue;
            }

            foreach ($rule->rewards as $reward) {
                /** @var \App\Modules\Pricing\Models\PromotionReward $reward */
                // 2. Kumpulkan kandidat item reward di keranjang
                $candidateUnits = [];

                foreach ($context->items as $idx => $item) {
                    $isCandidate = false;

                    if ($reward->reward_product_id !== null) {
                        if ((int) $item['productId'] === (int) $reward->reward_product_id) {
                            $isCandidate = true;
                        }
                    } else {
                        // Jika tidak ada reward_product_id spesifik, reward berlaku untuk produk non-pemicu atau produk yang ditentukan
                        // Jika produk pemicu dan reward terpisah, jangan diskon pemicu kecuali eksplisit
                        if (empty($triggerProductIds) || ! in_array((int) $item['productId'], $triggerProductIds, true)) {
                            $isCandidate = true;
                        }
                    }

                    if ($isCandidate) {
                        $qty = (int) $item['quantity'];
                        for ($q = 0; $q < $qty; $q++) {
                            $candidateUnits[] = [
                                'lineIndex' => $idx,
                                'unitPrice' => (float) $item['unitPrice'],
                            ];
                        }
                    }
                }

                if (empty($candidateUnits)) {
                    continue;
                }

                // Urutkan least expensive item first (harga satuan termurah terlebih dahulu)
                usort($candidateUnits, function ($a, $b) {
                    return $a['unitPrice'] <=> $b['unitPrice'];
                });

                // Ambil maksimal sebanyak $numSets unit reward
                $selectedUnits = array_slice($candidateUnits, 0, $numSets);

                // Agregasi diskon per lineIndex
                $discountByLine = [];
                foreach ($selectedUnits as $unit) {
                    $lineIdx = $unit['lineIndex'];
                    $unitPrice = $unit['unitPrice'];

                    $discount = 0.0;
                    if ($reward->reward_type === PromotionReward::REWARD_FREE_PRODUCT) {
                        $discount = $unitPrice;
                    } elseif ($reward->reward_type === PromotionReward::REWARD_PERCENTAGE_DISCOUNT && $reward->discount_percentage !== null) {
                        $pct = (float) $reward->discount_percentage;
                        $discount = round($unitPrice * ($pct / 100), 2);
                    } elseif ($reward->reward_type === PromotionReward::REWARD_FIXED_AMOUNT_DISCOUNT && $reward->discount_fixed_amount !== null) {
                        $discount = min($unitPrice, (float) $reward->discount_fixed_amount);
                    } elseif ($reward->reward_type === PromotionReward::REWARD_SPECIAL_PRICE_PRODUCT && $reward->discount_fixed_amount !== null) {
                        $discount = max(0.0, $unitPrice - (float) $reward->discount_fixed_amount);
                    }

                    $discountByLine[$lineIdx] = ($discountByLine[$lineIdx] ?? 0.0) + $discount;
                }

                foreach ($discountByLine as $lineIdx => $totalDiscountForLine) {
                    if ($totalDiscountForLine > 0) {
                        $applied[] = new AppliedPromotionDto(
                            campaignId: (int) $campaign->id,
                            ruleId: (int) $rule->id,
                            rewardId: (int) $reward->id,
                            type: $campaign->type,
                            discountAmount: round($totalDiscountForLine, 2),
                            targetLineIndex: $lineIdx,
                            description: "BXGY Reward: {$campaign->name}",
                            meta: ['scope' => 'ITEM', 'line_index' => $lineIdx, 'sets_applied' => count($selectedUnits)]
                        );
                    }
                }
            }
        }

        return $applied;
    }
}
