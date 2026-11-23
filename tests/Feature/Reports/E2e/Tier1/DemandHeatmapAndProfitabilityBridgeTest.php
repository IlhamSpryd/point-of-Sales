<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class DemandHeatmapAndProfitabilityBridgeTest extends BaseE2eReportTestCase
{
    protected DashboardOverviewQueryService $overviewService;

    protected DashboardOperationsQueryService $operationsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->overviewService = app(DashboardOverviewQueryService::class);
        $this->operationsService = app(DashboardOperationsQueryService::class);
    }

    /**
     * T1-FE17-01: Verify Section C2 renders revenue waterfall bridge steps.
     */
    public function test_section_c2_renders_revenue_waterfall_bridge_bars(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $bridge = $this->overviewService->getRevenueWaterfallBridge($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($bridge);
        $this->assertArrayHasKey('steps', $bridge);
        $steps = $bridge['steps'];
        $this->assertNotEmpty($steps);

        $stepKeys = array_column($steps, 'step');
        $this->assertContains('gross_sales', $stepKeys);
        $this->assertContains('net_sales', $stepKeys);
    }

    /**
     * T1-FE17-02: Verify waterfall steps categorize positive and deductive steps.
     */
    public function test_section_c2_waterfall_colors_positive_and_deductive_steps(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $bridge = $this->overviewService->getRevenueWaterfallBridge($this->tenant->id, $this->store->id, '30d');
        $steps = $bridge['steps'];

        $types = array_column($steps, 'type');
        $this->assertContains('start', $types);
        $this->assertContains('deduction', $types);
        $this->assertContains('subtotal', $types);
    }

    /**
     * T1-FE17-03: Verify Section C2 renders 7x15 Day x Hour demand heatmap grid.
     */
    public function test_section_c2_renders_7x15_day_hour_demand_heatmap(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $heatmap = $this->operationsService->getDemandHeatmap($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($heatmap);
        $this->assertArrayHasKey('cells', $heatmap);
        $this->assertCount(105, $heatmap['cells'], 'Heatmap must contain 105 cells (7 days x 15 hours).');
    }

    /**
     * T1-FE17-04: Verify Section C2 heatmap identifies peak and quietest operating hours.
     */
    public function test_section_c2_heatmap_identifies_peak_and_quietest_hours(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $heatmap = $this->operationsService->getDemandHeatmap($this->tenant->id, $this->store->id, '30d');

        $this->assertArrayHasKey('peak_hour', $heatmap);
        $this->assertArrayHasKey('quietest_hour', $heatmap);
        $this->assertArrayHasKey('hour', $heatmap['peak_hour']);
        $this->assertArrayHasKey('hour', $heatmap['quietest_hour']);
    }

    /**
     * T1-FE17-05: Verify Section C2 heatmap aggregates 4 operational dayparts.
     */
    public function test_section_c2_heatmap_daypart_tabs_filter_time_blocks(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $heatmap = $this->operationsService->getDemandHeatmap($this->tenant->id, $this->store->id, '30d');

        $this->assertArrayHasKey('dayparts', $heatmap);
        $dayparts = $heatmap['dayparts'];
        $this->assertCount(4, $dayparts);
        $this->assertArrayHasKey('label', $dayparts[0]);
        $this->assertArrayHasKey('transactions', $dayparts[0]);
        $this->assertArrayHasKey('revenue', $dayparts[0]);
    }
}
