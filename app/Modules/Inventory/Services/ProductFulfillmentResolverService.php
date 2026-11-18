<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\DTOs\ProductFulfillmentDto;
use App\Modules\Inventory\Models\IngredientStockBalance;
use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Modules\Inventory\Models\ModifierIngredient;
use App\Modules\Inventory\Models\ProductIngredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Potong stok bahan baku dapur secara otomatis untuk pesanan kasir F&B (JIT BOM Deduction).
     *
     * @param  array<int, array{
     *     product_id: int,
     *     quantity: float|int|string,
     *     modifier_ids?: array<int>,
     *     order_item_id?: ?int
     * }>  $items
     * @return array<int, array{
     *     ingredient_id: int,
     *     quantity_deducted: string,
     *     balance_before: string,
     *     balance_after: string,
     *     movement_id: int
     * }>
     */
    public function deductRecipeForOrder(
        int $tenantId,
        int $storeId,
        int $orderId,
        array $items,
        ?int $userId = null
    ): array {
        $requiredIngredients = $this->aggregateRequiredIngredients($tenantId, $items);

        if (empty($requiredIngredients)) {
            return [];
        }

        return DB::transaction(function () use ($tenantId, $storeId, $orderId, $requiredIngredients, $userId) {
            // Sort keys by ingredient_id for canonical anti-deadlock ordering
            ksort($requiredIngredients);
            $results = [];

            foreach ($requiredIngredients as $ingredientId => $qtyNeeded) {
                // Pastikan baris balance terinisialisasi
                $this->ensureIngredientBalanceExists($tenantId, $storeId, $ingredientId);

                /** @var IngredientStockBalance|null $balance */
                $balance = IngredientStockBalance::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first();

                $balanceBefore = $balance ? (string) $balance->quantity : '0.0000';
                $newQuantity = bcsub($balanceBefore, $qtyNeeded, 4);

                $unitCost = $balance ? (string) ($balance->average_unit_cost ?? '0.0000') : '0.0000';
                $idempotencyKey = "jit_bom:{$tenantId}:{$storeId}:order:{$orderId}:ing:{$ingredientId}:".Str::uuid()->toString();

                // Catat mutasi keluar di ingredient_stock_movements (SALE_CONSUMPTION)
                $movement = IngredientStockMovement::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'ingredient_id' => $ingredientId,
                    'order_id' => $orderId,
                    'type' => 'sale_deduction',
                    'quantity' => bcmul($qtyNeeded, '-1', 4),
                    'unit_cost' => $unitCost,
                    'reason' => "JIT BOM Deduction for Order #{$orderId}",
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                // Perbarui saldo persediaan bahan baku
                if ($balance) {
                    DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('ingredient_id', $ingredientId)
                        ->update([
                            'quantity' => $newQuantity,
                            'last_movement_id' => $movement->id,
                            'updated_at' => now(),
                        ]);
                    $balance->quantity = (string) $newQuantity;
                    $balance->last_movement_id = $movement->id;
                }

                // Sinkronisasi kolom mirror legacy ingredients.current_stock
                DB::table('ingredients')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $ingredientId)
                    ->decrement('current_stock', (float) $qtyNeeded);

                $results[] = [
                    'ingredient_id' => $ingredientId,
                    'quantity_deducted' => $qtyNeeded,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $newQuantity,
                    'movement_id' => $movement->id,
                ];
            }

            return $results;
        });
    }

    /**
     * Pulihkan stok bahan mentah saat pesanan di-void (Void Restoration).
     *
     * @param  array<int, array{
     *     product_id: int,
     *     quantity: float|int|string,
     *     modifier_ids?: array<int>,
     *     order_item_id?: ?int
     * }>  $items
     * @return array<int, array{
     *     ingredient_id: int,
     *     quantity_restored: string,
     *     balance_before: string,
     *     balance_after: string,
     *     movement_id: int
     * }>
     */
    public function restoreRecipeForVoid(
        int $tenantId,
        int $storeId,
        int $orderId,
        array $items,
        ?int $userId = null,
        string $reason = 'Restorasi stok bahan baku akibat void pesanan'
    ): array {
        $requiredIngredients = $this->aggregateRequiredIngredients($tenantId, $items);

        if (empty($requiredIngredients)) {
            return [];
        }

        return DB::transaction(function () use ($tenantId, $storeId, $orderId, $requiredIngredients, $userId, $reason) {
            ksort($requiredIngredients);
            $results = [];

            foreach ($requiredIngredients as $ingredientId => $qtyToRestore) {
                $this->ensureIngredientBalanceExists($tenantId, $storeId, $ingredientId);

                /** @var IngredientStockBalance|null $balance */
                $balance = IngredientStockBalance::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first();

                $balanceBefore = $balance ? (string) $balance->quantity : '0.0000';
                $newQuantity = bcadd($balanceBefore, $qtyToRestore, 4);

                $unitCost = $balance ? (string) ($balance->average_unit_cost ?? '0.0000') : '0.0000';
                $idempotencyKey = "void_restore:{$tenantId}:{$storeId}:order:{$orderId}:ing:{$ingredientId}:".Str::uuid()->toString();

                // Catat mutasi kompensasi masuk di ingredient_stock_movements (VOID_RESTORE)
                $movement = IngredientStockMovement::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'ingredient_id' => $ingredientId,
                    'order_id' => $orderId,
                    'type' => 'restore_compensation',
                    'quantity' => $qtyToRestore,
                    'unit_cost' => $unitCost,
                    'reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                if ($balance) {
                    DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('ingredient_id', $ingredientId)
                        ->update([
                            'quantity' => $newQuantity,
                            'last_movement_id' => $movement->id,
                            'updated_at' => now(),
                        ]);
                    $balance->quantity = (string) $newQuantity;
                    $balance->last_movement_id = $movement->id;
                }

                DB::table('ingredients')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $ingredientId)
                    ->increment('current_stock', (float) $qtyToRestore);

                $results[] = [
                    'ingredient_id' => $ingredientId,
                    'quantity_restored' => $qtyToRestore,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $newQuantity,
                    'movement_id' => $movement->id,
                ];
            }

            return $results;
        });
    }

    /**
     * Agregasi kebutuhan total bahan mentah dari daftar item produk dan modifier.
     *
     * @param  array<int, array{
     *     product_id: int,
     *     quantity: float|int|string,
     *     modifier_ids?: array<int>
     * }>  $items
     * @return array<int, numeric-string> Map ingredient_id => total_quantity_needed
     */
    public function aggregateRequiredIngredients(int $tenantId, array $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $itemQty = bcadd((string) (float) $item['quantity'], '0', 4);

            // 1. Resep dasar produk jadi (product_ingredients)
            $productIngredients = ProductIngredient::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->get();

            foreach ($productIngredients as $pi) {
                $ingId = (int) $pi->ingredient_id;
                $portion = (string) (float) $pi->quantity_required;
                $needed = bcmul($portion, $itemQty, 4);

                $totals[$ingId] = isset($totals[$ingId])
                    ? bcadd($totals[$ingId], $needed, 4)
                    : $needed;
            }

            // 2. Resep tambahan modifier (modifier_ingredients)
            $modifierIds = $item['modifier_ids'] ?? [];
            if (! empty($modifierIds)) {
                $modifierIngredients = ModifierIngredient::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('modifier_id', $modifierIds)
                    ->get();

                foreach ($modifierIngredients as $mi) {
                    $ingId = (int) $mi->ingredient_id;
                    $portion = (string) (float) $mi->quantity_required;
                    $needed = bcmul($portion, $itemQty, 4);

                    $totals[$ingId] = isset($totals[$ingId])
                        ? bcadd($totals[$ingId], $needed, 4)
                        : $needed;
                }
            }
        }

        return $totals;
    }

    private function ensureIngredientBalanceExists(int $tenantId, int $storeId, int $ingredientId): void
    {
        $legacyStock = DB::table('ingredients')
            ->where('tenant_id', $tenantId)
            ->where('id', $ingredientId)
            ->value('current_stock');

        $initialQty = $legacyStock !== null ? (float) $legacyStock : 0;

        DB::table('ingredient_stock_balances')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'ingredient_id' => $ingredientId,
            'quantity' => $initialQty,
            'reserved_quantity' => 0,
            'baseline_quantity' => $initialQty,
            'baseline_movement_id' => 0,
            'min_stock_level' => 0,
            'reorder_point' => 0,
            'max_stock_level' => null,
            'average_unit_cost' => 0,
            'last_unit_cost' => 0,
            'last_movement_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
