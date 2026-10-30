<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Services\UnifiedPricingCalculationPipelineService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Shift $shift;
    protected Customer $customer;
    protected Category $category;
    /** @var array<int, Product> */
    protected array $products = [];
    protected Voucher $voucher;
    protected UnifiedPricingCalculationPipelineService $pipeline;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Benchmark Tenant',
            'domain' => 'benchmark.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Benchmark',
            'code' => 'STR-BENCH',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Manager', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Benchmark User',
            'username' => 'bench_user',
            'email' => 'bench_user@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'starting_cash' => 500000,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Benchmark Customer',
            'phone' => '081299887766',
            'email' => 'bench.cust@example.com',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Benchmark Category',
            'is_active' => true,
        ]);

        // Create 10 distinct products
        for ($i = 1; $i <= 10; $i++) {
            $this->products[$i] = Product::create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $this->category->id,
                'name' => "Benchmark Item {$i}",
                'sku' => sprintf('SKU-BENCH-%02d', $i),
                'product_price' => 15000.00 * $i,
                'standard_cost' => 8000.00 * $i,
                'track_inventory' => false,
                'is_active' => true,
            ]);
        }

        // Setup active promotion campaign (Happy Hour / Item discount on items 1 and 2)
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'BENCHPROMO',
            'name' => 'Benchmark 15% Off Item 1 and 2',
            'type' => 'ITEM_DISCOUNT',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDays(2),
            'status' => 'ACTIVE',
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => 'PRODUCT_MATCH',
            'trigger_product_ids' => [$this->products[1]->id, $this->products[2]->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => 'PERCENTAGE_DISCOUNT',
            'discount_percentage' => 15.00,
        ]);

        // Setup active voucher
        $this->voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'BENCHVOUCHER25K',
            'name' => 'Benchmark Voucher 25K',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 25000.00,
            'min_order_amount' => 100000.00,
            'status' => VoucherStatusEnum::ACTIVE,
            'valid_from' => now()->subDays(5),
            'valid_until' => now()->addDays(30),
            'max_uses' => 1000,
            'used_count' => 0,
        ]);

        $this->pipeline = app(UnifiedPricingCalculationPipelineService::class);
    }

    /**
     * Benchmark pipeline performance: 50 iterations with 10 cart items, tax, manual discount,
     * promo rules, and voucher reservation. Asserts p95 <= 25ms.
     */
    public function test_pricing_pipeline_p95_latency_under_sla_threshold(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = [
                'product_id' => $this->products[$i]->id,
                'quantity' => 2,
            ];
        }

        $input = new CheckoutCalculationInputDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            customerId: $this->customer->id,
            items: $items,
            manualDiscountAmount: 15000.00,
            voucherCode: 'BENCHVOUCHER25K',
            hasCashPayment: true,
            serviceChargeRate: 0.05,
            taxRate: 0.11,
            isTaxInclusive: false
        );

        // Warm-up runs (5 iterations) to warm up opcache and lazy service loading
        for ($w = 0; $w < 5; $w++) {
            $warmResult = $this->pipeline->calculate($input);
            $this->assertNotNull($warmResult);
            $this->assertGreaterThan(0, $warmResult->grandTotal);
        }

        // Benchmark runs (50 iterations)
        $iterationCount = 50;
        $executionTimesMs = [];

        for ($i = 0; $i < $iterationCount; $i++) {
            $startTime = hrtime(true);
            $breakdown = $this->pipeline->calculate($input);
            $endTime = hrtime(true);

            $durationMs = ($endTime - $startTime) / 1e6;
            $executionTimesMs[] = $durationMs;

            $this->assertNotNull($breakdown);
            $this->assertCount(10, $breakdown->lineBreakdowns);
            $this->assertGreaterThan(0.0, $breakdown->totalItemDiscounts);
            $this->assertGreaterThan(0.0, $breakdown->totalTransactionDiscounts);
        }

        sort($executionTimesMs);

        // Compute 95th percentile (index ceil(0.95 * 50) - 1 = 47)
        $p95Index = (int) ceil(0.95 * $iterationCount) - 1;
        $p95LatencyMs = $executionTimesMs[$p95Index];
        $medianLatencyMs = $executionTimesMs[(int) floor($iterationCount / 2)];
        $minLatencyMs = $executionTimesMs[0];
        $maxLatencyMs = $executionTimesMs[$iterationCount - 1];

        // Output diagnostics for debugging
        fwrite(
            STDERR,
            sprintf(
                "\n[PricingPerformanceBenchmark] Min: %.2fms | Median: %.2fms | p95: %.2fms | Max: %.2fms\n",
                $minLatencyMs,
                $medianLatencyMs,
                $p95LatencyMs,
                $maxLatencyMs
            )
        );

        // SLA threshold: p95 <= 25ms
        $this->assertLessThanOrEqual(
            25.0,
            $p95LatencyMs,
            sprintf(
                'Pricing calculation p95 latency (%.2fms) exceeded SLA threshold of 25.0ms (min: %.2fms, median: %.2fms, max: %.2fms)',
                $p95LatencyMs,
                $minLatencyMs,
                $medianLatencyMs,
                $maxLatencyMs
            )
        );
    }
}
