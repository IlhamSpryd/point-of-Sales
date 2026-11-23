<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

/**
 * Feature 20: Section E: Inventory Intelligence & Stock Control
 *
 * Verifies:
 * - 6-metric operational summary strip (total SKUs, low stock, out of stock, valuation, fast moving, slow moving).
 * - Stock control table with status pills (out_of_stock, low_stock).
 * - Stock status filtering and categorization behavior.
 * - Direct deep links to replenishment / PO workflows.
 * - Honest zero state on empty inventory without division-by-zero or crashes.
 */
class InventoryIntelligenceAndStockControlTest extends BaseE2eReportTestCase
{
    protected DashboardOperationsQueryService $operationsService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->operationsService = app(DashboardOperationsQueryService::class);
    }

    /**
     * T1-FE20-01: Verifies 6-metric operational summary strip.
     */
    public function test_section_e_renders_6_metric_operational_summary_strip(): void
    {
        // 1. Healthy product (stock 50)
        $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Healthy SKU',
            'product_code' => 'SKU-HLT-01',
            'standard_cost' => 10000.00,
            'stock' => 50,
        ]);

        // 2. Low stock product (stock 4 <= 10)
        $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Low Stock SKU',
            'product_code' => 'SKU-LOW-01',
            'standard_cost' => 20000.00,
            'stock' => 4,
        ]);

        // 3. Out of stock product (stock 0)
        $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Depleted SKU',
            'product_code' => 'SKU-OUT-01',
            'standard_cost' => 15000.00,
            'stock' => 0,
        ]);

        $data = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store->id);

        $this->assertArrayHasKey('summary', $data);
        $summary = $data['summary'];

        // Assert all 6 metrics
        $this->assertArrayHasKey('total_skus', $summary);
        $this->assertArrayHasKey('low_stock_count', $summary);
        $this->assertArrayHasKey('out_of_stock_count', $summary);
        $this->assertArrayHasKey('valuation', $summary);
        $this->assertArrayHasKey('fast_moving_count', $summary);
        $this->assertArrayHasKey('slow_moving_count', $summary);

        $this->assertGreaterThanOrEqual(3, $summary['total_skus']);
        $this->assertGreaterThanOrEqual(1, $summary['low_stock_count']);
        $this->assertGreaterThanOrEqual(1, $summary['out_of_stock_count']);

        // Valuation math: (50 * 10,000) + (4 * 20,000) + (0 * 15,000) = 580,000
        $this->assertGreaterThanOrEqual(580000.00, $summary['valuation']);
        $this->assertStringContainsString('Rp', $summary['valuation_formatted']);
    }

    /**
     * T1-FE20-02: Verifies stock control table with status pills.
     */
    public function test_section_e_renders_stock_control_table_with_status_pills(): void
    {
        $outProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Critical Filter Coffee',
            'product_code' => 'SKU-CFC-01',
            'stock' => 0,
        ]);

        $lowProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Low Whole Beans',
            'product_code' => 'SKU-LWB-01',
            'stock' => 3,
        ]);

        $data = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store->id);

        $this->assertArrayHasKey('stock_control_table', $data);
        $table = $data['stock_control_table'];

        $outRow = collect($table)->firstWhere('product_id', $outProduct->id);
        $this->assertNotNull($outRow, 'Out of stock product must appear in stock control table');
        $this->assertEquals('out_of_stock', $outRow['status']);
        $this->assertEquals(0, $outRow['current_stock']);
        $this->assertEquals(10, $outRow['min_stock']);

        $lowRow = collect($table)->firstWhere('product_id', $lowProduct->id);
        $this->assertNotNull($lowRow, 'Low stock product must appear in stock control table');
        $this->assertEquals('low_stock', $lowRow['status']);
        $this->assertEquals(3, $lowRow['current_stock']);
    }

    /**
     * T1-FE20-03: Verifies stock status filtering separates safe vs at-risk items.
     */
    public function test_section_e_stock_status_filter_filters_table_rows(): void
    {
        $safeProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Abundant Vanilla Syrup',
            'product_code' => 'SKU-AVS-01',
            'stock' => 100,
        ]);

        $lowProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Scarce Caramel Syrup',
            'product_code' => 'SKU-SCS-01',
            'stock' => 2,
        ]);

        $data = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store->id);
        $table = $data['stock_control_table'];

        // Safe product with stock 100 must NOT be in stock control table (only items stock <= 10)
        $safeRow = collect($table)->firstWhere('product_id', $safeProduct->id);
        $this->assertNull($safeRow, 'Product with healthy stock must be filtered out of stock control');

        $lowRow = collect($table)->firstWhere('product_id', $lowProduct->id);
        $this->assertNotNull($lowRow, 'Product with scarce stock must be present');
    }

    /**
     * T1-FE20-04: Verifies action links deep link to transfers and PO.
     */
    public function test_section_e_action_links_deep_link_to_transfers_and_po(): void
    {
        $outProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Depleted Oat Milk',
            'product_code' => 'SKU-DOM-01',
            'stock' => 0,
        ]);

        $lowProduct = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Low Almond Milk',
            'product_code' => 'SKU-LAM-01',
            'stock' => 5,
        ]);

        $data = $this->operationsService->getInventoryIntelligence($this->tenant->id, $this->store->id);
        $table = $data['stock_control_table'];

        $outRow = collect($table)->firstWhere('product_id', $outProduct->id);
        $this->assertNotNull($outRow);
        $this->assertEquals('Buat PO Segera', $outRow['action']);
        $this->assertNotEmpty($outRow['deep_link']);

        $lowRow = collect($table)->firstWhere('product_id', $lowProduct->id);
        $this->assertNotNull($lowRow);
        $this->assertEquals('Restock Cabang', $lowRow['action']);
        $this->assertNotEmpty($lowRow['deep_link']);
    }

    /**
     * T1-FE20-05: Verifies empty inventory renders honest zero state without crashing.
     */
    public function test_section_e_empty_inventory_renders_honest_zero_state(): void
    {
        $emptyTenant = $this->createTenant(['name' => 'Empty Inventory Tenant']);

        $data = $this->operationsService->getInventoryIntelligence($emptyTenant->id, null);

        $this->assertIsArray($data);
        $this->assertEquals(0, $data['summary']['total_skus']);
        $this->assertEquals(0, $data['summary']['low_stock_count']);
        $this->assertEquals(0, $data['summary']['out_of_stock_count']);
        $this->assertEquals(0.0, $data['summary']['valuation']);
        $this->assertEquals('Rp 0', $data['summary']['valuation_formatted']);
        $this->assertEquals(0, $data['summary']['fast_moving_count']);
        $this->assertEquals(0, $data['summary']['slow_moving_count']);
        $this->assertEmpty($data['stock_control_table']);
    }
}
