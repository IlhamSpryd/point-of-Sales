<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Core\Context\TenantContext;
use App\Modules\Reporting\Services\Providers\CustomerAndWorkforceMetricProvider;
use App\Modules\Reporting\Services\Providers\FinanceAndCashflowMetricProvider;
use App\Modules\Reporting\Services\Providers\InventoryAndManufacturingMetricProvider;
use App\Modules\Reporting\Services\Providers\SalesAndPosMetricProvider;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Single Source of Truth (SSOT) orchestrator for KPI definitions and calculations (STR-14, ADR-108).
 * Delegates domain-specific calculation algorithms to focused sub-providers (< 400 lines each).
 */
class MetricRegistry
{
    public const KPI_GROSS_SALES = 'KPI-SAL-01';

    public const KPI_TOTAL_DISCOUNTS = 'KPI-SAL-02';

    public const KPI_NET_SALES = 'KPI-SAL-03';

    public const KPI_AVERAGE_ORDER_VALUE = 'KPI-SAL-04';

    public const KPI_UNITS_PER_TRANSACTION = 'KPI-SAL-05';

    public const KPI_COGS = 'KPI-FIN-01';

    public const KPI_GROSS_PROFIT = 'KPI-FIN-02';

    public const KPI_GROSS_MARGIN_PCT = 'KPI-FIN-03';

    public const KPI_STOCK_VALUE = 'KPI-INV-01';

    public const KPI_INVENTORY_TURNOVER = 'KPI-INV-02';

    public const KPI_DAYS_SALES_OF_INVENTORY = 'KPI-INV-03';

    public const KPI_CUSTOMER_LIFETIME_VALUE = 'KPI-CRM-01';

    public const KPI_ARPC = self::KPI_CUSTOMER_LIFETIME_VALUE;

    public const KPI_REPEAT_PURCHASE_RATE = 'KPI-CRM-02';

    public const KPI_FOOD_COST_PCT = 'KPI-FNB-01';

    public const KPI_BOM_VARIANCE = 'KPI-FNB-01-VAR';

    public const KPI_CHANNEL_MIX = 'KPI-FNB-02';

    public const KPI_ORDER_CHANNEL_MIX = self::KPI_CHANNEL_MIX;

    public const KPI_TABLE_OCCUPANCY = 'KPI-FNB-03';

    public const KPI_TABLE_OCCUPANCY_RATE = self::KPI_TABLE_OCCUPANCY;

    public const KPI_KDS_SLA = 'KPI-FNB-04';

    public const KPI_KDS_SLA_BREACH = self::KPI_KDS_SLA;

    public const KPI_SHRINKAGE_PCT = 'KPI-RTL-01';

    public const KPI_SHRINKAGE_INV = 'KPI-INV-04';

    public const KPI_GMROI = 'KPI-RTL-01-GMROI';

    public const KPI_DEAD_STOCK = 'KPI-RTL-02';

    public const KPI_ABC_ANALYSIS = self::KPI_DEAD_STOCK;

    public const KPI_NEAR_EXPIRED = 'KPI-RTL-03';

    public const KPI_NEAR_EXPIRED_ALERT = self::KPI_NEAR_EXPIRED;

    public const KPI_LABOR_COST_PCT = 'KPI-HRM-01';

    public const KPI_OEE = 'KPI-MFG-01';

    public const KPI_TARGET_ATTAINMENT = 'KPI-PLN-01';

    public const KPI_SALES_TARGET_ATTAINMENT = self::KPI_TARGET_ATTAINMENT;

    protected SalesAndPosMetricProvider $salesProvider;

    protected InventoryAndManufacturingMetricProvider $inventoryProvider;

    protected FinanceAndCashflowMetricProvider $financeProvider;

    protected CustomerAndWorkforceMetricProvider $customerProvider;

    public function __construct(
        protected TenantContext $tenantContext,
        ?SalesAndPosMetricProvider $salesProvider = null,
        ?InventoryAndManufacturingMetricProvider $inventoryProvider = null,
        ?FinanceAndCashflowMetricProvider $financeProvider = null,
        ?CustomerAndWorkforceMetricProvider $customerProvider = null,
    ) {
        $this->salesProvider = $salesProvider ?? new SalesAndPosMetricProvider($this->tenantContext);
        $this->inventoryProvider = $inventoryProvider ?? new InventoryAndManufacturingMetricProvider;
        $this->financeProvider = $financeProvider ?? new FinanceAndCashflowMetricProvider($this->tenantContext);
        $this->customerProvider = $customerProvider ?? new CustomerAndWorkforceMetricProvider;
    }

    /**
     * @return array<string, array{code: string, key: string, name: string, formula: string, authoritative_source: string, unit: string, scale: int, gl_account_code: string|null}>
     */
    public function all(): array
    {
        return array_merge(
            $this->salesProvider->definitions(),
            $this->inventoryProvider->definitions(),
            $this->financeProvider->definitions(),
            $this->customerProvider->definitions()
        );
    }

    public function has(string $codeOrKey): bool
    {
        $definitions = $this->all();
        if (isset($definitions[$codeOrKey])) {
            return true;
        }

        foreach ($definitions as $def) {
            if ($def['key'] === $codeOrKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, key: string, name: string, formula: string, authoritative_source: string, unit: string, scale: int, gl_account_code: string|null}
     */
    public function get(string $codeOrKey): array
    {
        $definitions = $this->all();
        if (isset($definitions[$codeOrKey])) {
            return $definitions[$codeOrKey];
        }

        foreach ($definitions as $def) {
            if ($def['key'] === $codeOrKey) {
                return $def;
            }
        }

        throw new InvalidArgumentException("Metrik '{$codeOrKey}' tidak ditemukan di dalam MetricRegistry.");
    }

    /**
     * @return numeric-string
     */
    public function toNumericString(mixed $value, int $scale = 2): string
    {
        return $this->salesProvider->toNumericString($value, $scale);
    }

    /**
     * @param  array<int, array{price?: mixed, item_price?: mixed, quantity?: mixed, qty?: mixed}>  $items
     */
    public function calculateGrossSalesFromItems(array $items): string
    {
        return $this->salesProvider->calculateGrossSalesFromItems($items);
    }

    public function calculateNetSales(mixed $grossSales, mixed $totalDiscounts = '0.00', mixed $salesReturns = '0.00'): string
    {
        return $this->salesProvider->calculateNetSales($grossSales, $totalDiscounts, $salesReturns);
    }

    public function calculateAverageOrderValue(mixed $netSales, int $orderCount): string
    {
        return $this->salesProvider->calculateAverageOrderValue($netSales, $orderCount);
    }

    public function calculateUnitsPerTransaction(mixed $totalUnits, int $orderCount): string
    {
        return $this->salesProvider->calculateUnitsPerTransaction($totalUnits, $orderCount);
    }

    public function calculateGrossProfit(mixed $netSales, mixed $cogs): string
    {
        return $this->financeProvider->calculateGrossProfit($netSales, $cogs);
    }

    public function calculateGrossMarginPct(mixed $grossProfit, mixed $netSales): string
    {
        return $this->financeProvider->calculateGrossMarginPct($grossProfit, $netSales);
    }

    public function calculateAverageInventoryValue(mixed $openingInventory, mixed $closingInventory): string
    {
        return $this->inventoryProvider->calculateAverageInventoryValue($openingInventory, $closingInventory);
    }

    public function calculateInventoryTurnover(mixed $cogs, mixed $averageInventoryValue, int $scale = 2): string
    {
        return $this->inventoryProvider->calculateInventoryTurnover($cogs, $averageInventoryValue, $scale);
    }

    public function calculateDaysSalesOfInventory(mixed $turnoverRatio, int $periodDays = 365): string
    {
        return $this->inventoryProvider->calculateDaysSalesOfInventory($turnoverRatio, $periodDays);
    }

    public function calculateClv(mixed $totalCustomerRevenue, int $totalTrackedCustomers): string
    {
        return $this->customerProvider->calculateClv($totalCustomerRevenue, $totalTrackedCustomers);
    }

    public function calculateArpc(mixed $registeredCustomerNetSales, int $uniqueRegisteredCustomers): string
    {
        return $this->customerProvider->calculateArpc($registeredCustomerNetSales, $uniqueRegisteredCustomers);
    }

    public function calculateRepeatPurchaseRate(int $repeatCustomerCount, int $totalCustomerCount): string
    {
        return $this->customerProvider->calculateRepeatPurchaseRate($repeatCustomerCount, $totalCustomerCount);
    }

    public function calculateFoodCostPct(mixed $recipeIngredientCost, mixed $netFnbSales): string
    {
        return $this->inventoryProvider->calculateFoodCostPct($recipeIngredientCost, $netFnbSales);
    }

    public function calculateBomVariance(mixed $actualCost, mixed $theoreticalCost): string
    {
        return $this->inventoryProvider->calculateBomVariance($actualCost, $theoreticalCost);
    }

    public function calculateSpendPerCover(mixed $netSales, int $coverCount): string
    {
        return $this->salesProvider->calculateSpendPerCover($netSales, $coverCount);
    }

    public function calculateSlaBreachRate(int $breachedTickets, int $totalTickets): string
    {
        return $this->salesProvider->calculateSlaBreachRate($breachedTickets, $totalTickets);
    }

    public function calculateShrinkagePct(mixed $lostDamagedStockValue, mixed $stockValuationOrCogs): string
    {
        return $this->inventoryProvider->calculateShrinkagePct($lostDamagedStockValue, $stockValuationOrCogs);
    }

    /**
     * @param  array<int, array{adjustment_type?: string, type?: string, loss_value?: mixed, cost_amount?: mixed}>  $adjustments
     */
    public function calculateShrinkageFromAdjustments(array $adjustments, mixed $stockValuationOrCogs): string
    {
        return $this->inventoryProvider->calculateShrinkageFromAdjustments($adjustments, $stockValuationOrCogs);
    }

    public function calculateGmroi(mixed $grossProfit, mixed $averageInventoryValuation, int $scale = 2): string
    {
        return $this->inventoryProvider->calculateGmroi($grossProfit, $averageInventoryValuation, $scale);
    }

    public function calculateTargetAttainment(mixed $actualNetSales, mixed $targetNetSales): string
    {
        return $this->salesProvider->calculateTargetAttainment($actualNetSales, $targetNetSales);
    }

    public function calculateRunRateProjection(mixed $actualNetSalesToDate, int $elapsedDays, int $totalDaysInMonth): string
    {
        return $this->salesProvider->calculateRunRateProjection($actualNetSalesToDate, $elapsedDays, $totalDaysInMonth);
    }

    public function calculateLaborCostPct(mixed $totalLaborCost, mixed $netSales): string
    {
        return $this->customerProvider->calculateLaborCostPct($totalLaborCost, $netSales);
    }

    public function calculateOee(mixed $availability, mixed $performance, mixed $quality): string
    {
        return $this->inventoryProvider->calculateOee($availability, $performance, $quality);
    }

    public function calculateContributionPct(mixed $partValue, mixed $totalValue): string
    {
        return $this->salesProvider->calculateContributionPct($partValue, $totalValue);
    }

    public function calculateGrowthPct(mixed $currentValue, mixed $previousValue): string
    {
        return $this->salesProvider->calculateGrowthPct($currentValue, $previousValue);
    }

    /**
     * @return array{gross_revenue: string, sales_discounts: string, sales_returns: string, net_sales: string, journal_count: int}
     */
    public function computeGlRevenueBreakdown(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?string $accountCode = null
    ): array {
        return $this->financeProvider->computeGlRevenueBreakdown($start, $end, $storeId, $tenantId, $accountCode);
    }

    public function computeGlNetSales(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?string $accountCode = null
    ): string {
        return $this->financeProvider->computeGlNetSales($start, $end, $storeId, $tenantId, $accountCode);
    }

    /**
     * @return array<string, array{date: string, gross_sales: string, discounts: string, returns: string, net_sales: string, order_count: int}>
     */
    public function computeAccrualDailySales(
        Carbon $start,
        Carbon $end,
        ?int $storeId = null,
        ?int $tenantId = null,
        ?int $companyId = null,
        string $timezone = 'Asia/Jakarta'
    ): array {
        return $this->salesProvider->computeAccrualDailySales($start, $end, $storeId, $tenantId, $companyId, $timezone);
    }
}
