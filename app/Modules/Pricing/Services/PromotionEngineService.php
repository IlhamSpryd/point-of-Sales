<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationResultDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReservation;
use App\Modules\Pricing\Services\Evaluators\BundlePackageEvaluator;
use App\Modules\Pricing\Services\Evaluators\BxgyRewardEvaluator;
use App\Modules\Pricing\Services\Evaluators\HappyHourScheduleEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionEngineService
{
    /** @var array<string, mixed> */
    protected array $campaignsCache = [];

    public function clearCache(): void
    {
        $this->campaignsCache = [];
    }

    public function __construct(
        protected HappyHourScheduleEvaluator $happyHourEvaluator,
        protected BxgyRewardEvaluator $bxgyEvaluator,
        protected BundlePackageEvaluator $bundleEvaluator,
    ) {}

    /**
     * Mengevaluasi seluruh promosi aktif yang eligible untuk keranjang kasir.
     */
    public function evaluateCart(CartEvaluationContextDto $context): CartEvaluationResultDto
    {
        $cacheKey = "camp_{$context->tenantId}_{$context->storeId}";
        if (! isset($this->campaignsCache[$cacheKey])) {
            $this->campaignsCache[$cacheKey] = PromotionCampaign::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('status', PromotionCampaign::STATUS_ACTIVE)
                ->where('start_datetime', '<=', $context->now)
                ->where('end_datetime', '>=', $context->now)
                ->where(function ($query) use ($context) {
                    $query->whereNull('store_id')
                        ->orWhere('store_id', $context->storeId)
                        ->orWhereHas('storeMappings', function ($q) use ($context) {
                            $q->where('store_id', $context->storeId);
                        });
                })
                ->with(['rules.rewards', 'storeMappings'])
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();
        }

        $campaigns = $this->campaignsCache[$cacheKey];

        $allApplied = [];
        $eligibleCampaigns = [];
        $totalItemDiscounts = 0.0;
        $totalOrderDiscounts = 0.0;

        foreach ($campaigns as $campaign) {
            $appliedForCampaign = match ($campaign->type) {
                PromotionCampaign::TYPE_HAPPY_HOUR => $this->happyHourEvaluator->evaluate($campaign, $context),
                PromotionCampaign::TYPE_BUY_X_GET_Y => $this->bxgyEvaluator->evaluate($campaign, $context),
                PromotionCampaign::TYPE_BUNDLE_PACKAGE => $this->bundleEvaluator->evaluate($campaign, $context),
                default => $this->happyHourEvaluator->evaluate($campaign, $context),
            };

            if (! empty($appliedForCampaign)) {
                $eligibleCampaigns[] = $campaign->name;
                foreach ($appliedForCampaign as $promoDto) {
                    $allApplied[] = $promoDto;
                    if ($promoDto->targetLineIndex !== null) {
                        $totalItemDiscounts += $promoDto->discountAmount;
                    } else {
                        $totalOrderDiscounts += $promoDto->discountAmount;
                    }
                }
            }
        }

        return new CartEvaluationResultDto(
            appliedPromotions: $allApplied,
            totalItemDiscounts: round($totalItemDiscounts, 2),
            totalOrderDiscounts: round($totalOrderDiscounts, 2),
            eligibleCampaigns: $eligibleCampaigns,
        );
    }

    /**
     * Memesan kuota promosi / kupon untuk keranjang aktif (TTL 120 detik).
     */
    public function reservePromotionQuota(
        string $cartUuid,
        ?int $campaignId,
        ?int $voucherId = null,
        int $storeId = 0,
        ?int $customerId = null,
        float $reservedAmount = 0.0
    ): int {
        return DB::transaction(function () use ($cartUuid, $campaignId, $voucherId, $storeId, $customerId, $reservedAmount) {
            $tenantId = app(\App\Services\Context\TenantContext::class)->requireTenantId();

            if ($campaignId !== null) {
                $campaign = PromotionCampaign::where('tenant_id', $tenantId)
                    ->where('id', $campaignId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($campaign->max_usage_limit !== null) {
                    $activeReserved = PromotionReservation::where('tenant_id', $tenantId)
                        ->where('campaign_id', $campaignId)
                        ->where('status', PromotionReservation::STATUS_RESERVED)
                        ->where('expires_at', '>', now())
                        ->count();

                    if (($campaign->current_usage_count + $activeReserved) >= $campaign->max_usage_limit) {
                        throw ValidationException::withMessages([
                            'promotion' => 'Kuota promosi telah habis.',
                        ]);
                    }
                }
            }

            $reservation = PromotionReservation::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'campaign_id' => $campaignId,
                'voucher_id' => $voucherId,
                'customer_id' => $customerId,
                'cart_uuid' => $cartUuid,
                'reserved_amount' => $reservedAmount,
                'status' => PromotionReservation::STATUS_RESERVED,
                'expires_at' => now()->addSeconds(120),
            ]);

            return (int) $reservation->id;
        });
    }

    /**
     * Mengomposisikan reservasi menjadi status COMMITTED saat checkout sukses.
     */
    public function commitReservation(int $reservationId, int $orderId): void
    {
        DB::transaction(function () use ($reservationId, $orderId) {
            $reservation = PromotionReservation::lockForUpdate()->findOrFail($reservationId);
            $reservation->update([
                'status' => PromotionReservation::STATUS_COMMITTED,
                'order_id' => $orderId,
            ]);

            if ($reservation->campaign_id) {
                PromotionCampaign::where('id', $reservation->campaign_id)
                    ->increment('current_usage_count');
            }
        });
    }

    /**
     * Melepaskan reservasi jika checkout dibatalkan.
     */
    public function releaseReservation(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
            $reservation = PromotionReservation::lockForUpdate()->findOrFail($reservationId);
            $reservation->update([
                'status' => PromotionReservation::STATUS_RELEASED,
            ]);
        });
    }
}
