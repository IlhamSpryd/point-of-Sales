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
use App\Modules\Pricing\Services\Evaluators\BundlePackageEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundlePackageTest extends TestCase
{
    use RefreshDatabase;

    private BundlePackageEvaluator $evaluator;

    private Tenant $tenant;

    private Store $store;

    private Product $burger;

    private Product $fries;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->evaluator = new BundlePackageEvaluator;

        $this->tenant = Tenant::create([
            'name' => 'Bundle Test Tenant',
            'slug' => 'bundle-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(\App\Services\Context\TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Bundle',
            'is_active' => true,
        ]);

        app(\App\Services\Context\TenantContext::class)->setStoreId($this->store->id);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Makanan Cepat Saji',
        ]);

        $this->burger = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Beef Burger Spesial',
            'product_price' => 30000,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->fries = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'French Fries',
            'product_price' => 15000,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_bundle_combo_discount_allocated_to_components(): void
    {
        // Paket Kombo Burger + Fries: Harga Normal 45.000 -> Harga Paket 35.000 (Diskon 10.000)
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'COMBO-BURGER-FRIES',
            'name' => 'Paket Kombo Burger Mantap',
            'type' => PromotionCampaign::TYPE_BUNDLE_PACKAGE,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_PRODUCT_MATCH,
            'trigger_product_ids' => [$this->burger->id, $this->fries->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_SPECIAL_PRICE_PRODUCT,
            'discount_fixed_amount' => 35000.00, // Harga paket kombo
        ]);

        $campaign->load('rules.rewards');

        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            now: Carbon::parse('2026-10-30 12:00:00'),
            items: [
                ['productId' => $this->burger->id, 'quantity' => 1, 'unitPrice' => 30000.0, 'lineIndex' => 0],
                ['productId' => $this->fries->id, 'quantity' => 1, 'unitPrice' => 15000.0, 'lineIndex' => 1],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertCount(2, $results);

        $totalAllocated = 0.0;
        foreach ($results as $res) {
            $totalAllocated += $res->discountAmount;
        }

        // Total diskon bundle adalah 45.000 - 35.000 = 10.000
        $this->assertEquals(10000.00, $totalAllocated);

        // Alokasi proporsional:
        // Burger (30.000 / 45.000) * 10.000 = 6.666,67 -> round = 6.666,67 (atau 6.670)
        // Fries menyerap sisanya agar total persis 10.000
        $this->assertEquals(6666.67, $results[0]->discountAmount);
        $this->assertEquals(3333.33, $results[1]->discountAmount);
    }

    public function test_bundle_rejected_if_any_component_is_missing(): void
    {
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'COMBO-BURGER-FRIES',
            'name' => 'Paket Kombo Burger Mantap',
            'type' => PromotionCampaign::TYPE_BUNDLE_PACKAGE,
            'start_datetime' => '2026-01-01 00:00:00',
            'end_datetime' => '2026-12-31 23:59:59',
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_PRODUCT_MATCH,
            'trigger_product_ids' => [$this->burger->id, $this->fries->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_SPECIAL_PRICE_PRODUCT,
            'discount_fixed_amount' => 35000.00,
        ]);

        $campaign->load('rules.rewards');

        // Keranjang HANYA berisi burger, TANPA kentang goreng
        $context = new CartEvaluationContextDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            now: Carbon::parse('2026-10-30 12:00:00'),
            items: [
                ['productId' => $this->burger->id, 'quantity' => 1, 'unitPrice' => 30000.0, 'lineIndex' => 0],
            ]
        );

        $results = $this->evaluator->evaluate($campaign, $context);

        $this->assertEmpty($results);
    }
}
