<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\IngredientStockBalance;
use App\Modules\Manufacturing\Models\IngredientStockMovement;
use App\Modules\Manufacturing\Models\KitchenWasteLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * KitchenWasteService — Layanan pencatatan dan pelacakan limbah makanan,
 * bahan baku kedaluwarsa, tumpah, atau rusak di dapur restoran (Kitchen Waste & Spoilage).
 */
class KitchenWasteService
{
    /**
     * Catat peristiwa limbah / kerusakan bahan baku dapur.
     */
    public function recordWaste(
        int $tenantId,
        int $storeId,
        int $ingredientId,
        float|string $quantity,
        string $reason = 'SPOILAGE_WASTE',
        ?int $userId = null,
        ?Carbon $wasteDate = null
    ): KitchenWasteLog {
        $qtyStr = bcadd((string) (float) $quantity, '0', 4);

        if (bccomp($qtyStr, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Kuantitas limbah harus bernilai positif lebih dari 0.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $storeId,
            $ingredientId,
            $qtyStr,
            $reason,
            $userId,
            $wasteDate
        ) {
            // 1. Kunci saldo persediaan bahan baku
            $this->ensureIngredientBalanceExists($tenantId, $storeId, $ingredientId);

            /** @var IngredientStockBalance|null $balance */
            $balance = IngredientStockBalance::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            $rawCost = $balance && (float) $balance->average_unit_cost > 0
                ? (string) $balance->average_unit_cost
                : (string) (DB::table('ingredients')->where('id', $ingredientId)->value('cost_per_unit') ?? '0.0000');

            /** @var numeric-string $unitCost */
            $unitCost = is_numeric($rawCost) ? $rawCost : '0.0000';

            $totalLoss = bcmul($qtyStr, $unitCost, 4);

            $balanceBefore = $balance ? (string) $balance->quantity : '0.0000';
            if (bccomp($qtyStr, $balanceBefore, 4) > 0) {
                throw new \DomainException("Jumlah limbah dapur ({$qtyStr}) melebihi saldo stok bahan baku yang tersedia ({$balanceBefore}).");
            }
            $newQuantity = bcsub($balanceBefore, $qtyStr, 4);

            $idempotencyKey = "waste:{$tenantId}:{$storeId}:ing:{$ingredientId}:".Str::uuid()->toString();
            $effectiveDate = $wasteDate ?? Carbon::now();

            // 2. Rekam mutasi keluar di ingredient_stock_movements bertipe 'waste'
            $movement = IngredientStockMovement::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'ingredient_id' => $ingredientId,
                'type' => 'waste',
                'quantity' => bcmul($qtyStr, '-1', 4),
                'unit_cost' => $unitCost,
                'reason' => "Kitchen Waste: {$reason}",
                'idempotency_key' => $idempotencyKey,
                'created_by' => $userId,
                'created_at' => $effectiveDate,
            ]);

            // 3. Potong saldo di tabel ingredient_stock_balances
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

            // 4. Sinkronisasi kolom mirror legacy ingredients.current_stock
            DB::table('ingredients')
                ->where('tenant_id', $tenantId)
                ->where('id', $ingredientId)
                ->decrement('current_stock', (float) $qtyStr);

            // 5. Catat rekord di tabel kitchen_waste_logs
            return KitchenWasteLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'ingredient_id' => $ingredientId,
                'quantity' => $qtyStr,
                'cost_price' => $unitCost,
                'total_loss_amount' => $totalLoss,
                'reason' => $reason,
                'waste_date' => $effectiveDate,
                'logged_by_user_id' => $userId,
            ]);
        });
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
