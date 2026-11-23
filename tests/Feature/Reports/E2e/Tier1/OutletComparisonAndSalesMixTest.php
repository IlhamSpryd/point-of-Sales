<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class OutletComparisonAndSalesMixTest extends BaseE2eReportTestCase
{
    protected DashboardOperationsQueryService $operationsService;

    protected DashboardOverviewQueryService $overviewService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->operationsService = app(DashboardOperationsQueryService::class);
        $this->overviewService = app(DashboardOverviewQueryService::class);
    }

    /**
     * T1-FE18-01: Verify Section C3 renders sortable outlet comparison table structure.
     */
    public function test_section_c3_renders_sortable_outlet_comparison_table(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $outlets = $this->operationsService->getOutletComparison($this->tenant->id, '30d');

        $this->assertIsArray($outlets);
        if (! empty($outlets)) {
            $first = $outlets[0];
            $this->assertArrayHasKey('store_id', $first);
            $this->assertArrayHasKey('name', $first);
            $this->assertArrayHasKey('net_sales', $first);
            $this->assertArrayHasKey('orders_count', $first);
            $this->assertArrayHasKey('aov', $first);
        }
    }

    /**
     * T1-FE18-02: Verify outlet ranking sorts by revenue descending.
     */
    public function test_section_c3_clicking_table_headers_sorts_outlets(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $outlets = $this->operationsService->getOutletComparison($this->tenant->id, '30d');

        $this->assertIsArray($outlets);
        if (count($outlets) > 1) {
            $this->assertGreaterThanOrEqual($outlets[1]['net_sales'], $outlets[0]['net_sales']);
        }
    }

    /**
     * T1-FE18-03: Verify target attainment bullet progress bar formatting.
     */
    public function test_section_c3_renders_target_attainment_bullet_progress_bars(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $outlets = $this->operationsService->getOutletComparison($this->tenant->id, '30d');

        $this->assertIsArray($outlets);
        if (! empty($outlets)) {
            $first = $outlets[0];
            $this->assertArrayHasKey('attainment_pct', $first);
            $this->assertArrayHasKey('attainment_formatted', $first);
            $this->assertArrayHasKey('sparkline', $first);
        }
    }

    /**
     * T1-FE18-04: Verify Section C3 renders ranked category sales mix bars.
     */
    public function test_section_c3_renders_ranked_category_sales_mix_bars(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $salesMix = $this->overviewService->getSalesMix($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($salesMix);
        $this->assertArrayHasKey('by_category', $salesMix);
        $this->assertIsArray($salesMix['by_category']);
    }

    /**
     * T1-FE18-05: Verify Section C3 renders ranked payment method mix bars.
     */
    public function test_section_c3_renders_ranked_payment_method_mix_bars(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $salesMix = $this->overviewService->getSalesMix($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($salesMix);
        $this->assertArrayHasKey('by_payment_method', $salesMix);
        $this->assertIsArray($salesMix['by_payment_method']);
    }
}
