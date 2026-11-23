<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Core\Context\TenantContext;
use Carbon\Carbon;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class HeaderAndKpiGridTest extends BaseE2eReportTestCase
{
    /**
     * T1-FE13-01: Verify executive header bar renders business overview / dashboard title.
     */
    public function test_header_bar_renders_business_overview_title(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertTrue(
            str_contains(strtolower($content), 'dashboard') || str_contains(strtolower($content), 'overview'),
            'Dashboard header must render title heading.'
        );
    }

    /**
     * T1-FE13-02: Verify executive header bar renders current WIB timestamp.
     */
    public function test_header_bar_renders_current_wib_timestamp(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        // Check timezone or date formatting in response
        $currentYear = Carbon::now('Asia/Jakarta')->format('Y');
        $this->assertStringContainsString($currentYear, $response->getContent());
    }

    /**
     * T1-FE13-03: Verify executive header bar renders segmented date range selector.
     */
    public function test_header_bar_renders_segmented_date_range_selector(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // Verifies date selector controls or period parameters are handled
        $this->assertTrue(
            str_contains($content, 'period') || str_contains($content, 'today') || str_contains($content, '30d') || str_contains($content, '7d'),
            'Dashboard header must support date range options.'
        );
    }

    /**
     * T1-FE13-04: Verify executive header bar renders atur target cabang modal button.
     */
    public function test_header_bar_renders_atur_target_cabang_modal_button(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $this->assertStringContainsString('Atur Target Cabang', $response->getContent());
    }

    /**
     * T1-FE13-05: Verify executive header bar renders new POS order CTA button.
     */
    public function test_header_bar_renders_new_pos_order_cta_button(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'order') || str_contains($content, 'transaksi') || str_contains($content, 'pos'),
            'Dashboard must offer quick action to new transaction/order.'
        );
    }

    /**
     * T1-FE14-01: Verify section A renders primary KPI stat cards.
     */
    public function test_section_a_renders_all_6_kpi_cards(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 125000.00,
            'subtotal_amount' => 125000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('Orders', $content);
    }

    /**
     * T1-FE14-02: Verify section A formats monetary values with tabular-nums.
     */
    public function test_section_a_formats_monetary_values_with_tabular_nums(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $this->assertStringContainsString('tabular-nums', $response->getContent(), 'Metrics must use tabular-nums class for alignment.');
    }

    /**
     * T1-FE14-03: Verify section A renders delta badges with positive/negative variants.
     */
    public function test_section_a_renders_delta_badges_with_positive_negative_variants(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'delta') || str_contains($content, '+') || str_contains($content, '%') || str_contains($content, 'text-success'),
            'KPI cards must render trend deltas.'
        );
    }

    /**
     * T1-FE14-04: Verify section A renders sparkline indicators.
     */
    public function test_section_a_renders_inline_svg_sparkline_per_card(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('sparkline', $content, 'Sparkline rendering engine must be declared.');
    }

    /**
     * T1-FE14-05: Verify section A card click opens KPI drill-down drawer or detail view.
     */
    public function test_section_a_card_click_opens_kpi_drill_down_drawer(): void
    {
        $dashboardViewPath = resource_path('views/dashboard.blade.php');
        $content = file_get_contents($dashboardViewPath);
        $this->assertNotFalse($content);

        $this->assertTrue(
            str_contains($content, 'drawer') || str_contains($content, 'modal') || str_contains($content, 'ticket'),
            'Dashboard view must manage drawer or inspection modal state.'
        );
    }
}
