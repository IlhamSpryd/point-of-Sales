<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\E2e\Tier1;

use App\Modules\Reporting\Services\DashboardOperationsQueryService;
use App\Modules\Reporting\Services\ExecutiveDashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reports\E2e\BaseE2eReportTestCase;

/**
 * Feature 19: Section D: Product Unit Economics & Recipe BOM Drawer
 *
 * Verifies:
 * - 9-column product economics table rendering and math.
 * - COGS and profit margin masking for unauthorized roles.
 * - Slide-over BOM drawer data payload structure.
 * - Raw ingredient quantities, unit costs, and total recipe cost calculation.
 * - Store-level inventory distribution and status badges.
 */
class ProductEconomicsAndBomDrawerTest extends BaseE2eReportTestCase
{
    protected DashboardOperationsQueryService $operationsService;

    protected ExecutiveDashboardService $executiveService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->operationsService = app(DashboardOperationsQueryService::class);
        $this->executiveService = app(ExecutiveDashboardService::class);
    }

    /**
     * T1-FE19-01: Verifies 9-column product unit economics table.
     */
    public function test_section_d_renders_9_column_product_economics_table(): void
    {
        $order = $this->createOrderWithPayment($this->tenant, $this->store, $this->ownerUser, [
            'order_amount' => 150000.00,
            'order_date' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        $product = $this->createProductWithItem($this->tenant, $this->store, $order, [
            'product_name' => 'Signature Espresso Blend',
            'product_code' => 'SKU-ESP-01',
            'product_price' => 50000.00,
            'standard_cost' => 20000.00,
        ], [
            'qty' => 3,
            'order_price' => 50000.00,
            'order_subtotal' => 150000.00,
            'hpp_snapshot' => 20000.00,
            'net_line_total' => 150000.00,
        ]);

        $result = $this->operationsService->getProductUnitEconomics($this->tenant->id, $this->store->id, '30d');

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertNotEmpty($result['items']);

        $matchedItem = collect($result['items'])->firstWhere('product_id', $product->id);
        $this->assertNotNull($matchedItem, 'Product should be listed in unit economics');

        // Verify the 9 canonical columns
        $this->assertArrayHasKey('name', $matchedItem);
        $this->assertArrayHasKey('sku', $matchedItem);
        $this->assertArrayHasKey('category', $matchedItem);
        $this->assertArrayHasKey('units_sold', $matchedItem);
        $this->assertArrayHasKey('revenue', $matchedItem);
        $this->assertArrayHasKey('unit_cogs', $matchedItem);
        $this->assertArrayHasKey('gross_profit', $matchedItem);
        $this->assertArrayHasKey('margin_pct', $matchedItem);
        $this->assertArrayHasKey('sparkline', $matchedItem);

        // Verify unit economics values
        $this->assertEquals('Signature Espresso Blend', $matchedItem['name']);
        $this->assertEquals('SKU-ESP-01', $matchedItem['sku']);
        $this->assertEquals(3, $matchedItem['units_sold']);
        $this->assertEquals(150000.00, $matchedItem['revenue']);
        $this->assertEquals(20000.00, $matchedItem['unit_cogs']);
        $this->assertEquals(90000.00, $matchedItem['gross_profit']); // 150000 - (3 * 20000)
        $this->assertEquals(60.0, $matchedItem['margin_pct']); // (90000 / 150000) * 100
        $this->assertIsArray($matchedItem['sparkline']);
    }

    /**
     * T1-FE19-02: Verifies COGS and profit margin columns/data are masked/restricted when unauthorized.
     */
    public function test_section_d_masks_cogs_and_margin_columns_if_unauthorized(): void
    {
        $cashierUser = $this->createUserWithRole(
            $this->tenant,
            'Kasir',
            ['dashboard.view', 'pos.access'],
            $this->store,
            'cashier'
        );

        $metrics = $this->executiveService->getMetrics($cashierUser, '30d', $this->store->id, null, true);

        $this->assertFalse($metrics['has_profit_margin_permission']);
        $this->assertArrayNotHasKey('cogs', $metrics['accrual_sales']);
        $this->assertArrayNotHasKey('gross_profit', $metrics['accrual_sales']);
        $this->assertArrayNotHasKey('gross_margin_pct', $metrics['accrual_sales']);

        // Check outlet comparison masking as well
        $outletComparison = $this->operationsService->getOutletComparison(
            $this->tenant->id,
            '30d',
            hasMarginPermission: false
        );

        foreach ($outletComparison as $row) {
            $this->assertArrayNotHasKey('cogs', $row);
            $this->assertArrayNotHasKey('gross_profit', $row);
            $this->assertArrayNotHasKey('margin_pct', $row);
        }
    }

    /**
     * T1-FE19-03: Verifies opening product recipe BOM drawer retrieves expected product details.
     */
    public function test_section_d_clicking_row_opens_product_recipe_bom_drawer(): void
    {
        $product = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Vanilla Latte',
            'product_code' => 'SKU-LATTE-01',
            'product_price' => 38000.00,
            'standard_cost' => 14000.00,
        ]);

        $bomData = $this->operationsService->getProductRecipeBom($this->tenant->id, $product->id);

        $this->assertArrayHasKey('product', $bomData);
        $this->assertArrayHasKey('ingredients', $bomData);
        $this->assertArrayHasKey('store_distribution', $bomData);

        $this->assertNotNull($bomData['product']);
        $this->assertEquals($product->id, $bomData['product']['id']);
        $this->assertEquals('Vanilla Latte', $bomData['product']['name']);
        $this->assertEquals('SKU-LATTE-01', $bomData['product']['sku']);
        $this->assertEquals(38000.00, $bomData['product']['selling_price']);
    }

    /**
     * T1-FE19-04: Verifies BOM drawer displays ingredient quantities, unit costs, and total recipe cost.
     */
    public function test_section_d_bom_drawer_displays_ingredient_quantities_and_costs(): void
    {
        $product = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Matcha Latte',
            'product_code' => 'SKU-MATCHA-01',
            'product_price' => 42000.00,
            'standard_cost' => 18000.00,
        ]);

        $ing1Id = DB::table('ingredients')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-MATCHA-POWDER',
            'name' => 'Uji Matcha Powder',
            'unit' => 'gr',
            'cost_per_unit' => 500.00,
            'current_stock' => 5000.00,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ing2Id = DB::table('ingredients')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-FRESH-MILK',
            'name' => 'Fresh Whole Milk',
            'unit' => 'ml',
            'cost_per_unit' => 30.00,
            'current_stock' => 10000.00,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_ingredients')->insert([
            [
                'tenant_id' => $this->tenant->id,
                'product_id' => $product->id,
                'ingredient_id' => $ing1Id,
                'quantity_required' => 20.00, // 20gr * 500 = 10,000
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'product_id' => $product->id,
                'ingredient_id' => $ing2Id,
                'quantity_required' => 200.00, // 200ml * 30 = 6,000
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $bomData = $this->operationsService->getProductRecipeBom($this->tenant->id, $product->id);

        $this->assertCount(2, $bomData['ingredients']);

        $matcha = collect($bomData['ingredients'])->firstWhere('name', 'Uji Matcha Powder');
        $this->assertNotNull($matcha);
        $this->assertEquals(20.0, $matcha['quantity']);
        $this->assertEquals(500.0, $matcha['unit_cost']);
        $this->assertEquals(10000.0, $matcha['total_cost']);

        $milk = collect($bomData['ingredients'])->firstWhere('name', 'Fresh Whole Milk');
        $this->assertNotNull($milk);
        $this->assertEquals(200.0, $milk['quantity']);
        $this->assertEquals(30.0, $milk['unit_cost']);
        $this->assertEquals(6000.0, $milk['total_cost']);

        // Total recipe COGS should be 10,000 + 6,000 = 16,000
        $this->assertEquals(16000.0, $bomData['product']['cogs']);
        $this->assertEquals(round(((42000 - 16000) / 42000) * 100, 1), $bomData['product']['margin_pct']);
    }

    /**
     * T1-FE19-05: Verifies BOM drawer displays store inventory distribution with health status.
     */
    public function test_section_d_bom_drawer_displays_store_inventory_distribution(): void
    {
        $secondStore = $this->createStore($this->tenant, ['name' => 'Cabang Tebet', 'code' => 'STR-TBT']);
        $thirdStore = $this->createStore($this->tenant, ['name' => 'Cabang Senopati', 'code' => 'STR-SNP']);

        $product = $this->createProductWithItem($this->tenant, $this->store, null, [
            'product_name' => 'Croissant Butter',
            'product_code' => 'SKU-CR-01',
        ]);

        foreach ([
            ['store' => $this->store, 'qty' => 50],
            ['store' => $secondStore, 'qty' => 5],
            ['store' => $thirdStore, 'qty' => 0],
        ] as $entry) {
            DB::table('product_stock_balances')->updateOrInsert(
                [
                    'tenant_id' => $this->tenant->id,
                    'store_id' => $entry['store']->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $entry['qty'],
                    'min_stock_level' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $bomData = $this->operationsService->getProductRecipeBom($this->tenant->id, $product->id);

        $distribution = $bomData['store_distribution'];
        $this->assertCount(3, $distribution);

        $dist1 = collect($distribution)->firstWhere('store_id', $this->store->id);
        $this->assertNotNull($dist1);
        $this->assertEquals(50, $dist1['current_stock']);
        $this->assertEquals('healthy', $dist1['status']);

        $dist2 = collect($distribution)->firstWhere('store_id', $secondStore->id);
        $this->assertNotNull($dist2);
        $this->assertEquals(5, $dist2['current_stock']);
        $this->assertEquals('low', $dist2['status']);

        $dist3 = collect($distribution)->firstWhere('store_id', $thirdStore->id);
        $this->assertNotNull($dist3);
        $this->assertEquals(0, $dist3['current_stock']);
        $this->assertEquals('out_of_stock', $dist3['status']);
    }
}
