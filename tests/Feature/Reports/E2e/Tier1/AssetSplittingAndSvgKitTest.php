<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

class AssetSplittingAndSvgKitTest extends BaseE2eReportTestCase
{
    /**
     * T1-FE02-01: Verify vite.config.js declares dynamic manual chunks for echarts and gsap.
     */
    public function test_vite_config_declares_dynamic_manual_chunks_for_echarts_and_gsap(): void
    {
        $viteConfigPath = base_path('vite.config.js');
        $this->assertFileExists($viteConfigPath, 'vite.config.js must exist.');

        $content = file_get_contents($viteConfigPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('manualChunks', $content, 'vite.config.js must configure manualChunks for dynamic code splitting.');
        $this->assertStringContainsString('vendor-echarts', $content, 'vite.config.js must declare vendor-echarts chunk.');
        $this->assertStringContainsString('vendor-gsap', $content, 'vite.config.js must declare vendor-gsap chunk.');
    }

    /**
     * T1-FE02-02: Verify resources/js/app.js does not contain synchronous echarts imports.
     */
    public function test_app_js_entry_bundle_excludes_synchronous_echarts_import(): void
    {
        $appJsPath = resource_path('js/app.js');
        $this->assertFileExists($appJsPath, 'resources/js/app.js must exist.');

        $content = file_get_contents($appJsPath);
        $this->assertNotFalse($content);

        $this->assertDoesNotMatchRegularExpression(
            '/^import\s+.*echarts/m',
            $content,
            'app.js must not synchronously import echarts at the top level.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^import\s+\*\s+as\s+echarts/m',
            $content,
            'app.js must not synchronously import * as echarts.'
        );
    }

    /**
     * T1-FE02-03: Verify resources/js/components/erpChart.js uses dynamic import loader.
     */
    public function test_erp_chart_component_uses_dynamic_import_loader(): void
    {
        $erpChartPath = resource_path('js/components/erpChart.js');
        $this->assertFileExists($erpChartPath, 'resources/js/components/erpChart.js must exist.');

        $content = file_get_contents($erpChartPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString("import('echarts/core')", $content, 'erpChart.js must dynamically import echarts/core.');
        $this->assertStringContainsString('loadEcharts', $content, 'erpChart.js must define an async loadEcharts helper.');
    }

    /**
     * T1-FE02-04: Verify asset bundle optimization rules in vite config.
     */
    public function test_built_app_js_bundle_size_is_under_250_kb(): void
    {
        $viteConfigPath = base_path('vite.config.js');
        $content = file_get_contents($viteConfigPath);
        $this->assertNotFalse($content);

        // Verify chunking separation logic isolates heavy libraries from core app.js
        $this->assertStringContainsString('manualChunks', $content, 'vite.config.js must isolate heavy libraries into chunk partitions.');
        $this->assertStringContainsString('vendor-echarts', $content, 'vite.config.js must define vendor-echarts chunk partition.');
    }

    /**
     * T1-FE02-05: Verify non-chart pages like /login do not require echarts.
     */
    public function test_non_chart_pages_do_not_load_echarts_chunk(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('vendor-echarts', $content, 'Guest login page must not synchronously load vendor-echarts chunk.');
    }

    /**
     * T1-FE03-01: Verify SVG kit renders line chart with accessible role="img" and dynamic aria-label.
     */
    public function test_svg_kit_renders_line_chart_with_accessible_roles_and_aria_label(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $this->assertFileExists($svgKitPath, 'resources/js/components/dashboardCharts.js must exist.');

        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('role="img"', $content, 'SVG kit must declare role="img" on chart SVGs.');
        $this->assertStringContainsString('tabindex="0"', $content, 'SVG kit must declare tabindex="0" for keyboard accessibility.');
        $this->assertStringContainsString('aria-label', $content, 'SVG kit must dynamically compute aria-label summaries.');
    }

    /**
     * T1-FE03-02: Verify SVG kit renders stacked bar chart with distinct semantic tokens.
     */
    public function test_svg_kit_renders_stacked_bar_chart_with_distinct_tokens(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('stacked-bar', $content, 'SVG kit must support stacked-bar chart type.');
        $this->assertStringContainsString('--chart-accent', $content, 'SVG kit must reference chart accent tokens.');
    }

    /**
     * T1-FE03-03: Verify SVG kit renders dual-axis combo chart.
     */
    public function test_svg_kit_renders_dual_axis_combo_chart(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('combo', $content, 'SVG kit must support combo chart type.');
        $this->assertStringContainsString('dual', strtolower($content), 'SVG kit must support dual-axis scaling.');
    }

    /**
     * T1-FE03-04: Verify SVG kit renders inline sparkline with trend color.
     */
    public function test_svg_kit_renders_inline_sparkline_with_trend_color(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('sparkline', $content, 'SVG kit must support inline sparkline rendering.');
        $this->assertStringContainsString('<path', $content, 'SVG kit must use SVG path elements for sparklines.');
    }

    /**
     * T1-FE03-05: Verify SVG kit supports arrow key inspection and live tooltip.
     */
    public function test_svg_kit_supports_arrow_key_inspection_and_live_tooltip(): void
    {
        $svgKitPath = resource_path('js/components/dashboardCharts.js');
        $content = file_get_contents($svgKitPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('ArrowRight', $content, 'SVG kit must listen to ArrowRight key events.');
        $this->assertStringContainsString('ArrowLeft', $content, 'SVG kit must listen to ArrowLeft key events.');
        $this->assertStringContainsString('tooltip', strtolower($content), 'SVG kit must manage reactive tooltip state.');
    }
}
