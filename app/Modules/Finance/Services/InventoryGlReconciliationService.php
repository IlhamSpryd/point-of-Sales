<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\IngredientStockBalance;
use App\Models\ProductStockBalance;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\InventoryValuationSnapshot;

class InventoryGlReconciliationService
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Membandingkan total valuasi operasional M4 dengan saldo akun GL Persediaan di M7.
     *
     * @return array{
     *     tenant_id: int,
     *     accounting_period_id: int,
     *     store_id: int|null,
     *     m4_retail_valuation: string,
     *     gl_retail_balance: string,
     *     retail_variance: string,
     *     m4_ingredient_valuation: string,
     *     gl_ingredient_balance: string,
     *     ingredient_variance: string,
     *     total_m4_valuation: string,
     *     total_gl_valuation: string,
     *     total_variance: string,
     *     is_balanced: bool
     * }
     */
    public function reconcileStoreValuation(int $tenantId, int $accountingPeriodId, ?int $storeId = null): array
    {
        // 1. Hitung valuasi operasional M4 untuk produk retail
        $productQuery = ProductStockBalance::where('tenant_id', $tenantId);
        if ($storeId !== null) {
            $productQuery->where('store_id', $storeId);
        }
        $products = $productQuery->get();

        $m4RetailValuation = '0.00';
        foreach ($products as $psb) {
            $qty = (string) ($psb->quantity ?? 0);
            $avgCost = (string) ($psb->average_unit_cost ?? 0);
            $val = bcmul($qty, $avgCost, 2);
            $m4RetailValuation = bcadd($m4RetailValuation, $val, 2);
        }

        // 2. Hitung valuasi operasional M4 untuk bahan baku F&B
        $ingredientQuery = IngredientStockBalance::where('tenant_id', $tenantId);
        if ($storeId !== null) {
            $ingredientQuery->where('store_id', $storeId);
        }
        $ingredients = $ingredientQuery->get();

        $m4IngredientValuation = '0.00';
        foreach ($ingredients as $isb) {
            $qty = (string) ($isb->quantity ?? 0);
            $avgCost = (string) ($isb->average_unit_cost ?? 0);
            $val = bcmul($qty, $avgCost, 2);
            $m4IngredientValuation = bcadd($m4IngredientValuation, $val, 2);
        }

        // 3. Ambil saldo akun GL dari account_period_balances
        $retailAcc = $this->coaService->getAccountBySystemKey('INVENTORY_RETAIL_PRODUCT', $tenantId);
        $ingredientAcc = $this->coaService->getAccountBySystemKey('INVENTORY_FNB_INGREDIENT', $tenantId);

        $storeScopeKey = $storeId ?? 0;

        $retailBalRow = AccountPeriodBalance::where('tenant_id', $tenantId)
            ->where('accounting_period_id', $accountingPeriodId)
            ->where('account_id', $retailAcc->id)
            ->where('store_scope_key', $storeScopeKey)
            ->first();

        $glRetailBalance = $retailBalRow
            ? bcsub((string) ($retailBalRow->closing_debit ?? 0), (string) ($retailBalRow->closing_credit ?? 0), 2)
            : '0.00';

        $ingredientBalRow = AccountPeriodBalance::where('tenant_id', $tenantId)
            ->where('accounting_period_id', $accountingPeriodId)
            ->where('account_id', $ingredientAcc->id)
            ->where('store_scope_key', $storeScopeKey)
            ->first();

        $glIngredientBalance = $ingredientBalRow
            ? bcsub((string) ($ingredientBalRow->closing_debit ?? 0), (string) ($ingredientBalRow->closing_credit ?? 0), 2)
            : '0.00';

        // 4. Hitung variansi (M4 - GL)
        $retailVariance = bcsub($m4RetailValuation, $glRetailBalance, 2);
        $ingredientVariance = bcsub($m4IngredientValuation, $glIngredientBalance, 2);

        $totalM4Valuation = bcadd($m4RetailValuation, $m4IngredientValuation, 2);
        $totalGlValuation = bcadd($glRetailBalance, $glIngredientBalance, 2);
        $totalVariance = bcsub($totalM4Valuation, $totalGlValuation, 2);

        $isBalanced = (bccomp($retailVariance, '0.00', 2) === 0 && bccomp($ingredientVariance, '0.00', 2) === 0);

        return [
            'tenant_id' => $tenantId,
            'accounting_period_id' => $accountingPeriodId,
            'store_id' => $storeId,
            'm4_retail_valuation' => $m4RetailValuation,
            'gl_retail_balance' => $glRetailBalance,
            'retail_variance' => $retailVariance,
            'm4_ingredient_valuation' => $m4IngredientValuation,
            'gl_ingredient_balance' => $glIngredientBalance,
            'ingredient_variance' => $ingredientVariance,
            'total_m4_valuation' => $totalM4Valuation,
            'total_gl_valuation' => $totalGlValuation,
            'total_variance' => $totalVariance,
            'is_balanced' => $isBalanced,
        ];
    }

    /**
     * Menyimpan potret valuasi stok operasional ke inventory_valuation_snapshots.
     */
    public function createValuationSnapshots(int $tenantId, int $accountingPeriodId, ?int $storeId = null, ?string $snapshotDate = null): int
    {
        $date = $snapshotDate ?? now()->toDateString();
        $retailAcc = $this->coaService->getAccountBySystemKey('INVENTORY_RETAIL_PRODUCT', $tenantId);
        $ingredientAcc = $this->coaService->getAccountBySystemKey('INVENTORY_FNB_INGREDIENT', $tenantId);

        $count = 0;

        // Produk Retail
        $productQuery = ProductStockBalance::where('tenant_id', $tenantId);
        if ($storeId !== null) {
            $productQuery->where('store_id', $storeId);
        }

        foreach ($productQuery->get() as $psb) {
            $qty = (string) ($psb->quantity ?? 0);
            $avgCost = (string) ($psb->average_unit_cost ?? 0);
            $valuation = bcmul($qty, $avgCost, 2);

            InventoryValuationSnapshot::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $accountingPeriodId,
                    'store_id' => $psb->store_id,
                    'item_type' => 'PRODUCT',
                    'item_id' => $psb->product_id,
                ],
                [
                    'snapshot_date' => $date,
                    'closing_qty' => $qty,
                    'average_unit_cost' => $avgCost,
                    'total_valuation_amount' => $valuation,
                    'gl_account_id' => $retailAcc->id,
                    'gl_balance_amount' => $valuation,
                    'variance_amount' => '0.00',
                    'reconciliation_status' => 'MATCHED',
                ]
            );
            $count++;
        }

        // Bahan Baku F&B
        $ingredientQuery = IngredientStockBalance::where('tenant_id', $tenantId);
        if ($storeId !== null) {
            $ingredientQuery->where('store_id', $storeId);
        }

        foreach ($ingredientQuery->get() as $isb) {
            $qty = (string) ($isb->quantity ?? 0);
            $avgCost = (string) ($isb->average_unit_cost ?? 0);
            $valuation = bcmul($qty, $avgCost, 2);

            InventoryValuationSnapshot::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'accounting_period_id' => $accountingPeriodId,
                    'store_id' => $isb->store_id,
                    'item_type' => 'INGREDIENT',
                    'item_id' => $isb->ingredient_id,
                ],
                [
                    'snapshot_date' => $date,
                    'closing_qty' => $qty,
                    'average_unit_cost' => $avgCost,
                    'total_valuation_amount' => $valuation,
                    'gl_account_id' => $ingredientAcc->id,
                    'gl_balance_amount' => $valuation,
                    'variance_amount' => '0.00',
                    'reconciliation_status' => 'MATCHED',
                ]
            );
            $count++;
        }

        return $count;
    }
}
