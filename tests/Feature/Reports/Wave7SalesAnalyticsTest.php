<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Modules\Reports\Services\Analytics\SalesAnalyticsService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave7SalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected SalesAnalyticsService $analyticsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });

        $this->analyticsService = app(SalesAnalyticsService::class);
    }

    /**
     * T-RBI-01: Query perbandingan MoM dan YoY menghasilkan delta persentase dan nominal yang akurat serta scoped ke tenant aktif.
     */
    public function test_t_rbi_01_mom_and_yoy_comparison_calculates_accurate_growth_and_tenant_isolation(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $now = Carbon::parse('2026-10-15 14:00:00');
        $prevMonth = Carbon::parse('2026-09-15 14:00:00');
        $prevYear = Carbon::parse('2025-10-15 14:00:00');

        // Tenant A: Bulan Ini (Oktober 2026) -> 2 pesanan, total Rp 300.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-CUR-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 100000.00,
            'subtotal_amount' => 100000.00,
            'order_date' => '2026-10-05',
            'created_at' => '2026-10-05 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-CUR-2',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => '2026-10-10',
            'created_at' => '2026-10-10 11:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant A: Bulan Lalu (September 2026) -> 1 pesanan, total Rp 200.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-PREV-MOM',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => '2026-09-12',
            'created_at' => '2026-09-12 12:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant A: Tahun Lalu (Oktober 2025) -> 1 pesanan, total Rp 150.000
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A-PREV-YOY',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 150000.00,
            'subtotal_amount' => 150000.00,
            'order_date' => '2025-10-10',
            'created_at' => '2025-10-10 13:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Tenant B: Data pencemar (Oktober 2026) -> Rp 5.000.000 (tidak boleh masuk ke Tenant A)
        $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            Order::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'user_id' => $this->userBBarista->id,
                'order_code' => 'ORD-B-POLLUTE',
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 5000000.00,
                'subtotal_amount' => 5000000.00,
                'order_date' => '2026-10-08',
                'created_at' => '2026-10-08 14:00:00',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        });

        // 1. Uji MoM
        $mom = $this->analyticsService->getMomComparison($now);
        $this->assertEquals('300000.00', $mom['current_revenue']);
        $this->assertEquals('200000.00', $mom['previous_revenue']);
        $this->assertEquals(2, $mom['current_orders']);
        $this->assertEquals(1, $mom['previous_orders']);
        // Pertumbuhan: (300.000 - 200.000) / 200.000 * 100 = 50.00%
        $this->assertEquals('50.00', $mom['revenue_growth_pct']);
        $this->assertEquals('100.00', $mom['orders_growth_pct']);

        // 2. Uji YoY
        $yoy = $this->analyticsService->getYoyComparison($now);
        $this->assertEquals('300000.00', $yoy['current_revenue']);
        $this->assertEquals('150000.00', $yoy['previous_revenue']);
        // Pertumbuhan YoY: (300.000 - 150.000) / 150.000 * 100 = 100.00%
        $this->assertEquals('100.00', $yoy['revenue_growth_pct']);

        // 3. Uji Isolasi Tenant B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $momB = $this->analyticsService->getMomComparison($now);
        $this->assertEquals('5000000.00', $momB['current_revenue']);
        $this->assertEquals(1, $momB['current_orders']);
    }

    /**
     * T-RBI-02: Basket analysis menghasilkan pasangan produk co-occurrence dengan frekuensi dan confidence ratio yang benar tanpa cross-tenant data leak.
     */
    public function test_t_rbi_02_basket_analysis_identifies_co_occurrences_and_confidence(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $cat = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Kopi & Roti',
            'category_code' => 'CAT-KR',
        ]);

        $prodA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Kopi Espresso',
            'product_code' => 'PRD-ESP',
            'product_price' => 25000.00,
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Croissant Butter',
            'product_code' => 'PRD-CRS',
            'product_price' => 30000.00,
        ]);

        $prodC = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Mineral Water',
            'product_code' => 'PRD-WTR',
            'product_price' => 10000.00,
        ]);

        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-31');

        // Order 1: Prod A + Prod B
        $o1 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 55000.00,
            'subtotal_amount' => 55000.00,
            'order_date' => '2026-10-05',
            'created_at' => '2026-10-05 09:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o1->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o1->id, 'product_id' => $prodB->id, 'qty' => 1, 'order_price' => 30000.00, 'order_subtotal' => 30000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        // Order 2: Prod A + Prod B + Prod C
        $o2 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-2',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 65000.00,
            'subtotal_amount' => 65000.00,
            'order_date' => '2026-10-06',
            'created_at' => '2026-10-06 10:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodB->id, 'qty' => 1, 'order_price' => 30000.00, 'order_subtotal' => 30000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);
        OrderItem::create(['order_id' => $o2->id, 'product_id' => $prodC->id, 'qty' => 1, 'order_price' => 10000.00, 'order_subtotal' => 10000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        // Order 3: Prod A saja
        $o3 = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-BA-3',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 25000.00,
            'subtotal_amount' => 25000.00,
            'order_date' => '2026-10-07',
            'created_at' => '2026-10-07 11:00:00',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        OrderItem::create(['order_id' => $o3->id, 'product_id' => $prodA->id, 'qty' => 1, 'order_price' => 25000.00, 'order_subtotal' => 25000.00, 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id]);

        $basket = $this->analyticsService->getBasketAnalysis($start, $end, null, 10, 2);

        $this->assertNotEmpty($basket);
        $pairAB = collect($basket)->first(function ($p) use ($prodA, $prodB) {
            return ($p['product_a_id'] === $prodA->id && $p['product_b_id'] === $prodB->id)
                || ($p['product_a_id'] === $prodB->id && $p['product_b_id'] === $prodA->id);
        });

        $this->assertNotNull($pairAB);
        $this->assertEquals(2, $pairAB['co_occurrence_count']);
        // Total order = 3. Co-occurrence = 2 -> Support = 2/3 * 100 = 66.67%
        $this->assertEquals('66.67', $pairAB['support_pct']);
        // Order memuat Prod A = 3. Pasangan A+B = 2 -> Confidence = 2/3 * 100 = 66.67%
        $this->assertEquals('66.67', $pairAB['confidence_pct']);
    }

    /**
     * T-RBI-03: Heatmap jam sibuk mengembalikan matriks 24x7 dengan agregasi jumlah transaksi dan total omzet.
     */
    public function test_t_rbi_03_peak_hours_heatmap_returns_complete_24x7_matrix(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $monday = Carbon::parse('2026-10-12 14:15:00'); // 2026-10-12 adalah hari Senin

        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-HEAT-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 75000.00,
            'subtotal_amount' => 75000.00,
            'order_date' => $monday->toDateString(),
            'created_at' => $monday->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $heatmap = $this->analyticsService->getPeakHoursHeatmap(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31')
        );

        // 7 Hari dalam seminggu
        $this->assertCount(7, $heatmap);

        // Hari Senin (day_of_week = 1)
        $mondayRow = collect($heatmap)->firstWhere('day_of_week', 1);
        $this->assertNotNull($mondayRow);
        $this->assertEquals('Senin', $mondayRow['day_name']);
        $this->assertCount(24, $mondayRow['hours']);

        // Jam 14:00
        $hour14 = collect($mondayRow['hours'])->firstWhere('hour', 14);
        $this->assertNotNull($hour14);
        $this->assertEquals(1, $hour14['order_count']);
        $this->assertEquals('75000.00', $hour14['total_sales']);

        // Jam lain pada hari Senin (misal jam 03:00) kosong
        $hour03 = collect($mondayRow['hours'])->firstWhere('hour', 3);
        $this->assertNotNull($hour03);
        $this->assertEquals(0, $hour03['order_count']);
        $this->assertEquals('0.00', $hour03['total_sales']);
    }
}
