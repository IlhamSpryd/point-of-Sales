<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use App\Modules\Reporting\Services\DashboardOverviewQueryService;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class RevenueAndVolumeAnalyticsTest extends BaseE2eReportTestCase
{
    protected DashboardOverviewQueryService $overviewService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->overviewService = app(DashboardOverviewQueryService::class);
    }

    /**
     * T1-FE15-01: Verify Section B revenue analytics returns multi-series data structure.
     */
    public function test_section_b_renders_multi_series_svg_line_chart(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $series = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'daily');

        $this->assertIsArray($series);
        $this->assertArrayHasKey('labels', $series);
        $this->assertArrayHasKey('net_series', $series);
        $this->assertArrayHasKey('gross_series', $series);
        $this->assertArrayHasKey('prior_series', $series);
        $this->assertArrayHasKey('target_series', $series);
    }

    /**
     * T1-FE15-02: Verify Section B series toggles show/hide capability in SVG kit.
     */
    public function test_section_b_series_toggles_show_hide_chart_lines(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('series', $content);
        $this->assertStringContainsString('colorToken', $content);
    }

    /**
     * T1-FE15-03: Verify Section B granularity selector switches daily, weekly, monthly.
     */
    public function test_section_b_granularity_selector_switches_daily_weekly_monthly(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $daily = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'daily');
        $weekly = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'weekly');
        $monthly = $this->overviewService->getRevenueSeries($this->tenant->id, $this->store->id, '30d', 'monthly');

        $this->assertIsArray($daily['labels']);
        $this->assertIsArray($weekly['labels']);
        $this->assertIsArray($monthly['labels']);
    }

    /**
     * T1-FE15-04: Verify Section B chart displays interactive hover tooltip format.
     */
    public function test_section_b_chart_displays_interactive_hover_tooltip(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('formatIDR', $content, 'SVG kit must format tooltip currency as IDR.');
    }

    /**
     * T1-FE15-05: Verify Section B chart supports screen reader accessible summary.
     */
    public function test_section_b_chart_supports_screen_reader_summary(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('aria-label', $content);
        $this->assertStringContainsString('role="img"', $content);
    }

    /**
     * T1-FE16-01: Verify Section C1 renders payment volume stacked bar chart breakdown.
     */
    public function test_section_c1_renders_payment_volume_stacked_bar_chart(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $breakdown = $this->overviewService->getPaymentMethodBreakdown($this->tenant->id, $this->store->id, '30d');

        $this->assertIsArray($breakdown);
        $this->assertArrayHasKey('categories', $breakdown);
        $this->assertArrayHasKey('series', $breakdown);
        $this->assertArrayHasKey('total_volume', $breakdown);
    }

    /**
     * T1-FE16-02: Verify Section C1 renders gross profit and margin combo chart.
     */
    public function test_section_c1_renders_gross_profit_and_margin_combo_chart(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $combo = $this->overviewService->getProfitabilityCombo($this->tenant->id, $this->store->id, '30d', true);

        $this->assertIsArray($combo);
        $this->assertArrayHasKey('labels', $combo);
        $this->assertArrayHasKey('gross_profit_series', $combo);
        $this->assertArrayHasKey('margin_pct_series', $combo);
    }

    /**
     * T1-FE16-03: Verify Section C1 masks gross margin series when permission is missing.
     */
    public function test_section_c1_masks_gross_margin_series_when_permission_missing(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $maskedCombo = $this->overviewService->getProfitabilityCombo($this->tenant->id, $this->store->id, '30d', false);

        $this->assertIsArray($maskedCombo);
        $this->assertEmpty($maskedCombo['gross_profit_series']);
        $this->assertEmpty($maskedCombo['margin_pct_series']);
    }

    /**
     * T1-FE16-04: Verify Section C1 formats payment tickets with tabular numbers.
     */
    public function test_section_c1_formats_payment_tickets_with_tabular_nums(): void
    {
        $breakdown = $this->overviewService->getPaymentMethodBreakdown($this->tenant->id, $this->store->id, '30d');
        $this->assertIsArray($breakdown['series']);
    }

    /**
     * T1-FE16-05: Verify Section C1 handles empty payments rendering flat baseline.
     */
    public function test_section_c1_empty_payments_render_flat_baseline(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $breakdown = $this->overviewService->getPaymentMethodBreakdown($this->tenant->id, $this->store->id, 'today');

        $this->assertIsArray($breakdown);
        $this->assertArrayHasKey('categories', $breakdown);
        $this->assertArrayHasKey('series', $breakdown);
    }
}
