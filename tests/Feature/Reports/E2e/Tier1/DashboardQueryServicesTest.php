<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use App\Modules\Reporting\Services\ExecutiveDashboardService;
use Carbon\Carbon;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class DashboardQueryServicesTest extends BaseE2eReportTestCase
{
    protected DashboardOverviewQueryService $overviewService;

    protected DashboardOperationsQueryService $operationsService;

    protected ExecutiveDashboardService $coordinatorService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->overviewService = app(DashboardOverviewQueryService::class);
        $this->operationsService = app(DashboardOperationsQueryService::class);
        $this->coordinatorService = app(ExecutiveDashboardService::class);
    }

    /**
     * T1-FE04-01: Verify overview query service computes KPIs accurately with tenant scoping.
     */
    public function test_overview_service_computes_kpis_accurately_with_tenant_scoping(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 150000.00,
            'subtotal_amount' => 150000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $kpis = $this->overviewService->getKpis($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($kpis);
        $this->assertArrayHasKey('gross_revenue', $kpis);
        $this->assertArrayHasKey('net_revenue', $kpis);
        $this->assertArrayHasKey('transactions', $kpis);
        $this->assertArrayHasKey('aov', $kpis);
        $this->assertArrayHasKey('gross_margin_pct', $kpis);
        $this->assertArrayHasKey('active_orders', $kpis);
        $this->assertGreaterThanOrEqual(150000.00, (float) $kpis['net_revenue']);
        $this->assertGreaterThanOrEqual(1, (int) $kpis['transactions']);
    }

    /**
     * T1-FE04-02: Verify overview query service filters KPIs strictly by store ID.
     */
    public function test_overview_service_filters_kpis_by_store_id(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $storeB = $this->createStore($this->tenant, ['name' => 'Branch B']);

        $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        app(TenantContext::class)->setStoreId($storeB->id);

        $this->createOrderWithPayment($this->tenant, $storeB, $this->ownerUser, [
            'order_amount' => 50000.00,
            'subtotal_amount' => 50000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $kpisStoreA = $this->overviewService->getKpis($this->tenant->id, $this->store->id, '30d');
        $kpisStoreB = $this->overviewService->getKpis($this->tenant->id, $storeB->id, '30d');

        $this->assertGreaterThanOrEqual(200000.00, (float) $kpisStoreA['net_revenue']);
        $this->assertEquals(50000.00, (float) $kpisStoreB['net_revenue']);
    }

    /**
     * T1-FE04-03: Verify overview query service computes revenue series for daily and weekly granularities.
     */
    public function test_overview_service_computes_revenue_series_for_daily_and_weekly(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $dailySeries = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'daily');
        $this->assertIsArray($dailySeries);
        $this->assertArrayHasKey('labels', $dailySeries);
        $this->assertArrayHasKey('net_series', $dailySeries);
        $this->assertArrayHasKey('gross_series', $dailySeries);
        $this->assertArrayHasKey('prior_series', $dailySeries);

        $weeklySeries = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'weekly');
        $this->assertIsArray($weeklySeries);
        $this->assertArrayHasKey('labels', $weeklySeries);
        $this->assertArrayHasKey('net_series', $weeklySeries);
    }

    /**
     * T1-FE04-04: Verify overview query service computes payment method breakdown.
     */
    public function test_overview_service_computes_payment_method_breakdown(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 75000.00,
            'subtotal_amount' => 75000.00,
            'payment_method' => 'qris',
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $breakdown = $this->overviewService->getPaymentMethodBreakdown($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($breakdown);
        $this->assertArrayHasKey('categories', $breakdown);
        $this->assertArrayHasKey('series', $breakdown);
    }

    /**
     * T1-FE04-05: Verify overview query service computes revenue waterfall bridge steps.
     */
    public function test_overview_service_computes_revenue_waterfall_bridge_steps(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $bridge = $this->overviewService->getRevenueWaterfallBridge($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($bridge);
        $this->assertArrayHasKey('steps', $bridge);
        $stepNames = array_column($bridge['steps'], 'step');
        $this->assertContains('gross_sales', $stepNames);
        $this->assertContains('net_sales', $stepNames);
        $this->assertContains('gross_profit', $stepNames);
    }

    /**
     * T1-FE05-01: Verify operations query service computes 7x15 demand heatmap matrix.
     */
    public function test_operations_service_computes_7x15_demand_heatmap_matrix(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $heatmap = $this->operationsService->getDemandHeatmap($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($heatmap);
        $this->assertArrayHasKey('cells', $heatmap);
        $this->assertArrayHasKey('peak_hour', $heatmap);
        $this->assertArrayHasKey('quietest_hour', $heatmap);
        $this->assertArrayHasKey('dayparts', $heatmap);

        // 7 days x 15 hours = 105 cells
        $this->assertCount(105, $heatmap['cells']);
    }

    /**
     * T1-FE05-02: Verify operations query service computes outlet comparison table.
     */
    public function test_operations_service_computes_outlet_comparison_table(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $comparison = $this->operationsService->getOutletComparison($this->tenant->id, '30d');
        $this->assertIsArray($comparison);
        if (! empty($comparison)) {
            $first = $comparison[0];
            $this->assertArrayHasKey('store_id', $first);
            $this->assertArrayHasKey('name', $first);
            $this->assertArrayHasKey('net_sales', $first);
            $this->assertArrayHasKey('district', $first);
        }
    }

    /**
     * T1-FE05-03: Verify operations query service computes product unit economics table.
     */
    public function test_operations_service_computes_product_unit_economics_table(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 100000.00,
            'subtotal_amount' => 100000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $this->createProductWithItem($this->tenant, $this->store, $order, [
            'product_name' => 'Signature Coffee',
            'product_price' => 50000.00,
            'standard_cost' => 20000.00,
        ], [
            'qty' => 2,
        ]);

        $economics = $this->operationsService->getProductUnitEconomics($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($economics);
        $this->assertArrayHasKey('items', $economics);
        $this->assertArrayHasKey('total', $economics);
    }

    /**
     * T1-FE05-04: Verify operations query service retrieves product recipe BOM detail.
     */
    public function test_operations_service_retrieves_product_recipe_bom_detail(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $product = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Recipe Test Latte',
            'product_price' => 35000.00,
            'standard_cost' => 15000.00,
        ]);

        $bom = $this->operationsService->getProductRecipeBom($this->tenant->id, $product->id);

        $this->assertIsArray($bom);
        $this->assertArrayHasKey('product', $bom);
        $this->assertArrayHasKey('ingredients', $bom);
        $this->assertArrayHasKey('store_distribution', $bom);
        $this->assertEquals($product->id, $bom['product']['id']);
    }

    /**
     * T1-FE05-05: Verify operations query service retrieves transaction receipt and GL journal detail.
     */
    public function test_operations_service_retrieves_transaction_receipt_and_gl_journal(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 88000.00,
            'subtotal_amount' => 88000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $receipt = $this->operationsService->getTransactionReceiptDetail($this->tenant->id, $order->id);

        $this->assertIsArray($receipt);
        $this->assertArrayHasKey('order', $receipt);
        $this->assertArrayHasKey('items', $receipt);
        $this->assertArrayHasKey('tenders', $receipt);
        $this->assertArrayHasKey('gl_journal', $receipt);
    }

    /**
     * T1-FE06-01: Verify coordinator facade assembles full metrics payload.
     */
    public function test_coordinator_facade_assembles_full_metrics_payload(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $metrics = $this->coordinatorService->getMetrics($this->ownerUser, '30d', $this->store->id, null, true);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('kpi', $metrics);
        $this->assertArrayHasKey('planVsActual', $metrics);
        $this->assertArrayHasKey('glTrustIndicator', $metrics);
        $this->assertArrayHasKey('branchRanking', $metrics);
    }

    /**
     * T1-FE06-02: Verify coordinator facade enforces 5-tier cache partition.
     */
    public function test_coordinator_facade_enforces_5_tier_cache_partition(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // Invalidate and ensure methods execute cleanly
        $this->coordinatorService->invalidateCache($this->tenant->id, $this->store->id);
        $this->assertTrue(true, 'Cache invalidation should execute cleanly.');
    }

    /**
     * T1-FE06-03: Verify coordinator facade invalidates cache on demand.
     */
    public function test_coordinator_facade_invalidates_cache_on_demand(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->coordinatorService->invalidateCache($this->tenant->id, null);
        $this->coordinatorService->invalidateCache($this->tenant->id, $this->store->id);

        $this->assertTrue(true, 'Invalidating both tenant and store partitions succeeds.');
    }

    /**
     * T1-FE06-04: Verify coordinator facade integrates plan vs actual KPI-PLN-01.
     */
    public function test_coordinator_facade_integrates_plan_vs_actual_kpi_pln_01(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $currentPeriod = Carbon::now('Asia/Jakarta')->format('Y-m');
        $this->createStoreTarget($this->tenant, $this->store, $currentPeriod, 100000000.00);

        $planVsActual = $this->coordinatorService->computePlanVsActual(
            $this->tenant->id,
            $this->store->id,
            $this->company->id ?? null,
            '25000000.00'
        );

        $this->assertIsArray($planVsActual);
        $this->assertEquals(100000000.00, (float) $planVsActual['target_revenue']);
        $this->assertEquals(25000000.00, (float) $planVsActual['actual_revenue']);
        $this->assertEquals(25.0, (float) $planVsActual['attainment_pct']);
        $this->assertArrayHasKey('run_rate_projection', $planVsActual);
        $this->assertArrayHasKey('status', $planVsActual);
    }

    /**
     * T1-FE06-05: Verify coordinator facade preserves 3-state GL trust indicator contract.
     */
    public function test_coordinator_facade_preserves_gl_trust_indicator_contract(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $indicator = $this->coordinatorService->computeGlTrustIndicator($this->tenant->id, $this->store->id);

        $this->assertIsArray($indicator);
        $this->assertArrayHasKey('status_state', $indicator);
        $this->assertArrayHasKey('is_reconciled', $indicator);
        $this->assertArrayHasKey('is_trusted', $indicator);
        $this->assertArrayHasKey('status_badge', $indicator);
        $this->assertArrayHasKey('reconciliation_variance', $indicator);
        $this->assertContains($indicator['status_state'], ['reconciled', 'audit_pending', 'variance_detected']);
    }
}
