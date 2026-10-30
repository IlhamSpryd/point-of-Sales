<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Services\MarginSimulationService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginSimulationCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $managerUser;
    protected User $ownerUser;
    protected Category $category;
    protected Product $normalProduct;
    protected Product $lowMarginProduct;
    protected MarginSimulationService $service;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Margin Simulation Tenant',
            'domain' => 'margin-sim.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Sim',
            'code' => 'STR-SIM',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $this->managerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Manager User',
            'username' => 'mgr_sim',
            'email' => 'mgr.sim@test.com',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'is_active' => true,
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Owner User',
            'username' => 'owner_sim',
            'email' => 'owner.sim@test.com',
            'password' => bcrypt('password'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Simulation Category',
            'is_active' => true,
        ]);

        // Produk 1: Harga Rp 100.000, HPP Rp 40.000 (Marjin Sehat 60%)
        $this->normalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Sehat Burger',
            'product_price' => 100000.00,
            'standard_cost' => 40000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        // Produk 2: Harga Rp 50.000, HPP Rp 45.000 (Marjin Tipis 10%)
        $this->lowMarginProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Tipis Fries',
            'product_price' => 50000.00,
            'standard_cost' => 45000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->service = app(MarginSimulationService::class);
    }

    public function test_margin_simulation_calculates_baseline_vs_proposed_accurately(): void
    {
        // Kampanye Diskon 20%
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'DISC20',
            'name' => 'Diskon 20% All',
            'type' => 'ITEM_DISCOUNT',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDays(5),
            'status' => 'DRAFT',
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => 'PRODUCT_MATCH',
            'trigger_product_ids' => [$this->normalProduct->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => 'PERCENTAGE_DISCOUNT',
            'discount_percentage' => 20.00,
        ]);

        $products = [
            ['product' => $this->normalProduct, 'quantity' => 10], // Qty 10
        ];

        $result = $this->service->simulate($campaign, $products);

        // Baseline: 10 * 100.000 = 1.000.000 Rev, 10 * 40.000 = 400.000 COGS -> GP = 600.000 (60%)
        $this->assertEquals(1000000.00, $result->baselineRevenue);
        $this->assertEquals(400000.00, $result->baselineCogs);
        $this->assertEquals(600000.00, $result->baselineGrossProfit);
        $this->assertEquals(60.0, $result->baselineMarginPercent);

        // Proposed: Harga diskon 80.000. Rev = 800.000, COGS = 400.000 -> GP = 400.000 (50%)
        $this->assertEquals(800000.00, $result->proposedRevenue);
        $this->assertEquals(400000.00, $result->proposedCogs);
        $this->assertEquals(400000.00, $result->proposedGrossProfit);
        $this->assertEquals(50.0, $result->proposedMarginPercent);

        // Discount exposure = 200.000, profit variance = -200.000
        $this->assertEquals(200000.00, $result->discountExposure);
        $this->assertEquals(-200000.00, $result->profitVariance);

        $this->assertFalse($result->hasNegativeMarginItems);
        $this->assertFalse($result->isOverallNegativeMargin);
        $this->assertFalse($result->requiresOwnerOverride);
    }

    public function test_negative_margin_guard_detects_loss_and_enforces_owner_override(): void
    {
        // Kampanye Diskon 50% pada Produk Tipis Fries (Harga 50.000, HPP 45.000)
        // Diskon 50% = 25.000 -> Harga Jual Promo = 25.000 < HPP 45.000 (Rugi Rp 20.000 per unit!)
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'DISC50_LOSS',
            'name' => 'Diskon 50% Rugi',
            'type' => 'ITEM_DISCOUNT',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDays(5),
            'status' => 'DRAFT',
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => 'PRODUCT_MATCH',
            'trigger_product_ids' => [$this->lowMarginProduct->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => 'PERCENTAGE_DISCOUNT',
            'discount_percentage' => 50.00,
        ]);

        $products = [
            ['product' => $this->lowMarginProduct, 'quantity' => 5],
        ];

        $result = $this->service->simulate($campaign, $products);

        $this->assertTrue($result->hasNegativeMarginItems);
        $this->assertTrue($result->isOverallNegativeMargin);
        $this->assertTrue($result->requiresOwnerOverride);

        // Proposed Rev: 5 * 25.000 = 125.000, COGS: 5 * 45.000 = 225.000 -> Gross Profit: -100.000
        $this->assertEquals(-100000.00, $result->proposedGrossProfit);

        // Governance: Manager TIDAK BISA publish
        $canManagerPublish = $this->service->canPublish($campaign, $this->managerUser, $products);
        $this->assertFalse($canManagerPublish, 'Manager dilarang mempublikasikan promosi dengan marjin negatif!');

        // Governance: Owner BISA publish (Override)
        $canOwnerPublish = $this->service->canPublish($campaign, $this->ownerUser, $products);
        $this->assertTrue($canOwnerPublish, 'Owner memiliki otorisasi override untuk menerbitkan promosi marjin negatif.');
    }
}
