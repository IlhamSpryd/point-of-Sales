<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\Ingredient;
use App\Modules\Manufacturing\Models\IngredientStockBalance;
use App\Modules\Manufacturing\Models\ModifierIngredient;
use App\Modules\Manufacturing\Models\ProductIngredient;

/**
 * RecipeCostingService — Layanan kalkulasi HPP resep makanan & minuman (BOM Costing)
 * berdasarkan akumulasi takaran bahan baku x biaya rata-rata bahan (Moving Average Cost).
 */
class RecipeCostingService
{
    /**
     * Hitung total HPP resep untuk suatu produk jadi.
     *
     * @return numeric-string
     */
    public function calculateRecipeCost(int $tenantId, int $productId, ?int $storeId = null): string
    {
        $productIngredients = ProductIngredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->get();

        if ($productIngredients->isEmpty()) {
            return '0.00';
        }

        $totalCost = '0.0000';

        foreach ($productIngredients as $pi) {
            $ingredientId = (int) $pi->ingredient_id;
            $qtyRequired = bcadd((string) (float) $pi->quantity_required, '0', 4);

            $unitCost = $this->resolveIngredientUnitCost($tenantId, $ingredientId, $storeId);
            $ingredientTotal = bcmul($qtyRequired, $unitCost, 4);

            $totalCost = bcadd($totalCost, $ingredientTotal, 4);
        }

        /** @var numeric-string $formatted */
        $formatted = bcadd($totalCost, '0', 2);

        return $formatted;
    }

    /**
     * Hitung tambahan HPP dari modifier/topping pilihan.
     *
     * @return numeric-string
     */
    public function calculateModifierCost(int $tenantId, int $modifierId, ?int $storeId = null): string
    {
        $modifierIngredients = ModifierIngredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('modifier_id', $modifierId)
            ->get();

        if ($modifierIngredients->isEmpty()) {
            return '0.00';
        }

        $totalCost = '0.0000';

        foreach ($modifierIngredients as $mi) {
            $ingredientId = (int) $mi->ingredient_id;
            $qtyRequired = bcadd((string) (float) $mi->quantity_required, '0', 4);

            $unitCost = $this->resolveIngredientUnitCost($tenantId, $ingredientId, $storeId);
            $itemTotal = bcmul($qtyRequired, $unitCost, 4);

            $totalCost = bcadd($totalCost, $itemTotal, 4);
        }

        /** @var numeric-string $formatted */
        $formatted = bcadd($totalCost, '0', 2);

        return $formatted;
    }

    /**
     * Resolusi biaya satuan bahan mentah: preferensi average_unit_cost cabang, fallback cost_per_unit master.
     *
     * @return numeric-string
     */
    private function resolveIngredientUnitCost(int $tenantId, int $ingredientId, ?int $storeId): string
    {
        if ($storeId !== null) {
            $balance = IngredientStockBalance::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('ingredient_id', $ingredientId)
                ->first();

            if ($balance && (float) $balance->average_unit_cost > 0) {
                return bcadd((string) (float) $balance->average_unit_cost, '0', 4);
            }
        }

        $ingredient = Ingredient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $ingredientId)
            ->first();

        if ($ingredient && (float) $ingredient->cost_per_unit > 0) {
            return bcadd((string) (float) $ingredient->cost_per_unit, '0', 4);
        }

        return '0.0000';
    }
}
