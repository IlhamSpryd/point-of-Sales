<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services\Providers;

/**
 * Metric calculations for Inventory, Warehousing, F&B BOM, Shrinkage, and Manufacturing OEE (STR-14, ADR-108).
 */
class InventoryAndManufacturingMetricProvider
{
    /**
     * @return array<string, array{code: string, key: string, name: string, formula: string, authoritative_source: string, unit: string, scale: int, gl_account_code: string|null}>
     */
    public function definitions(): array
    {
        return [
            'KPI-INV-01' => [
                'code' => 'KPI-INV-01',
                'key' => 'stock_value',
                'name' => 'Total Stock Value',
                'formula' => 'SUM(quantity * unit_cost)',
                'authoritative_source' => 'product_stock_balances, inventory_valuation_snapshots',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '1140',
            ],
            'KPI-INV-02' => [
                'code' => 'KPI-INV-02',
                'key' => 'inventory_turnover',
                'name' => 'Inventory Turnover',
                'formula' => 'Period COGS / Average Inventory Valuation',
                'authoritative_source' => 'product_stock_balances & COGS',
                'unit' => 'Ratio',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-INV-03' => [
                'code' => 'KPI-INV-03',
                'key' => 'days_sales_of_inventory',
                'name' => 'Days Sales of Inventory (DSI)',
                'formula' => 'Period Days / Inventory Turnover Ratio',
                'authoritative_source' => 'product_stock_balances & COGS',
                'unit' => 'Days',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-FNB-01' => [
                'code' => 'KPI-FNB-01',
                'key' => 'food_cost_pct',
                'name' => 'Food Cost (%)',
                'formula' => '(Recipe Raw Material COGS / Net Food Sales) * 100',
                'authoritative_source' => 'ingredient_stock_movements',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5102',
            ],
            'KPI-FNB-01-VAR' => [
                'code' => 'KPI-FNB-01-VAR',
                'key' => 'bom_food_cost_variance',
                'name' => 'BOM Food Cost Variance',
                'formula' => 'Actual Food Cost (Usage + Spoilage) - Theoretical Food Cost (Sales * Recipe)',
                'authoritative_source' => 'product_ingredients, ingredient_stock_movements',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '5102',
            ],
            'KPI-RTL-01' => [
                'code' => 'KPI-RTL-01',
                'key' => 'shrinkage_pct',
                'name' => 'Inventory Shrinkage (%)',
                'formula' => '(Damaged, Expired or Opname Lost Stock Value / Total Stock Valuation) * 100',
                'authoritative_source' => 'stock_adjustments (DAMAGE, EXPIRED, OPNAME_LOSS)',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5201',
            ],
            'KPI-INV-04' => [
                'code' => 'KPI-INV-04',
                'key' => 'shrinkage_inv_pct',
                'name' => 'Inventory Shrinkage Rate (%)',
                'formula' => '(Damaged, Expired or Opname Lost Stock Value / Total Stock Valuation) * 100',
                'authoritative_source' => 'stock_adjustments (DAMAGE, EXPIRED, OPNAME_LOSS)',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5201',
            ],
            'KPI-RTL-01-GMROI' => [
                'code' => 'KPI-RTL-01-GMROI',
                'key' => 'gmroi',
                'name' => 'Gross Margin Return on Inventory Investment (GMROI)',
                'formula' => 'Gross Profit / Average Inventory Valuation',
                'authoritative_source' => 'order_items, product_stock_balances',
                'unit' => 'Ratio',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-RTL-02' => [
                'code' => 'KPI-RTL-02',
                'key' => 'dead_stock_valuation',
                'name' => 'Dead Stock Risk Valuation',
                'formula' => 'SUM(Current Stock Qty * Unit Cost) for SKUs with 0 sales in 60+ days',
                'authoritative_source' => 'product_stock_balances, orders',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '1140',
            ],
            'KPI-RTL-03' => [
                'code' => 'KPI-RTL-03',
                'key' => 'near_expired_valuation',
                'name' => 'Near-Expired Batches Risk Valuation',
                'formula' => 'SUM(Batch Quantity * Unit Cost) for Expiry <= 30 days',
                'authoritative_source' => 'product_batches, ingredient_batches',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => '1140',
            ],
            'KPI-MFG-01' => [
                'code' => 'KPI-MFG-01',
                'key' => 'oee',
                'name' => 'Overall Equipment Effectiveness (OEE)',
                'formula' => 'Availability * Performance * Quality * 100',
                'authoritative_source' => 'kitchen_tickets, pos_devices',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
        ];
    }

    /**
     * @return numeric-string
     */
    public function toNumericString(mixed $value, int $scale = 2): string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            /** @var numeric-string $str */
            $str = (string) $value;

            return bcadd($str, '0', $scale);
        }

        /** @var numeric-string $zero */
        $zero = bcadd('0', '0', $scale);

        return $zero;
    }

    /**
     * Rata-rata Nilai Persediaan = (Persediaan Awal + Persediaan Akhir) / 2.
     */
    public function calculateAverageInventoryValue(mixed $openingInventory, mixed $closingInventory): string
    {
        $open = $this->toNumericString($openingInventory, 2);
        $close = $this->toNumericString($closingInventory, 2);

        return bcdiv(bcadd($open, $close, 2), '2', 2);
    }

    /**
     * KPI-INV-02: Inventory Turnover Ratio = COGS / Average Inventory Value.
     */
    public function calculateInventoryTurnover(mixed $cogs, mixed $averageInventoryValue, int $scale = 2): string
    {
        $cost = $this->toNumericString($cogs, 4);
        $avgInv = $this->toNumericString($averageInventoryValue, 4);

        if (bccomp($avgInv, '0.0000', 4) <= 0) {
            return $this->toNumericString('0', $scale);
        }

        return bcdiv($cost, $avgInv, $scale);
    }

    /**
     * KPI-INV-03: Days Sales of Inventory (DSI) = Period Days / Inventory Turnover Ratio.
     */
    public function calculateDaysSalesOfInventory(mixed $turnoverRatio, int $periodDays = 365): string
    {
        if ($periodDays <= 0) {
            return '0.00';
        }

        $ratio = $this->toNumericString($turnoverRatio, 4);
        if (bccomp($ratio, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcdiv((string) $periodDays, $ratio, 2);
    }

    /**
     * KPI-INV-04 / KPI-RTL-01: Shrinkage % = (Lost or Damaged Stock Value / Stock Valuation or COGS) * 100.
     */
    public function calculateShrinkagePct(mixed $lostDamagedStockValue, mixed $stockValuationOrCogs): string
    {
        return $this->calculateContributionPct($lostDamagedStockValue, $stockValuationOrCogs);
    }

    /**
     * Menghitung nilai rasio penyusutan dari daftar mutasi penyesuaian stok.
     *
     * @param  array<int, array{adjustment_type?: string, type?: string, loss_value?: mixed, cost_amount?: mixed}>  $adjustments
     */
    public function calculateShrinkageFromAdjustments(array $adjustments, mixed $stockValuationOrCogs): string
    {
        $allowedTypes = ['DAMAGE', 'EXPIRED', 'OPNAME_LOSS', 'SPOILAGE'];
        $totalLoss = '0.00';

        foreach ($adjustments as $adj) {
            $type = strtoupper((string) ($adj['adjustment_type'] ?? $adj['type'] ?? ''));
            if (in_array($type, $allowedTypes, true)) {
                $loss = $this->toNumericString($adj['loss_value'] ?? $adj['cost_amount'] ?? '0.00', 2);
                $totalLoss = bcadd($totalLoss, $loss, 2);
            }
        }

        return $this->calculateShrinkagePct($totalLoss, $stockValuationOrCogs);
    }

    /**
     * KPI-RTL-01-GMROI: Gross Margin Return on Inventory Investment = Gross Profit / Average Inventory Valuation.
     */
    public function calculateGmroi(mixed $grossProfit, mixed $averageInventoryValuation, int $scale = 2): string
    {
        $profit = $this->toNumericString($grossProfit, 4);
        $avgInv = $this->toNumericString($averageInventoryValuation, 4);

        if (bccomp($avgInv, '0.0000', 4) <= 0) {
            return $this->toNumericString('0', $scale);
        }

        return bcdiv($profit, $avgInv, $scale);
    }

    /**
     * KPI-FNB-01: Food Cost % = (Recipe Ingredient Cost / Net F&B Sales) * 100.
     */
    public function calculateFoodCostPct(mixed $recipeIngredientCost, mixed $netFnbSales): string
    {
        return $this->calculateContributionPct($recipeIngredientCost, $netFnbSales);
    }

    /**
     * KPI-FNB-01-VAR: BOM Food Cost Variance = Actual Food Cost - Theoretical Food Cost.
     */
    public function calculateBomVariance(mixed $actualCost, mixed $theoreticalCost): string
    {
        $act = $this->toNumericString($actualCost, 2);
        $theo = $this->toNumericString($theoreticalCost, 2);

        return bcsub($act, $theo, 2);
    }

    /**
     * KPI-MFG-01: Overall Equipment Effectiveness (OEE %) = Availability * Performance * Quality * 100.
     */
    public function calculateOee(mixed $availability, mixed $performance, mixed $quality): string
    {
        $norm = function (mixed $val): string {
            $num = $this->toNumericString($val, 6);
            if (bccomp($num, '0.000000', 6) <= 0) {
                return '0.000000';
            }
            if (bccomp($num, '1.000000', 6) > 0) {
                return bcdiv($num, '100', 6);
            }

            return $num;
        };

        $a = $this->toNumericString($norm($availability), 6);
        $p = $this->toNumericString($norm($performance), 6);
        $q = $this->toNumericString($norm($quality), 6);

        $product = bcmul(bcmul($a, $p, 6), $q, 6);
        $raw = bcmul($product, '100', 4);

        return number_format(round((float) $raw, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    public function calculateContributionPct(mixed $partValue, mixed $totalValue): string
    {
        $part = $this->toNumericString($partValue, 4);
        $total = $this->toNumericString($totalValue, 4);

        if (bccomp($total, '0.0000', 4) <= 0) {
            return '0.00';
        }

        $raw = bcmul(bcdiv($part, $total, 6), '100', 4);

        return number_format(round((float) $raw, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
