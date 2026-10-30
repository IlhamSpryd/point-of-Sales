<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services\Evaluators;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;

class BundlePackageEvaluator extends BasePromotionEvaluator
{
    public function evaluate(PromotionCampaign $campaign, CartEvaluationContextDto $context): array
    {
        if (! $this->isCampaignEligible($campaign, $context)) {
            return [];
        }

        $applied = [];

        foreach ($campaign->rules as $rule) {
            /** @var \App\Modules\Pricing\Models\PromotionRule $rule */
            $requiredProductIds = array_map(fn ($id) => (int) $id, $rule->trigger_product_ids ?? []);
            if (empty($requiredProductIds)) {
                continue;
            }

            // Hitung kuantitas tiap produk komponen di keranjang
            $cartQtyByProduct = [];
            $lineIndicesByProduct = [];

            foreach ($context->items as $idx => $item) {
                $pid = (int) $item['productId'];
                if (in_array($pid, $requiredProductIds, true)) {
                    $cartQtyByProduct[$pid] = ($cartQtyByProduct[$pid] ?? 0) + (int) $item['quantity'];
                    $lineIndicesByProduct[$pid][] = $idx;
                }
            }

            // Pastikan SEMUA produk komponen ada di keranjang
            $bundleSets = null;
            foreach ($requiredProductIds as $reqPid) {
                $qty = $cartQtyByProduct[$reqPid] ?? 0;
                if ($qty <= 0) {
                    $bundleSets = 0;
                    break;
                }
                $bundleSets = ($bundleSets === null) ? $qty : min($bundleSets, $qty);
            }

            if (! $bundleSets || $bundleSets <= 0) {
                continue;
            }

            foreach ($rule->rewards as $reward) {
                /** @var \App\Modules\Pricing\Models\PromotionReward $reward */
                // Hitung harga normal 1 set bundle
                $normalPricePerSet = 0.0;
                $componentNormalTotal = 0.0;
                $componentLines = [];

                foreach ($requiredProductIds as $pid) {
                    foreach ($lineIndicesByProduct[$pid] as $idx) {
                        $item = $context->items[$idx];
                        $unitPrice = (float) $item['unitPrice'];
                        $componentLines[$idx] = [
                            'unitPrice' => $unitPrice,
                            'productId' => $pid,
                        ];
                    }
                }

                foreach ($requiredProductIds as $pid) {
                    $firstIdx = $lineIndicesByProduct[$pid][0];
                    $normalPricePerSet += (float) $context->items[$firstIdx]['unitPrice'];
                }

                $discountPerSet = 0.0;
                if ($reward->reward_type === PromotionReward::REWARD_SPECIAL_PRICE_PRODUCT && $reward->discount_fixed_amount !== null) {
                    $specialPrice = (float) $reward->discount_fixed_amount;
                    $discountPerSet = max(0.0, $normalPricePerSet - $specialPrice);
                } elseif ($reward->reward_type === PromotionReward::REWARD_FIXED_AMOUNT_DISCOUNT && $reward->discount_fixed_amount !== null) {
                    $discountPerSet = min($normalPricePerSet, (float) $reward->discount_fixed_amount);
                } elseif ($reward->reward_type === PromotionReward::REWARD_PERCENTAGE_DISCOUNT && $reward->discount_percentage !== null) {
                    $discountPerSet = round($normalPricePerSet * ((float) $reward->discount_percentage / 100), 2);
                }

                $totalBundleDiscount = round($discountPerSet * $bundleSets, 2);
                if ($totalBundleDiscount <= 0) {
                    continue;
                }

                // Alokasikan diskon paket ke masing-masing item komponen secara proporsional
                $totalNormalForBundles = $normalPricePerSet * $bundleSets;
                $allocatedTotal = 0.0;
                $pidsCount = count($requiredProductIds);

                foreach ($requiredProductIds as $i => $pid) {
                    $lineIdx = $lineIndicesByProduct[$pid][0];
                    $itemUnitPrice = (float) $context->items[$lineIdx]['unitPrice'];
                    $itemSubtotalForBundles = $itemUnitPrice * $bundleSets;

                    if ($i === $pidsCount - 1) {
                        // Sisa sen diserap baris terakhir
                        $lineDiscount = round($totalBundleDiscount - $allocatedTotal, 2);
                    } else {
                        $ratio = $totalNormalForBundles > 0 ? ($itemSubtotalForBundles / $totalNormalForBundles) : 0;
                        $lineDiscount = round($totalBundleDiscount * $ratio, 2);
                        $allocatedTotal += $lineDiscount;
                    }

                    if ($lineDiscount > 0) {
                        $applied[] = new AppliedPromotionDto(
                            campaignId: (int) $campaign->id,
                            ruleId: (int) $rule->id,
                            rewardId: (int) $reward->id,
                            type: $campaign->type,
                            discountAmount: $lineDiscount,
                            targetLineIndex: $lineIdx,
                            description: "Bundle: {$campaign->name}",
                            meta: [
                                'scope' => 'ITEM',
                                'line_index' => $lineIdx,
                                'bundle_sets' => $bundleSets,
                                'total_bundle_discount' => $totalBundleDiscount,
                            ]
                        );
                    }
                }
            }
        }

        return $applied;
    }
}
