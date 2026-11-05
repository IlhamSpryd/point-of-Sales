<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Modules\Reports\Services\MetricRegistry;
use App\Services\Context\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MetricRegistryTest extends TestCase
{
    private MetricRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MetricRegistry(new TenantContext);
    }

    public function test_registry_contains_all_canonical_metric_definitions(): void
    {
        $all = $this->registry->all();

        $expectedCodes = [
            MetricRegistry::KPI_GROSS_SALES,
            MetricRegistry::KPI_TOTAL_DISCOUNTS,
            MetricRegistry::KPI_NET_SALES,
            MetricRegistry::KPI_AVERAGE_ORDER_VALUE,
            MetricRegistry::KPI_UNITS_PER_TRANSACTION,
            MetricRegistry::KPI_COGS,
            MetricRegistry::KPI_GROSS_PROFIT,
            MetricRegistry::KPI_GROSS_MARGIN_PCT,
            MetricRegistry::KPI_STOCK_VALUE,
            MetricRegistry::KPI_INVENTORY_TURNOVER,
            MetricRegistry::KPI_DAYS_SALES_OF_INVENTORY,
            MetricRegistry::KPI_CUSTOMER_LIFETIME_VALUE,
            MetricRegistry::KPI_FOOD_COST_PCT,
            MetricRegistry::KPI_SHRINKAGE_PCT,
            MetricRegistry::KPI_LABOR_COST_PCT,
            MetricRegistry::KPI_OEE,
        ];

        foreach ($expectedCodes as $code) {
            $this->assertTrue($this->registry->has($code), "Kode metrik {$code} wajib terdaftar.");
            $def = $this->registry->get($code);
            $this->assertSame($code, $def['code']);
            $this->assertNotEmpty($def['name']);
            $this->assertNotEmpty($def['formula']);
            $this->assertNotEmpty($def['authoritative_source']);
            $this->assertTrue($this->registry->has($def['key']));
            $this->assertSame($def, $this->registry->get($def['key']));
        }

        $this->assertSame('4-10000', $this->registry->get(MetricRegistry::KPI_NET_SALES)['gl_account_code']);
    }

    public function test_unknown_metric_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->get('KPI-UNKNOWN-999');
    }

    public function test_sales_metric_formulas_gross_net_aov_and_upt(): void
    {
        $gross = $this->registry->calculateGrossSalesFromItems([
            ['item_price' => '25000.00', 'quantity' => 2],
            ['price' => '50000.00', 'qty' => 3],
        ]);
        $this->assertSame('200000.00', $gross);

        $netSales = $this->registry->calculateNetSales('200000.00', '15000.00', '10000.00');
        $this->assertSame('175000.00', $netSales);

        $aov = $this->registry->calculateAverageOrderValue($netSales, 5);
        $this->assertSame('35000.00', $aov);
        $this->assertSame('0.00', $this->registry->calculateAverageOrderValue($netSales, 0));

        $upt = $this->registry->calculateUnitsPerTransaction(15, 4);
        $this->assertSame('3.75', $upt);
        $this->assertSame('0.00', $this->registry->calculateUnitsPerTransaction(15, 0));
    }

    public function test_financial_metric_formulas_gross_profit_and_gross_margin(): void
    {
        $grossProfit = $this->registry->calculateGrossProfit('1000000.00', '400000.00');
        $this->assertSame('600000.00', $grossProfit);

        $marginPct = $this->registry->calculateGrossMarginPct($grossProfit, '1000000.00');
        $this->assertSame('60.00', $marginPct);
        $this->assertSame('0.00', $this->registry->calculateGrossMarginPct('100.00', '0.00'));
    }

    public function test_inventory_turnover_and_days_sales_of_inventory_formulas(): void
    {
        $avgInv = $this->registry->calculateAverageInventoryValue('40000000.00', '60000000.00');
        $this->assertSame('50000000.00', $avgInv);

        $turnover = $this->registry->calculateInventoryTurnover('250000000.00', $avgInv, 2);
        $this->assertSame('5.00', $turnover);

        $dsi = $this->registry->calculateDaysSalesOfInventory($turnover, 365);
        $this->assertSame('73.00', $dsi);

        $this->assertSame('0.00', $this->registry->calculateInventoryTurnover('100000.00', '0.00', 2));
        $this->assertSame('0.00', $this->registry->calculateDaysSalesOfInventory('0.00', 365));
    }

    public function test_clv_fnb_shrinkage_labor_and_oee_formulas(): void
    {
        $this->assertSame('250000.00', $this->registry->calculateClv('1000000.00', 4));
        $this->assertSame('0.00', $this->registry->calculateClv('1000000.00', 0));

        $this->assertSame('32.00', $this->registry->calculateFoodCostPct('320000.00', '1000000.00'));
        $this->assertSame('1.50', $this->registry->calculateShrinkagePct('15000.00', '1000000.00'));
        $this->assertSame('22.50', $this->registry->calculateLaborCostPct('225000.00', '1000000.00'));

        // OEE dengan input rasio desimal (0.90 * 0.95 * 0.99 = 0.84645 -> 84.65%)
        $this->assertSame('84.65', $this->registry->calculateOee('0.90', '0.95', '0.99'));
        // OEE dengan input persentase (90% * 95% * 99% = 84.65%)
        $this->assertSame('84.65', $this->registry->calculateOee('90', '95', '99'));

        // Growth & Contribution
        $this->assertSame('50.00', $this->registry->calculateGrowthPct('300000.00', '200000.00'));
        $this->assertSame('25.00', $this->registry->calculateContributionPct('25000.00', '100000.00'));
    }
}
