<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;

class PromotionCombinationEngine
{
    /**
     * Menyelesaikan kombinasi promosi tingkat item (eksklusif vs stackable).
     *
     * @param  array<AppliedPromotionDto>  $candidateItemPromotions  Kandidat promo item untuk sebuah baris
     * @param  float  $lineSubtotal  Nilai subtotal baris item
     * @return array<AppliedPromotionDto> Promo yang terpilih untuk diterapkan
     */
    public function resolveItemPromotions(array $candidateItemPromotions, float $lineSubtotal): array
    {
        if (empty($candidateItemPromotions)) {
            return [];
        }

        $exclusive = [];
        $stackable = [];

        foreach ($candidateItemPromotions as $promo) {
            $isStackable = (bool) ($promo->meta['is_stackable'] ?? false);
            if ($isStackable) {
                $stackable[] = $promo;
            } else {
                $exclusive[] = $promo;
            }
        }

        // JIKA ada promo EXCLUSIVE: pilih promo dengan nominal diskon terbesar
        if (! empty($exclusive)) {
            usort($exclusive, function (AppliedPromotionDto $a, AppliedPromotionDto $b) {
                if ($a->discountAmount != $b->discountAmount) {
                    return ($a->discountAmount < $b->discountAmount) ? 1 : -1;
                }

                return $a->campaignId <=> $b->campaignId;
            });

            $bestExclusive = $exclusive[0];
            $bestExclusiveDiscount = min($lineSubtotal, $bestExclusive->discountAmount);

            return [
                new AppliedPromotionDto(
                    campaignId: $bestExclusive->campaignId,
                    ruleId: $bestExclusive->ruleId,
                    rewardId: $bestExclusive->rewardId,
                    type: $bestExclusive->type,
                    discountAmount: $bestExclusiveDiscount,
                    targetLineIndex: $bestExclusive->targetLineIndex,
                    description: $bestExclusive->description,
                    meta: $bestExclusive->meta,
                ),
            ];
        }

        // JIKA HANYA ADA promo STACKABLE: terapkan berurutan hingga maksimal lineSubtotal
        $applied = [];
        $remaining = $lineSubtotal;

        foreach ($stackable as $promo) {
            if ($remaining <= 0) {
                break;
            }

            $discount = min($remaining, $promo->discountAmount);
            $applied[] = new AppliedPromotionDto(
                campaignId: $promo->campaignId,
                ruleId: $promo->ruleId,
                rewardId: $promo->rewardId,
                type: $promo->type,
                discountAmount: $discount,
                targetLineIndex: $promo->targetLineIndex,
                description: $promo->description,
                meta: $promo->meta,
            );
            $remaining -= $discount;
        }

        return $applied;
    }

    /**
     * Memilih promo order otomatis terbaik dari kandidat yang ada.
     *
     * @param  array<AppliedPromotionDto>  $candidateOrderPromotions
     */
    public function selectBestAutoOrderPromotion(array $candidateOrderPromotions): ?AppliedPromotionDto
    {
        if (empty($candidateOrderPromotions)) {
            return null;
        }

        usort($candidateOrderPromotions, function (AppliedPromotionDto $a, AppliedPromotionDto $b) {
            if ($a->discountAmount != $b->discountAmount) {
                return ($a->discountAmount < $b->discountAmount) ? 1 : -1;
            }

            return $a->campaignId <=> $b->campaignId;
        });

        return $candidateOrderPromotions[0];
    }

    /**
     * Menyelesaikan konflik antara Promo Order Otomatis dengan Kupon/Voucher belanja.
     *
     * @return array{
     *     appliedPromotions: array<AppliedPromotionDto>,
     *     autoOrderDiscount: float,
     *     voucherDiscount: float,
     *     winningSource: string
     * }
     */
    public function resolveOrderPromotionVsVoucher(
        ?AppliedPromotionDto $autoOrderPromo,
        ?float $voucherDiscount,
        bool $voucherIsStackable = false,
        bool $autoOrderIsStackable = false
    ): array {
        $autoDiscount = $autoOrderPromo ? (float) $autoOrderPromo->discountAmount : 0.0;
        $vDiscount = $voucherDiscount !== null ? max(0.0, (float) $voucherDiscount) : 0.0;

        if ($autoDiscount <= 0 && $vDiscount <= 0) {
            return [
                'appliedPromotions' => [],
                'autoOrderDiscount' => 0.0,
                'voucherDiscount' => 0.0,
                'winningSource' => 'NONE',
            ];
        }

        if ($autoDiscount > 0 && $vDiscount <= 0) {
            return [
                'appliedPromotions' => [$autoOrderPromo],
                'autoOrderDiscount' => $autoDiscount,
                'voucherDiscount' => 0.0,
                'winningSource' => 'AUTO_PROMOTION',
            ];
        }

        if ($autoDiscount <= 0 && $vDiscount > 0) {
            return [
                'appliedPromotions' => [],
                'autoOrderDiscount' => 0.0,
                'voucherDiscount' => $vDiscount,
                'winningSource' => 'VOUCHER',
            ];
        }

        // Keduanya ada: periksa stacking
        if ($autoOrderIsStackable && $voucherIsStackable) {
            return [
                'appliedPromotions' => [$autoOrderPromo],
                'autoOrderDiscount' => $autoDiscount,
                'voucherDiscount' => $vDiscount,
                'winningSource' => 'BOTH_STACKABLE',
            ];
        }

        // Konflik non-stackable: Ambil diskon terbesar MAX(auto_discount, voucher_discount)
        if ($vDiscount > $autoDiscount) {
            return [
                'appliedPromotions' => [], // Lepaskan promo otomatis
                'autoOrderDiscount' => 0.0,
                'voucherDiscount' => $vDiscount,
                'winningSource' => 'VOUCHER',
            ];
        }

        // Promo otomatis lebih besar atau sama
        return [
            'appliedPromotions' => [$autoOrderPromo],
            'autoOrderDiscount' => $autoDiscount,
            'voucherDiscount' => 0.0, // Lepaskan voucher
            'winningSource' => 'AUTO_PROMOTION',
        ];
    }

    /**
     * Menyelesaikan kombinasi seluruh diskon tingkat pesanan (promo otomatis, voucher, diskon manual).
     *
     * @param array<int, array{type: string, amount: float, is_stackable: bool}> $orderDiscounts
     */
    public function resolveOrderDiscounts(array $orderDiscounts, float $orderSubtotal): float
    {
        if (empty($orderDiscounts)) {
            return 0.0;
        }

        $nonStackableMax = 0.0;
        $stackableSum = 0.0;

        foreach ($orderDiscounts as $disc) {
            $amount = (float) $disc['amount'];
            $isStackable = (bool) $disc['is_stackable'];

            if ($isStackable) {
                $stackableSum += $amount;
            } else {
                $nonStackableMax = max($nonStackableMax, $amount);
            }
        }

        $total = $nonStackableMax + $stackableSum;

        return min($orderSubtotal, $total);
    }
}
