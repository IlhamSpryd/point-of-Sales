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
use App\Modules\Pricing\Services\Evaluators\HappyHourScheduleEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HappyHourPromotionTest extends TestCase
{
    use RefreshDatabase;

    private HappyHourScheduleEvaluator $evaluator;

    private Tenant $tenant;

    private Store $store;

    private Product $productA;

    private Product $productB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->evaluator = new HappyHourScheduleEvaluator;

        $this->tenant = Tenant::create([
            'name' => 'Happy Hour Tenant',
            'slug' => 'happy-hour-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Utama',
            'is_active' => true,
        ]);

        app(\App\Services\Context\TenantContext::class)->setStoreId($this->store->id);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Latte',
            'product_price' => 30000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Teh Tarik',
            'product_price' => 20000,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_happy_hour_applied_during_scheduled_window(): void
    {
        // Happy hour setiap Jumat pukul 14:00 - 17:00, diskon 20%
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'HH-FRIDAY',
            'name' => 'Happy Hour Jumat Sore',
            'type' => PromotionCampaign::TYPE_HAPPY_HOUR,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'allowed_days' => ['Friday'],
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_CART_TOTAL,
            'min_order_amount' => 40000.00,
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_PERCENTAGE_DISCOUNT,
            'discount_percentage' => 20.00,
        ]);

        $campaign->load('rules.rewards');

        // Waktu uji: Jumat pukul 15:30 (Eligible)
        $testTime = Carbon::parse('2026-10-30 15:30:00'); // 2026-10-30 adalah hari Jumat
        $this->assertEquals('Friday', $testTime->format('l'));

        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            now: $testTime,
            items: [
                ['productId' => $this->productA->id, 'quantity' => 2, 'unitPrice' => 30000.0, 'lineIndex' => 0],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertCount(1, $results);
        $this->assertEquals(12000.00, $results[0]->discountAmount); // 20% dari 60.000 = 12.000
    }

    public function test_happy_hour_rejected_outside_time_window(): void
    {
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'HH-FRIDAY',
            'name' => 'Happy Hour Jumat Sore',
            'type' => PromotionCampaign::TYPE_HAPPY_HOUR,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'allowed_days' => ['Friday'],
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_CART_TOTAL,
            'min_order_amount' => 40000.00,
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_PERCENTAGE_DISCOUNT,
            'discount_percentage' => 20.00,
        ]);

        $campaign->load('rules.rewards');

        // Waktu uji: Jumat pukul 18:00 (Lewat jam aktif)
        $testTime = Carbon::parse('2026-10-30 18:00:00');

        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            now: $testTime,
            items: [
                ['productId' => $this->productA->id, 'quantity' => 2, 'unitPrice' => 30000.0, 'lineIndex' => 0],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertEmpty($results);
    }

    public function test_happy_hour_rejected_on_wrong_day(): void
    {
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'HH-FRIDAY',
            'name' => 'Happy Hour Jumat Sore',
            'type' => PromotionCampaign::TYPE_HAPPY_HOUR,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'allowed_days' => ['Friday'],
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_CART_TOTAL,
            'min_order_amount' => 40000.00,
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_PERCENTAGE_DISCOUNT,
            'discount_percentage' => 20.00,
        ]);

        $campaign->load('rules.rewards');

        // Waktu uji: Kamis pukul 15:00 (Hari yang salah)
        $testTime = Carbon::parse('2026-10-29 15:00:00');
        $this->assertEquals('Thursday', $testTime->format('l'));

        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            now: $testTime,
            items: [
                ['productId' => $this->productA->id, 'quantity' => 2, 'unitPrice' => 30000.0, 'lineIndex' => 0],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertEmpty($results);
    }
}
