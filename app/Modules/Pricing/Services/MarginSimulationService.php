<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Models\Product;
use App\Models\User;
use App\Modules\Pricing\DataTransferObjects\MarginSimulationResultDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use DomainException;

class MarginSimulationService
{
    /**
     * Menjalankan simulasi dampak marjin kotor terhadap kampanye promosi secara murni read-only.
     *
     * @param array<int, array{product: Product, quantity: float|int}> $productsWithQty
     */
    public function simulate(PromotionCampaign $campaign, array $productsWithQty = []): MarginSimulationResultDto
    {
        if (empty($productsWithQty)) {
            $productsWithQty = $this->resolveCandidateProducts($campaign);
        }

        $itemSimulations = [];
        $totalBaselineRev = 0.0;
        $totalBaselineCogs = 0.0;
        $totalProposedRev = 0.0;
        $hasNegativeMargin = false;

        foreach ($productsWithQty as $entry) {
            $product = $entry['product'];
            $qty = max(1.0, (float) ($entry['quantity'] ?? 10.0));

            $unitPrice = (float) ($product->product_price ?? $product->price ?? 0.0);
            $standardCost = (float) ($product->standard_cost ?? $product->cost_price ?? $product->hpp ?? 0.0);

            // Jika standard_cost 0 dan produk punya resep BOM, hitung dari bahan baku
            if ($standardCost <= 0.0 && $product->relationLoaded('ingredients') && $product->ingredients->isNotEmpty()) {
                foreach ($product->ingredients as $ing) {
                    $ingQty = (float) ($ing->pivot?->quantity_required ?? 1.0);
                    $ingCost = (float) ($ing->cost_per_unit ?? 0.0);
                    $standardCost += $ingQty * $ingCost;
                }
            }

            $discountAmount = $this->calculateProductDiscountFromCampaign($campaign, $product, $unitPrice);
            $discountedPrice = max(0.0, round($unitPrice - $discountAmount, 2));

            $lineBaselineRev = round($unitPrice * $qty, 2);
            $lineProposedRev = round($discountedPrice * $qty, 2);
            $lineCogs = round($standardCost * $qty, 2);
            $lineProposedProfit = round($lineProposedRev - $lineCogs, 2);

            $lineProposedMarginPct = $lineProposedRev > 0.0
                ? round(($lineProposedProfit / $lineProposedRev) * 100, 2)
                : ($lineProposedProfit < 0 ? -100.0 : 0.0);

            $isNegative = $discountedPrice < $standardCost || $lineProposedProfit < 0;
            if ($isNegative) {
                $hasNegativeMargin = true;
            }

            $totalBaselineRev += $lineBaselineRev;
            $totalBaselineCogs += $lineCogs;
            $totalProposedRev += $lineProposedRev;

            $itemSimulations[] = [
                'product_id' => (int) $product->id,
                'product_name' => (string) ($product->product_name ?? $product->name ?? 'Produk'),
                'unit_price' => $unitPrice,
                'standard_cost' => $standardCost,
                'discount_amount' => $discountAmount,
                'discounted_price' => $discountedPrice,
                'estimated_qty' => $qty,
                'baseline_revenue' => $lineBaselineRev,
                'proposed_revenue' => $lineProposedRev,
                'cogs' => $lineCogs,
                'proposed_gross_profit' => $lineProposedProfit,
                'proposed_margin_percent' => $lineProposedMarginPct,
                'is_negative_margin' => $isNegative,
            ];
        }

        $baselineGrossProfit = round($totalBaselineRev - $totalBaselineCogs, 2);
        $baselineMarginPercent = $totalBaselineRev > 0.0
            ? round(($baselineGrossProfit / $totalBaselineRev) * 100, 2)
            : 0.0;

        $proposedGrossProfit = round($totalProposedRev - $totalBaselineCogs, 2);
        $proposedMarginPercent = $totalProposedRev > 0.0
            ? round(($proposedGrossProfit / $totalProposedRev) * 100, 2)
            : ($proposedGrossProfit < 0 ? -100.0 : 0.0);

        $discountExposure = round($totalBaselineRev - $totalProposedRev, 2);
        $profitVariance = round($proposedGrossProfit - $baselineGrossProfit, 2);
        $isOverallNegative = $proposedGrossProfit < 0;
        $requiresOwnerOverride = $isOverallNegative || $hasNegativeMargin;

        return new MarginSimulationResultDto(
            baselineRevenue: $totalBaselineRev,
            baselineCogs: $totalBaselineCogs,
            baselineGrossProfit: $baselineGrossProfit,
            baselineMarginPercent: $baselineMarginPercent,
            proposedRevenue: $totalProposedRev,
            proposedCogs: $totalBaselineCogs,
            proposedGrossProfit: $proposedGrossProfit,
            proposedMarginPercent: $proposedMarginPercent,
            discountExposure: $discountExposure,
            profitVariance: $profitVariance,
            hasNegativeMarginItems: $hasNegativeMargin,
            isOverallNegativeMargin: $isOverallNegative,
            requiresOwnerOverride: $requiresOwnerOverride,
            itemSimulations: $itemSimulations
        );
    }

    /**
     * Memvalidasi apakah kampanye dapat dipublikasikan (Negative Margin Guard).
     */
    public function canPublish(PromotionCampaign $campaign, User $actor, array $productsWithQty = []): bool
    {
        $simulation = $this->simulate($campaign, $productsWithQty);

        if (! $simulation->requiresOwnerOverride) {
            return true;
        }

        // Jika marjin negatif: hanya aktor dengan peran 'Owner' yang boleh melakukan override publikasi
        $roleName = $actor->role ? $actor->role->name : '';
        $isOwner = strcasecmp((string) $roleName, 'Owner') === 0;

        if (! $isOwner && method_exists($actor, 'hasRole')) {
            $isOwner = $actor->hasRole('Owner');
        }

        return $isOwner;
    }

    /**
     * Hitung estimasi diskon per unit produk berdasarkan aturan kampanye.
     */
    protected function calculateProductDiscountFromCampaign(PromotionCampaign $campaign, Product $product, float $unitPrice): float
    {
        $maxDiscount = 0.0;

        foreach ($campaign->rules as $rule) {
            /** @var \App\Modules\Pricing\Models\PromotionRule $rule */
            // Periksa apakah produk cocok dengan aturan
            $triggerProductIds = is_array($rule->trigger_product_ids) ? $rule->trigger_product_ids : [];
            $triggerCategoryIds = is_array($rule->trigger_category_ids) ? $rule->trigger_category_ids : [];

            $isProductMatch = empty($triggerProductIds) || in_array($product->id, $triggerProductIds);
            $isCategoryMatch = empty($triggerCategoryIds) || in_array($product->category_id, $triggerCategoryIds);

            if (! $isProductMatch && ! $isCategoryMatch) {
                continue;
            }

            foreach ($rule->rewards as $reward) {
                /** @var \App\Modules\Pricing\Models\PromotionReward $reward */
                $discount = 0.0;
                switch ($reward->reward_type) {
                    case 'PERCENTAGE_DISCOUNT':
                        $pct = (float) $reward->discount_percentage;
                        $discount = round($unitPrice * ($pct / 100), 2);
                        if ($reward->max_discount_amount > 0) {
                            $discount = min($discount, (float) $reward->max_discount_amount);
                        }
                        break;
                    case 'FIXED_AMOUNT_DISCOUNT':
                        $discount = min($unitPrice, (float) $reward->discount_fixed_amount);
                        break;
                    case 'FREE_PRODUCT':
                        $discount = $unitPrice;
                        break;
                    case 'SPECIAL_PRICE_PRODUCT':
                        if ($reward->reward_product_id == $product->id && $reward->reward_product_discount_percent > 0) {
                            $discount = round($unitPrice * ($reward->reward_product_discount_percent / 100), 2);
                        }
                        break;
                }

                $maxDiscount = max($maxDiscount, $discount);
            }
        }

        return min($unitPrice, $maxDiscount);
    }

    /**
     * Resolusi produk-produk target dari kampanye jika tidak dioper manual.
     *
     * @return array<int, array{product: Product, quantity: float}>
     */
    protected function resolveCandidateProducts(PromotionCampaign $campaign): array
    {
        $productIds = [];
        foreach ($campaign->rules as $rule) {
            if (! empty($rule->trigger_product_ids) && is_array($rule->trigger_product_ids)) {
                $productIds = array_merge($productIds, $rule->trigger_product_ids);
            }
        }

        $query = Product::where('tenant_id', $campaign->tenant_id)->where('is_active', true);
        if (! empty($productIds)) {
            $query->whereIn('id', array_unique($productIds));
        } else {
            $query->limit(10);
        }

        $products = $query->get();
        $result = [];
        foreach ($products as $p) {
            $result[] = [
                'product' => $p,
                'quantity' => 10.0,
            ];
        }

        return $result;
    }
}
