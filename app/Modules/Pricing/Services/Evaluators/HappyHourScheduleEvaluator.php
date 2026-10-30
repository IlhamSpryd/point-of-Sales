<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services\Evaluators;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;

class HappyHourScheduleEvaluator extends BasePromotionEvaluator
{
    public function evaluate(PromotionCampaign $campaign, CartEvaluationContextDto $context): array
    {
        if (! $this->isCampaignEligible($campaign, $context)) {
            return [];
        }

        $applied = [];
        $cartSubtotal = $context->getSubtotal();

        foreach ($campaign->rules as $rule) {
            /** @var \App\Modules\Pricing\Models\PromotionRule $rule */
            $isRuleSatisfied = false;
            $matchingLineIndices = [];

            if ($rule->rule_type === PromotionRule::RULE_CART_TOTAL) {
                if ($cartSubtotal >= (float) $rule->min_order_amount) {
                    $isRuleSatisfied = true;
                }
            } elseif ($rule->rule_type === PromotionRule::RULE_PRODUCT_MATCH) {
                $triggerProductIds = array_map(fn ($id) => (int) $id, $rule->trigger_product_ids ?? []);
                $matchedQty = 0;

                foreach ($context->items as $idx => $item) {
                    if (in_array((int) $item['productId'], $triggerProductIds, true)) {
                        $matchedQty += (int) $item['quantity'];
                        $matchingLineIndices[] = $idx;
                    }
                }

                if ($matchedQty >= (int) ($rule->min_order_qty ?? 1)) {
                    $isRuleSatisfied = true;
                }
            } elseif ($rule->rule_type === PromotionRule::RULE_CATEGORY_MATCH) {
                $triggerCategoryIds = array_map(fn ($id) => (int) $id, $rule->trigger_category_ids ?? []);
                $matchedQty = 0;

                foreach ($context->items as $idx => $item) {
                    $catId = isset($item['categoryId']) ? (int) $item['categoryId'] : null;
                    if ($catId !== null && in_array($catId, $triggerCategoryIds, true)) {
                        $matchedQty += (int) $item['quantity'];
                        $matchingLineIndices[] = $idx;
                    }
                }

                if ($matchedQty >= (int) ($rule->min_order_qty ?? 1)) {
                    $isRuleSatisfied = true;
                }
            }

            if (! $isRuleSatisfied) {
                continue;
            }

            foreach ($rule->rewards as $reward) {
                /** @var \App\Modules\Pricing\Models\PromotionReward $reward */
                if ($rule->rule_type === PromotionRule::RULE_CART_TOTAL) {
                    // Diskon tingkat keranjang / order
                    $discount = $this->calculateDiscountAmount($reward, $cartSubtotal);
                    if ($discount > 0) {
                        $applied[] = new AppliedPromotionDto(
                            campaignId: (int) $campaign->id,
                            ruleId: (int) $rule->id,
                            rewardId: (int) $reward->id,
                            type: $campaign->type,
                            discountAmount: $discount,
                            targetLineIndex: null,
                            description: "Happy Hour: {$campaign->name}",
                            meta: ['scope' => 'ORDER']
                        );
                    }
                } else {
                    // Diskon tingkat item untuk setiap baris produk yang cocok
                    foreach ($matchingLineIndices as $lineIdx) {
                        $lineItem = $context->items[$lineIdx];
                        $lineSubtotal = ((float) $lineItem['unitPrice']) * ((float) $lineItem['quantity']);
                        $discount = $this->calculateDiscountAmount($reward, $lineSubtotal);

                        if ($discount > 0) {
                            $applied[] = new AppliedPromotionDto(
                                campaignId: (int) $campaign->id,
                                ruleId: (int) $rule->id,
                                rewardId: (int) $reward->id,
                                type: $campaign->type,
                                discountAmount: $discount,
                                targetLineIndex: $lineIdx,
                                description: "Happy Hour: {$campaign->name} pada Item",
                                meta: ['scope' => 'ITEM', 'line_index' => $lineIdx]
                            );
                        }
                    }
                }
            }
        }

        return $applied;
    }

    private function calculateDiscountAmount(PromotionReward $reward, float $baseAmount): float
    {
        $discount = 0.0;

        if ($reward->reward_type === PromotionReward::REWARD_PERCENTAGE_DISCOUNT && $reward->discount_percentage !== null) {
            $discount = round($baseAmount * ((float) $reward->discount_percentage / 100), 2);
            if ($reward->max_discount_amount !== null && (float) $reward->max_discount_amount > 0) {
                $discount = min($discount, (float) $reward->max_discount_amount);
            }
        } elseif ($reward->reward_type === PromotionReward::REWARD_FIXED_AMOUNT_DISCOUNT && $reward->discount_fixed_amount !== null) {
            $discount = min($baseAmount, (float) $reward->discount_fixed_amount);
        }

        return $discount;
    }
}
