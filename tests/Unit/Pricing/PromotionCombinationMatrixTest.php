<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Pricing\DataTransferObjects\AppliedPromotionDto;
use App\Modules\Pricing\Services\PromotionCombinationEngine;
use Tests\TestCase;

class PromotionCombinationMatrixTest extends TestCase
{
    private PromotionCombinationEngine $engine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->engine = new PromotionCombinationEngine;
    }

    public function test_exclusive_item_promotion_picks_highest_discount(): void
    {
        // 2 promo eksklusif untuk item yang sama:
        // Promo A: Diskon Rp5.000
        // Promo B: Diskon Rp8.000
        $candidateA = new AppliedPromotionDto(
            campaignId: 1,
            ruleId: 1,
            rewardId: 1,
            type: 'ITEM_DISCOUNT',
            discountAmount: 5000.0,
            targetLineIndex: 0,
            meta: ['is_stackable' => false]
        );

        $candidateB = new AppliedPromotionDto(
            campaignId: 2,
            ruleId: 2,
            rewardId: 2,
            type: 'ITEM_DISCOUNT',
            discountAmount: 8000.0,
            targetLineIndex: 0,
            meta: ['is_stackable' => false]
        );

        $resolved = $this->engine->resolveItemPromotions([$candidateA, $candidateB], 20000.0);

        // Hanya 1 promo yang dipilih (promo B dengan diskon terbesar 8.000)
        $this->assertCount(1, $resolved);
        $this->assertEquals(2, $resolved[0]->campaignId);
        $this->assertEquals(8000.0, $resolved[0]->discountAmount);
    }

    public function test_stackable_item_promotions_accumulate_up_to_line_subtotal(): void
    {
        $candidateA = new AppliedPromotionDto(
            campaignId: 1,
            ruleId: 1,
            rewardId: 1,
            type: 'ITEM_DISCOUNT',
            discountAmount: 4000.0,
            targetLineIndex: 0,
            meta: ['is_stackable' => true]
        );

        $candidateB = new AppliedPromotionDto(
            campaignId: 2,
            ruleId: 2,
            rewardId: 2,
            type: 'ITEM_DISCOUNT',
            discountAmount: 3000.0,
            targetLineIndex: 0,
            meta: ['is_stackable' => true]
        );

        $resolved = $this->engine->resolveItemPromotions([$candidateA, $candidateB], 10000.0);

        // Kedua promo stackable diakumulasikan
        $this->assertCount(2, $resolved);
        $this->assertEquals(4000.0, $resolved[0]->discountAmount);
        $this->assertEquals(3000.0, $resolved[1]->discountAmount);
    }

    public function test_order_promo_vs_voucher_conflict_resolves_highest_benefit_ut_pro_03(): void
    {
        // Promo order otomatis 10% bernilai Rp15.000 (non-stackable)
        $autoOrderPromo = new AppliedPromotionDto(
            campaignId: 10,
            ruleId: 10,
            rewardId: 10,
            type: 'ORDER_DISCOUNT',
            discountAmount: 15000.0,
            targetLineIndex: null,
            description: 'Promo Otomatis Transaksi 10%'
        );

        // Voucher belanja bernilai Rp20.000 (non-stackable)
        $voucherDiscount = 20000.0;

        $resolution = $this->engine->resolveOrderPromotionVsVoucher(
            autoOrderPromo: $autoOrderPromo,
            voucherDiscount: $voucherDiscount,
            voucherIsStackable: false,
            autoOrderIsStackable: false
        );

        // Sistem wajib memilih promo dengan nilai terbesar (Voucher Rp20.000) dan melepaskan promo otomatis Rp15.000
        $this->assertEquals('VOUCHER', $resolution['winningSource']);
        $this->assertEquals(20000.0, $resolution['voucherDiscount']);
        $this->assertEquals(0.0, $resolution['autoOrderDiscount']);
        $this->assertEmpty($resolution['appliedPromotions']);
    }

    public function test_order_promo_and_voucher_combine_when_both_stackable(): void
    {
        $autoOrderPromo = new AppliedPromotionDto(
            campaignId: 10,
            ruleId: 10,
            rewardId: 10,
            type: 'ORDER_DISCOUNT',
            discountAmount: 15000.0,
            targetLineIndex: null,
            description: 'Promo Otomatis Transaksi 10%'
        );

        $voucherDiscount = 10000.0;

        $resolution = $this->engine->resolveOrderPromotionVsVoucher(
            autoOrderPromo: $autoOrderPromo,
            voucherDiscount: $voucherDiscount,
            voucherIsStackable: true,
            autoOrderIsStackable: true
        );

        $this->assertEquals('BOTH_STACKABLE', $resolution['winningSource']);
        $this->assertEquals(15000.0, $resolution['autoOrderDiscount']);
        $this->assertEquals(10000.0, $resolution['voucherDiscount']);
        $this->assertCount(1, $resolution['appliedPromotions']);
    }
}
