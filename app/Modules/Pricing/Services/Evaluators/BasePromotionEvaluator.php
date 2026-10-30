<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services\Evaluators;

use App\Modules\Pricing\Contracts\PromotionRuleEvaluatorInterface;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use Carbon\Carbon;

abstract class BasePromotionEvaluator implements PromotionRuleEvaluatorInterface
{
    /**
     * Memeriksa kelayakan dasar kampanye (waktu, jadwal, hari, cabang, kanal, segmen pelanggan).
     */
    protected function isCampaignEligible(PromotionCampaign $campaign, CartEvaluationContextDto $context): bool
    {
        // 1. Status harus ACTIVE
        if ($campaign->status !== PromotionCampaign::STATUS_ACTIVE) {
            return false;
        }

        $now = $context->now;

        // 2. Rentang tanggal kampanye
        if ($campaign->start_datetime && $now->lt($campaign->start_datetime)) {
            return false;
        }
        if ($campaign->end_datetime && $now->gt($campaign->end_datetime)) {
            return false;
        }

        // 3. Batas hari aktif (misal ["Monday", "Friday"] atau ["monday", "friday"])
        if (! empty($campaign->allowed_days)) {
            $currentDayName = strtolower($now->format('l'));
            $allowedDaysLower = array_map(fn ($d) => strtolower((string) $d), (array) $campaign->allowed_days);
            if (! in_array($currentDayName, $allowedDaysLower, true)) {
                return false;
            }
        }

        // 4. Rentang jam aktif (Happy Hour: start_time <= now <= end_time)
        $currentTime = $now->format('H:i:s');
        if ($campaign->start_time && $currentTime < $campaign->start_time) {
            return false;
        }
        if ($campaign->end_time && $currentTime > $campaign->end_time) {
            return false;
        }

        // 5. Saluran Penjualan (Kanal)
        if (! empty($campaign->allowed_channels)) {
            if (! in_array($context->channel, (array) $campaign->allowed_channels, true)) {
                return false;
            }
        }

        // 6. Segmen / Grup Pelanggan
        if (! empty($campaign->allowed_customer_group_ids)) {
            if ($context->customerGroupId === null || ! in_array($context->customerGroupId, (array) $campaign->allowed_customer_group_ids, true)) {
                return false;
            }
        }

        // 7. Batas Cabang (Store Scope)
        if ($campaign->store_id !== null && (int) $campaign->store_id !== $context->storeId) {
            return false;
        }

        if ($campaign->relationLoaded('storeMappings') && $campaign->storeMappings->isNotEmpty()) {
            $allowedStoreIds = $campaign->storeMappings->pluck('store_id')->map(fn ($id) => (int) $id)->all();
            if (! in_array($context->storeId, $allowedStoreIds, true)) {
                return false;
            }
        }

        // 8. Batas Penggunaan Global Kampanye
        if ($campaign->max_usage_limit !== null && $campaign->current_usage_count >= $campaign->max_usage_limit) {
            return false;
        }

        return true;
    }
}
