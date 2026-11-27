<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\BundleComponentExpanderService;
use App\Modules\Catalog\Services\UnitConversionService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * Handles stock and ingredient deductions during POS checkout (ADR-108).
 */
class OrderStockDeductionService
{
    /**
     * Deduct inventory for all items in the order.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, OrderItem>  $createdItems
     * @param  Collection<int, Product>  $products
     * @param  array<int, list<array{ingredient_id: int, qty: string}>>  $bomBreakdownByLineIndex
     * @param  Collection<int, mixed>  $lockedIngredients
     */
    public function deductStockForOrder(
        Order $order,
        array $lines,
        array $createdItems,
        Collection $products,
        array $bomBreakdownByLineIndex,
        Collection $lockedIngredients,
        int $userId
    ): void {
        foreach ($lines as $idx => $line) {
            $orderItem = $createdItems[$idx];
            $product = $products->firstWhere('id', (int) $line['product_id']);

            if (! empty($line['needs_stock_deduction'])) {
                $deductionQty = (float) $line['qty'];
                if (! empty($line['uom_id']) && $product && $product->base_uom_id && (int) $line['uom_id'] !== (int) $product->base_uom_id) {
                    try {
                        $converted = app(UnitConversionService::class)->convertBetweenUoms(
                            tenantId: (int) $order->tenant_id,
                            fromUomId: (int) $line['uom_id'],
                            toUomId: (int) $product->base_uom_id,
                            quantity: $line['qty'],
                            productId: (int) $product->id,
                            scale: 4
                        );
                        $deductionQty = (float) $converted;
                    } catch (\Throwable) {
                        $conv = $product->unitConversions->firstWhere('from_uom_id', $line['uom_id']);
                        if ($conv) {
                            $deductionQty = (float) bcmul((string) $line['qty'], (string) $conv->conversion_factor, 4);
                        }
                    }
                }

                app(InventoryLedgerService::class)->recordMovement(
                    tenantId: (int) $order->tenant_id,
                    storeId: (int) $order->store_id,
                    itemType: 'product',
                    itemId: (int) $line['product_id'],
                    quantityDelta: -$deductionQty,
                    type: StockMovementType::SaleDeduction,
                    unitCost: $product?->standard_cost ? (float) $product->standard_cost : null,
                    reason: 'Checkout via POS',
                    idempotencyKey: "sale:{$order->id}:{$orderItem->id}",
                    orderId: (int) $order->id,
                    orderItemId: (int) $orderItem->id,
                    userId: $userId,
                    variantId: $line['product_variant_id'] ? (int) $line['product_variant_id'] : null,
                    allowNegative: (bool) $order->is_offline_sync
                );
            } elseif ($product && $product->isBundle()) {
                $bundleExpander = app(BundleComponentExpanderService::class);
                $components = $bundleExpander->expand($product, (int) $line['qty']);
                $inventoryLedger = app(InventoryLedgerService::class);

                foreach ($components as $comp) {
                    /** @var Product $compProd */
                    $compProd = $comp['component_product'];
                    $compQty = (float) $comp['quantity'];

                    if ($compProd->ingredients()->exists()) {
                        $compProd->loadMissing('ingredients');
                        foreach ($compProd->ingredients as $ingredient) {
                            $needed = bcmul((string) $ingredient->pivot->quantity_required, (string) $compQty, 4);
                            $inventoryLedger->recordMovement(
                                tenantId: (int) $order->tenant_id,
                                storeId: (int) $order->store_id,
                                itemType: 'ingredient',
                                itemId: (int) $ingredient->id,
                                quantityDelta: -(float) $needed,
                                type: StockMovementType::SaleDeduction,
                                unitCost: $ingredient->cost_per_unit ? (float) $ingredient->cost_per_unit : null,
                                reason: "Sale of bundle {$product->product_name} component {$compProd->product_name}",
                                idempotencyKey: "sale:{$order->id}:{$orderItem->id}:bundle_ing:{$ingredient->id}",
                                orderId: (int) $order->id,
                                orderItemId: (int) $orderItem->id,
                                userId: $userId,
                                allowNegative: (bool) $order->is_offline_sync
                            );
                        }
                    } elseif ($compProd->track_inventory && ! $compProd->isService() && ! $compProd->isNonInventory()) {
                        $inventoryLedger->recordMovement(
                            tenantId: (int) $order->tenant_id,
                            storeId: (int) $order->store_id,
                            itemType: 'product',
                            itemId: (int) $compProd->id,
                            quantityDelta: -$compQty,
                            type: StockMovementType::SaleDeduction,
                            unitCost: $compProd->standard_cost ? (float) $compProd->standard_cost : null,
                            reason: "Sale of bundle {$product->product_name} component {$compProd->product_name}",
                            idempotencyKey: "sale:{$order->id}:{$orderItem->id}:bundle_comp:{$compProd->id}",
                            orderId: (int) $order->id,
                            orderItemId: (int) $orderItem->id,
                            userId: $userId,
                            allowNegative: (bool) $order->is_offline_sync
                        );
                    }
                }
            }

            if (! empty($line['options'])) {
                $modifierSync = [];
                foreach ($line['options'] as $opt) {
                    $modifierSync[$opt['modifier_id']] = [
                        'price_at_time' => $opt['extra_price'],
                        'qty' => $line['qty'],
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                    ];
                }
                if (! empty($modifierSync)) {
                    $orderItem->modifiers()->sync($modifierSync);
                }
            }
        }

        foreach ($lines as $i => $line) {
            $breakdown = $bomBreakdownByLineIndex[$i] ?? [];
            if (empty($breakdown)) {
                continue;
            }

            $orderItemId = $createdItems[$i]->id;

            foreach ($breakdown as $consumption) {
                $ingredient = $lockedIngredients->get($consumption['ingredient_id']);
                $idempotencyKey = 'sale_deduction:'.$order->order_code.':'.$orderItemId.':'.$consumption['ingredient_id'];

                app(InventoryLedgerService::class)->recordMovement(
                    tenantId: (int) $order->tenant_id,
                    storeId: (int) $order->store_id,
                    itemType: 'ingredient',
                    itemId: (int) $consumption['ingredient_id'],
                    quantityDelta: bcmul($consumption['qty'], '-1', 4),
                    type: IngredientStockMovementTypeEnum::SaleDeduction,
                    unitCost: $ingredient?->cost_per_unit,
                    reason: 'Konsumsi otomatis saat penjualan order '.$order->order_code.'.',
                    idempotencyKey: $idempotencyKey,
                    orderId: (int) $order->id,
                    orderItemId: (int) $orderItemId,
                    userId: $userId,
                    allowNegative: (bool) $order->is_offline_sync
                );
            }
        }
    }
}
