<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\Concerns\ScopedToTenant;
use App\Modules\Products\Models\ProductCostHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * InventoryCostingService — Layanan kalkulasi biaya rata-rata bergerak (Moving Average Cost)
 * dan alokasi Landed Cost inkremental sesuai dokumen spesifikasi docs/04-data-architecture/inventory-costing-model.md.
 */
class InventoryCostingService
{
    /**
     * Hitung Biaya Rata-Rata Bergerak (Moving Average Cost) inkremental.
     *
     * Rumus PSAK/SAK:
     * C_new = ((Q_old * C_old) + (Q_in * C_in + LandedCost)) / (Q_old + Q_in)
     *
     * @return array{
     *     new_average_cost: string,
     *     total_quantity: string,
     *     effective_unit_cost: string
     * }
     */
    public function calculateMovingAverage(
        string|float $currentQty,
        string|float $currentAvgCost,
        string|float $incomingQty,
        string|float $incomingCost,
        string|float $landedCost = 0
    ): array {
        $qOld = (string) (float) $currentQty;
        $cOld = (string) (float) $currentAvgCost;
        $qIn = (string) (float) $incomingQty;
        $cIn = (string) (float) $incomingCost;
        $landed = (string) (float) $landedCost;

        if (bccomp($qIn, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Kuantitas masuk harus lebih besar dari 0.');
        }

        // Nilai total barang masuk termasuk biaya perolehan tambahan (Landed Cost)
        $inboundGoodsValue = bcmul($qIn, $cIn, 4);
        $totalInboundValue = bcadd($inboundGoodsValue, $landed, 4);
        $effectiveUnitCost = bcdiv($totalInboundValue, $qIn, 4);

        if (bccomp($qOld, '0.0000', 4) <= 0) {
            return [
                'new_average_cost' => bcadd($effectiveUnitCost, '0.0000', 4),
                'total_quantity' => bcadd($qIn, '0.0000', 4),
                'effective_unit_cost' => bcadd($effectiveUnitCost, '0.0000', 4),
            ];
        }

        $existingValue = bcmul($qOld, $cOld, 4);
        $totalValue = bcadd($existingValue, $totalInboundValue, 4);
        $totalQty = bcadd($qOld, $qIn, 4);

        $newAverageCost = bcdiv($totalValue, $totalQty, 4);

        return [
            'new_average_cost' => bcadd($newAverageCost, '0.0000', 4),
            'total_quantity' => bcadd($totalQty, '0.0000', 4),
            'effective_unit_cost' => bcadd($effectiveUnitCost, '0.0000', 4),
        ];
    }

    /**
     * Catat pembaruan biaya rata-rata bergerak pada saat penerimaan barang (Goods Receipt).
     *
     * @param 'product'|'ingredient' $itemType
     * @return array{
     *     old_average_cost: string,
     *     new_average_cost: string,
     *     effective_unit_cost: string
     * }
     */
    public function updateAverageCostOnReceipt(
        int $tenantId,
        int $storeId,
        string $itemType,
        int $itemId,
        string|float $incomingQty,
        string|float $purchasePrice,
        string|float $landedCost = 0,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use (
            $tenantId,
            $storeId,
            $itemType,
            $itemId,
            $incomingQty,
            $purchasePrice,
            $landedCost,
            $userId
        ) {
            $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
            $col = $itemType === 'product' ? 'product_id' : 'ingredient_id';

            $balance = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where($col, $itemId)
                ->lockForUpdate()
                ->first();

            $currentQty = $balance ? (string) $balance->quantity : '0.0000';
            $currentAvgCost = $balance ? (string) ($balance->average_unit_cost ?? '0.0000') : '0.0000';

            $calculation = $this->calculateMovingAverage(
                currentQty: $currentQty,
                currentAvgCost: $currentAvgCost,
                incomingQty: $incomingQty,
                incomingCost: $purchasePrice,
                landedCost: $landedCost
            );

            // Update balance table average_unit_cost & last_unit_cost
            if ($balance) {
                DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where($col, $itemId)
                    ->update([
                        'average_unit_cost' => $calculation['new_average_cost'],
                        'last_unit_cost' => $calculation['effective_unit_cost'],
                        'updated_at' => now(),
                    ]);
            }

            // Jika produk ritel, rekam riwayat perubahan HPP append-only
            if ($itemType === 'product') {
                ProductCostHistory::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'product_id' => $itemId,
                    'cost_price' => $calculation['new_average_cost'],
                    'effective_date' => Carbon::now(),
                    'created_by_user_id' => $userId,
                ]);

                // Update standard_cost pada produk master
                DB::table('products')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $itemId)
                    ->update([
                        'standard_cost' => $calculation['new_average_cost'],
                        'updated_at' => now(),
                    ]);
            } else {
                // Update cost_per_unit pada master ingredient
                DB::table('ingredients')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $itemId)
                    ->update([
                        'cost_per_unit' => $calculation['new_average_cost'],
                        'updated_at' => now(),
                    ]);
            }

            return [
                'old_average_cost' => $currentAvgCost,
                'new_average_cost' => $calculation['new_average_cost'],
                'effective_unit_cost' => $calculation['effective_unit_cost'],
            ];
        });
    }
}
