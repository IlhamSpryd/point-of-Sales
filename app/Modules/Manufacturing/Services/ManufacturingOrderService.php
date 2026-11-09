<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\Product;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Models\ProductIngredient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ManufacturingOrderService
{
    /**
     * Create a new discrete manufacturing work order.
     */
    public function createOrder(
        int $tenantId,
        int $storeId,
        int $productId,
        string|float|int $plannedQuantity,
        ?string $plannedStartDate = null,
        ?string $notes = null,
        ?int $userId = null
    ): ManufacturingOrder {
        $qtyStr = (string) $plannedQuantity;
        if (bccomp($qtyStr, '0', 4) <= 0) {
            throw new InvalidArgumentException('Planned quantity must be greater than zero.');
        }

        $orderNumber = 'MO-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);

        return ManufacturingOrder::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_number' => $orderNumber,
            'product_id' => $productId,
            'planned_quantity' => $qtyStr,
            'produced_quantity' => '0.0000',
            'status' => 'PLANNED',
            'planned_start_date' => $plannedStartDate ?? now()->toDateString(),
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Transition order to IN_PROGRESS.
     */
    public function startProduction(int $orderId): ManufacturingOrder
    {
        $order = ManufacturingOrder::findOrFail($orderId);
        if ($order->status !== 'PLANNED' && $order->status !== 'DRAFT') {
            throw new RuntimeException("Cannot start production from status {$order->status}.");
        }

        $order->status = 'IN_PROGRESS';
        $order->save();

        return $order;
    }

    /**
     * Complete production: deduct BOM recipe ingredients and increment product stock.
     */
    public function completeProduction(int $orderId, string|float|int|null $producedQuantity = null): ManufacturingOrder
    {
        return DB::transaction(function () use ($orderId, $producedQuantity) {
            $order = ManufacturingOrder::lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'COMPLETED' || $order->status === 'CANCELLED') {
                throw new RuntimeException("Cannot complete an already {$order->status} manufacturing order.");
            }

            $actualProduced = (string) ($producedQuantity ?? $order->planned_quantity);
            if (bccomp($actualProduced, '0', 4) <= 0) {
                throw new InvalidArgumentException('Produced quantity must be greater than zero.');
            }

            // 1. Deduct raw materials / ingredients based on BOM recipe
            $ingredients = ProductIngredient::where('tenant_id', $order->tenant_id)
                ->where('product_id', $order->product_id)
                ->get();

            foreach ($ingredients as $bomItem) {
                $itemQty = (string) ($bomItem->quantity_required ?? $bomItem->quantity ?? '1.0000');
                $requiredQty = bcmul($itemQty, $actualProduced, 4);

                DB::table('ingredient_stock_balances')
                    ->where('tenant_id', $order->tenant_id)
                    ->where('store_id', $order->store_id)
                    ->where('ingredient_id', $bomItem->ingredient_id)
                    ->decrement('quantity', (float) $requiredQty);

                DB::table('ingredients')
                    ->where('tenant_id', $order->tenant_id)
                    ->where('id', $bomItem->ingredient_id)
                    ->decrement('current_stock', (float) $requiredQty);
            }

            // 2. Increment finished good product stock
            DB::table('products')
                ->where('tenant_id', $order->tenant_id)
                ->where('id', $order->product_id)
                ->increment('stock', (int) round((float) $actualProduced));

            DB::table('product_stock_balances')
                ->where('tenant_id', $order->tenant_id)
                ->where('store_id', $order->store_id)
                ->where('product_id', $order->product_id)
                ->increment('quantity', (float) $actualProduced);

            // 3. Update order state
            $order->produced_quantity = $actualProduced;
            $order->status = 'COMPLETED';
            $order->completed_at = now();
            $order->save();

            return $order;
        });
    }

    /**
     * Cancel manufacturing order.
     */
    public function cancelOrder(int $orderId): ManufacturingOrder
    {
        $order = ManufacturingOrder::findOrFail($orderId);
        if ($order->status === 'COMPLETED') {
            throw new RuntimeException('Cannot cancel an already completed manufacturing order.');
        }

        $order->status = 'CANCELLED';
        $order->save();

        return $order;
    }
}
