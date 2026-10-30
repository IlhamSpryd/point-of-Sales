<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Pricing\DataTransferObjects\CartEvaluationContextDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Services\Evaluators\BxgyRewardEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BxgyPromotionTest extends TestCase
{
    use RefreshDatabase;

    private BxgyRewardEvaluator $evaluator;

    private Tenant $tenant;

    private Store $store;

    private Product $kopi;

    private Product $pastryA;

    private Product $pastryB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->evaluator = new BxgyRewardEvaluator;

        $this->tenant = Tenant::create([
            'name' => 'BXGY Test Tenant',
            'slug' => 'bxgy-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store BXGY',
            'is_active' => true,
        ]);

        app(\App\Services\Context\TenantContext::class)->setStoreId($this->store->id);

        $catBeverage = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $catBakery = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Bakery',
        ]);

        $this->kopi = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catBeverage->id,
            'product_name' => 'Kopi Arabika',
            'product_price' => 25000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->pastryA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catBakery->id,
            'product_name' => 'Croissant Almond (Pastry A)',
            'product_price' => 20000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->pastryB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $catBakery->id,
            'product_name' => 'Pain au Chocolat (Pastry B)',
            'product_price' => 15000,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_bxgy_least_expensive_reward_selection_ut_pro_02(): void
    {
        // Promo: Beli 2 Kopi, Diskon 50% untuk 1 Pastry
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'BXGY-KOPI-PASTRY',
            'name' => 'Beli 2 Kopi Dapat 1 Pastry Diskon 50%',
            'type' => PromotionCampaign::TYPE_BUY_X_GET_Y,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_BXGY_TRIGGER,
            'trigger_product_ids' => [$this->kopi->id],
            'min_order_qty' => 2,
        ]);

        // Reward: Diskon 50% pada Pastry (bisa pastry A atau pastry B)
        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_PERCENTAGE_DISCOUNT,
            'discount_percentage' => 50.00,
            'reward_product_id' => null, // Berlaku untuk produk lain di keranjang
        ]);

        $campaign->load('rules.rewards');

        // Keranjang berisi:
        // Line 0: 2 Kopi (unit 25.000)
        // Line 1: 1 Pastry A (unit 20.000)
        // Line 2: 1 Pastry B (unit 15.000)
        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            now: Carbon::parse('2026-10-30 10:00:00'),
            items: [
                ['productId' => $this->kopi->id, 'quantity' => 2, 'unitPrice' => 25000.0, 'lineIndex' => 0],
                ['productId' => $this->pastryA->id, 'quantity' => 1, 'unitPrice' => 20000.0, 'lineIndex' => 1],
                ['productId' => $this->pastryB->id, 'quantity' => 1, 'unitPrice' => 15000.0, 'lineIndex' => 2],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        // Hanya 1 reward yang berhak didapat (N_sets = floor(2/2) = 1)
        $this->assertCount(1, $results);

        // Harus mendiskon Pastry B (Line 2) karena harganya (Rp15.000) lebih murah daripada Pastry A (Rp20.000)
        $this->assertEquals(2, $results[0]->targetLineIndex);
        $this->assertEquals(7500.00, $results[0]->discountAmount); // 50% dari 15.000 = 7.500
    }

    public function test_bxgy_scaled_sets_when_purchasing_multiple_trigger_quantities(): void
    {
        // Beli 2 Kopi, Gratis 1 Pastry B (100% diskon)
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'BUY2-GET1-FREE',
            'name' => 'Beli 2 Kopi Gratis 1 Pastry B',
            'type' => PromotionCampaign::TYPE_BUY_X_GET_Y,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_BXGY_TRIGGER,
            'trigger_product_ids' => [$this->kopi->id],
            'min_order_qty' => 2,
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_FREE_PRODUCT,
            'reward_product_id' => $this->pastryB->id,
        ]);

        $campaign->load('rules.rewards');

        // Beli 4 Kopi (2 set) dan 2 Pastry B di keranjang
        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            now: Carbon::parse('2026-10-30 10:00:00'),
            items: [
                ['productId' => $this->kopi->id, 'quantity' => 4, 'unitPrice' => 25000.0, 'lineIndex' => 0],
                ['productId' => $this->pastryB->id, 'quantity' => 2, 'unitPrice' => 15000.0, 'lineIndex' => 1],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->targetLineIndex);
        $this->assertEquals(30000.00, $results[0]->discountAmount); // 2 * 15.000 = 30.000
    }
}
