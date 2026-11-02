<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\Ingredient;
use App\Modules\Manufacturing\Models\IngredientStockBalance;
use App\Modules\Manufacturing\Models\IngredientStockMovement;
use App\Modules\Manufacturing\Models\ModifierIngredient;
use App\Modules\Manufacturing\Models\ProductIngredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ProductFulfillmentResolverService — Layanan JIT BOM Recipe Deduction & Void Restoration.
 *
 * Mengimplementasikan:
 * - Dekomposisi resep produk jadi dan modifier ke bahan mentah dapur.
 * - Pemotongan saldo di ingredient_stock_balances secara atomik dengan lockForUpdate().
 * - Pencatatan mutasi append-only di ingredient_stock_movements (T-MFG-01).
 * - Pemulihan stok bahan mentah saat transaksi di-void (T-MFG-02).
 */
class ProductFulfillmentResolverService
{
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
                $idempotencyKey = "jit_bom:{$tenantId}:{$storeId}:order:{$orderId}:ing:{$ingredientId}:" . Str::uuid()->toString();

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
                    $balance->quantity = (float) $newQuantity;
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
                $idempotencyKey = "void_restore:{$tenantId}:{$storeId}:order:{$orderId}:ing:{$ingredientId}:" . Str::uuid()->toString();

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
                    $balance->quantity = (float) $newQuantity;
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
