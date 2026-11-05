<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\Product;
use App\Modules\Inventory\DTOs\ProductFulfillmentDto;
use App\Services\Context\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProductFulfillmentResolverService: Resolves commercial catalog sellability vs physical store inventory fulfillment.
 * Implements authoritative store-aware stock balance resolution using BCMath scale 4.
 */
class ProductFulfillmentResolverService
{
    public function resolve(Product $product, int $storeId): ProductFulfillmentDto
    {
        $tenantId = (int) ($product->tenant_id ?? app(TenantContext::class)->getTenantId());

        if (! $product->relationLoaded('ingredients')) {
            $product->load('ingredients');
        }

        $ingredients = $product->ingredients;

        if ($ingredients->isNotEmpty()) {
            $ingredientIds = $ingredients->pluck('id')->all();

            $balances = DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('ingredient_id', $ingredientIds)
                ->pluck('quantity', 'ingredient_id');

            $minPortions = PHP_INT_MAX;
            $missingIngredients = [];

            foreach ($ingredients as $ingredient) {
                $required = bc_num($ingredient->pivot->quantity_required ?? '0');
                if ((float) $required <= 0) {
                    continue;
                }

                $available = bc_num($balances[$ingredient->id] ?? '0');
                $portions = (int) floor((float) bcdiv($available, $required, 4));

                if ($portions < 1) {
                    $missingIngredients[] = [
                        'ingredient_id' => (int) $ingredient->id,
                        'name' => (string) $ingredient->name,
                        'required' => $required,
                        'available' => $available,
                        'unit' => (string) $ingredient->unit,
                    ];
                }

                if ($portions < $minPortions) {
                    $minPortions = $portions;
                }
            }

            $effectivePortions = $minPortions === PHP_INT_MAX ? 0 : max(0, $minPortions);
            $isFulfillable = empty($missingIngredients) && $effectivePortions >= 1;

            return new ProductFulfillmentDto(
                isFulfillable: $isFulfillable,
                availablePortions: $effectivePortions,
                hasBom: true,
                missingIngredients: $missingIngredients,
            );
        }

        // Non-BOM Products: Service, Non-Inventory, Bundle, or Direct Inventory Tracked
        if ($product->isBundle() || $product->isService() || $product->isNonInventory() || ! $product->track_inventory) {
            return new ProductFulfillmentDto(
                isFulfillable: true,
                availablePortions: null,
                hasBom: false,
            );
        }

        // Standard tracked product: read from product_stock_balances
        $balanceRow = DB::table('product_stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->first();

        $qty = $balanceRow !== null ? (float) $balanceRow->quantity : (float) ($product->stock ?? 0);
        $portions = max(0, (int) floor($qty));
        $isFulfillable = $qty > 0;

        return new ProductFulfillmentDto(
            isFulfillable: $isFulfillable,
            availablePortions: $portions,
            hasBom: false,
        );
    }

    /**
     * Efficiently batch-resolves fulfillment for an entire collection/array of products in 2 queries max.
     * Sets `is_fulfillable` and `available_portions` directly onto each Product model.
     *
     * @param  iterable<Product>  $products
     */
    public function resolveBatch(iterable $products, int $storeId): void
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            return;
        }

        $allProducts = [];
        foreach ($products as $p) {
            $allProducts[] = $p;
        }
        if ($allProducts === []) {
            return;
        }

        $allProductIds = [];
        $allIngredientIds = [];
        $bomProducts = [];

        foreach ($allProducts as $product) {
            $allProductIds[] = $product->id;

            if ($product->relationLoaded('ingredients') && $product->ingredients->isNotEmpty()) {
                $bomProducts[] = $product;
                foreach ($product->ingredients as $ing) {
                    $allIngredientIds[] = $ing->id;
                }
            }
        }

        $allIngredientIds = array_values(array_unique($allIngredientIds));

        // 1. Fetch store ingredient stock balances
        $ingredientBalances = empty($allIngredientIds)
            ? collect()
            : DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('ingredient_id', $allIngredientIds)
                ->pluck('quantity', 'ingredient_id');

        // 2. Fetch store product stock balances
        $productBalances = DB::table('product_stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('product_id', $allProductIds)
            ->pluck('quantity', 'product_id');

        // 3. Resolve each product in-memory
        foreach ($allProducts as $product) {
            if ($product->relationLoaded('ingredients') && $product->ingredients->isNotEmpty()) {
                $minPortions = PHP_INT_MAX;
                $missing = [];

                foreach ($product->ingredients as $ingredient) {
                    $required = bc_num($ingredient->pivot->quantity_required ?? '0');
                    if ((float) $required <= 0) {
                        continue;
                    }

                    $available = bc_num($ingredientBalances[$ingredient->id] ?? '0');
                    $portions = (int) floor((float) bcdiv($available, $required, 4));

                    if ($portions < 1) {
                        $missing[] = $ingredient->id;
                    }

                    if ($portions < $minPortions) {
                        $minPortions = $portions;
                    }
                }

                $effectivePortions = $minPortions === PHP_INT_MAX ? 0 : max(0, $minPortions);
                $isFulfillable = empty($missing) && $effectivePortions >= 1;

                $product->is_fulfillable = $isFulfillable;
                $product->available_portions = $effectivePortions;
                $product->has_bom = true;

                continue;
            }

            // Non-BOM Products
            if ($product->isBundle() || $product->isService() || $product->isNonInventory() || ! $product->track_inventory) {
                $product->is_fulfillable = true;
                $product->available_portions = null;
                $product->has_bom = false;

                continue;
            }

            $hasStoreBalance = $productBalances->has($product->id);
            $qty = $hasStoreBalance ? (float) $productBalances->get($product->id) : (float) ($product->stock ?? 0);
            $portions = max(0, (int) floor($qty));

            $product->is_fulfillable = $qty > 0;
            $product->available_portions = $portions;
            $product->has_bom = false;
        }
    }
}
