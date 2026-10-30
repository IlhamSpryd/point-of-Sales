<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Services\UnifiedPricingCalculationPipelineService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedPricingPipelineComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Shift $shift;
    protected Customer $customer;
    protected Category $category;
    protected Product $productA;
    protected Product $productB;
    protected UnifiedPricingCalculationPipelineService $pipeline;
    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Unified Pipeline Tenant',
            'domain' => 'pipeline.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Pipeline',
            'code' => 'STR-PIPE',
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
            'name' => 'Cashier Pipeline',
            'username' => 'cashier_pipe',
            'email' => 'cashier_pipe@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'starting_cash' => 100000,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pipeline Customer',
            'phone' => '081233445566',
            'email' => 'pipeline.cust@example.com',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Main Menu',
            'is_active' => true,
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Steak Sirloin 200g',
            'sku' => 'SKU-STEAK-01',
            'product_price' => 150000.00,
            'standard_cost' => 80000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Fresh Lemon Tea',
            'sku' => 'SKU-TEA-01',
            'product_price' => 25000.00,
            'standard_cost' => 5000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->pipeline = app(UnifiedPricingCalculationPipelineService::class);
        $this->transactionService = app(TransactionService::class);
    }

    public function test_unified_pipeline_9_steps_calculation_canonical_accuracy(): void
    {
        // 1. Setup Promo Otomatis Item: Diskon 20% untuk Steak Sirloin
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'STEAK20',
            'name' => 'Promo Steak 20%',
            'type' => 'ITEM_DISCOUNT',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDays(2),
            'status' => 'ACTIVE',
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => 'PRODUCT_MATCH',
            'trigger_product_ids' => [$this->productA->id],
        ]);

        PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => 'PERCENTAGE_DISCOUNT',
            'discount_percentage' => 20.00,
        ]);

        // Input keranjang:
        // Line 0: 2x Steak Sirloin @ 150.000 = Gross 300.000, Diskon 20% (60.000) -> Net 240.000
        // Line 1: 2x Lemon Tea @ 25.000 = Gross 50.000, Diskon 0 -> Net 50.000
        // Total Line Subtotal (Langkah 1): 350.000
        // Total Item Discounts (Langkah 2): 60.000
        // Order Subtotal (Langkah 3): 290.000
        // Manual Discount (Langkah 4): Rp 10.000
        // DPP (Langkah 5): 290.000 - 10.000 = 280.000
        // Service Charge (Langkah 6): 5% dari 280.000 = 14.000
        // Tax (Langkah 7): 11% dari DPP (280.000) = 30.800
        // Total before rounding: 280.000 + 14.000 + 30.800 = 324.800
        // Cash Rounding (Langkah 8): floor(324.800 / 100) * 100 = 324.800 (pas kelipatan 100, adj = 0)
        // Grand Total (Langkah 9): 324.800
        $input = new CheckoutCalculationInputDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            customerId: $this->customer->id,
            items: [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 2,
                ],
                [
                    'product_id' => $this->productB->id,
                    'quantity' => 2,
                ],
            ],
            manualDiscountAmount: 10000.00,
            hasCashPayment: true,
            serviceChargeRate: 0.05,
            taxRate: 0.11,
            isTaxInclusive: false
        );

        $breakdown = $this->pipeline->calculate($input);

        $this->assertEquals(350000.00, $breakdown->lineSubtotal);
        $this->assertEquals(60000.00, $breakdown->totalItemDiscounts);
        $this->assertEquals(290000.00, $breakdown->orderSubtotal);
        $this->assertEquals(10000.00, $breakdown->totalTransactionDiscounts);
        $this->assertEquals(280000.00, $breakdown->dpp);
        $this->assertEquals(14000.00, $breakdown->serviceChargeAmount);
        $this->assertEquals(30800.00, $breakdown->taxAmount);
        $this->assertEquals(0.00, $breakdown->cashRoundingAdjustment);
        $this->assertEquals(324800.00, $breakdown->grandTotal);

        // Verifikasi alokasi diskon transaksi pada baris
        $this->assertCount(2, $breakdown->lineBreakdowns);
        $line0 = $breakdown->lineBreakdowns[0];
        $line1 = $breakdown->lineBreakdowns[1];

        // Net Line 0: 240.000, Net Line 1: 50.000
        // Total Net: 290.000. Alokasi Rp 10.000:
        // Line 0: round(10.000 * 240.000 / 290.000) = 8.275,86
        // Line 1: 1.724,14
        // Total alokasi harus persis 10.000 (Largest Remainder Method)
        $totalAlloc = $line0['allocated_transaction_discount'] + $line1['allocated_transaction_discount'];
        $this->assertEquals(10000.00, $totalAlloc);
        $this->assertEquals('Steak Sirloin 200g', $line0['product_name_snapshot']);
        $this->assertEquals(80000.00, $line0['hpp_snapshot']);
    }

    public function test_cash_rounding_adjustment_floors_to_nearest_100(): void
    {
        // Total ganjil yang butuh pembulatan:
        // Gross: 1x Steak 150.000, DPP: 150.000, Tax 11%: 16.500 -> Total 166.500
        // Tambah diskon manual 1.234 -> DPP = 148.766
        // Tax (11%): 16.364,26
        // Total before rounding: 165.130,26
        // Rounding down to nearest 100: floor(165130.26 / 100) * 100 = 165.100
        // Rounding adjustment = 165.100 - 165.130,26 = -30.26
        $input = new CheckoutCalculationInputDto(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            channel: 'pos_cashier',
            customerId: $this->customer->id,
            items: [
                ['product_id' => $this->productA->id, 'quantity' => 1],
            ],
            manualDiscountAmount: 1234.00,
            hasCashPayment: true,
            serviceChargeRate: 0.0,
            taxRate: 0.11,
            isTaxInclusive: false
        );

        $breakdown = $this->pipeline->calculate($input);

        $this->assertEquals(148766.00, $breakdown->dpp);
        $this->assertEquals(16364.26, $breakdown->taxAmount);
        $this->assertEquals(-30.26, $breakdown->cashRoundingAdjustment);
        $this->assertEquals(165100.00, $breakdown->grandTotal);
    }

    public function test_transaction_service_saves_all_pipeline_snapshots(): void
    {
        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 1,
                    'unit_price' => 150000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 166500,
                ],
            ],
            'cash_received' => 166500,
            'applied_promotions_summary' => [
                ['type' => 'NONE', 'description' => 'Standard Checkout'],
            ],
        ];

        $order = $this->transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);

        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // Verifikasi snapshot pada orders table
        $this->assertEquals(150000.00, (float) $order->dpp_amount);
        $this->assertEquals(0.00, (float) $order->rounding_adjustment);
        $this->assertNotNull($order->applied_promotions_summary);

        // Verifikasi snapshot pada order_items table
        $item = $order->orderItems()->first();
        $this->assertNotNull($item);
        $this->assertEquals('Steak Sirloin 200g', $item->product_name_snapshot);
        $this->assertEquals($this->productA->product_code, $item->sku_snapshot);
        $this->assertEquals(80000.00, (float) $item->hpp_snapshot);
        $this->assertEquals('CATALOG', $item->price_source);
    }
}
