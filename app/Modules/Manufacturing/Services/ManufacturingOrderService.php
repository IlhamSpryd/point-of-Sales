<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Product;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Manufacturing\Models\IngredientRecipe;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Models\ProductIngredient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ManufacturingOrderService
{
    public function __construct(
        protected InventoryLedgerService $inventoryLedgerService,
        protected FinancialOutboxDispatcherService $financialOutboxDispatcher
    ) {}

    /**
     * Create a new discrete manufacturing work order for finished product.
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
            'destination_type' => 'product',
            'product_id' => $productId,
            'destination_ingredient_id' => null,
            'planned_quantity' => $qtyStr,
            'produced_quantity' => '0.0000',
            'status' => 'PLANNED',
            'planned_start_date' => $plannedStartDate ?? now()->toDateString(),
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Create a work order for semi-finished kitchen prep item (Multi-level BOM - TASK-P2-01).
     */
    public function createPrepOrder(
        int $tenantId,
        int $storeId,
        int $ingredientId,
        string|float|int $plannedQuantity,
        ?string $plannedStartDate = null,
        ?string $notes = null,
        ?int $userId = null
    ): ManufacturingOrder {
        $qtyStr = (string) $plannedQuantity;
        if (bccomp($qtyStr, '0', 4) <= 0) {
            throw new InvalidArgumentException('Planned quantity must be greater than zero.');
        }

        $orderNumber = 'MO-PREP-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);

        return ManufacturingOrder::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_number' => $orderNumber,
            'destination_type' => 'ingredient',
            'product_id' => null,
            'destination_ingredient_id' => $ingredientId,
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
     * Complete production: deduct BOM recipe ingredients and increment product stock
     * using append-only immutable ledgers and emit WIP accounting financial outbox event.
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

            $totalMaterialCost = '0.0000';
            $consumedSummary = [];

            if ($order->isPrepOrder()) {
                // Multi-Level BOM: Kitchen Prep Items (TASK-P2-01)
                $prepIngredientId = (int) $order->destination_ingredient_id;
                $recipeItems = IngredientRecipe::where('tenant_id', $order->tenant_id)
                    ->where('parent_ingredient_id', $prepIngredientId)
                    ->with('componentIngredient')
                    ->get();

                if ($recipeItems->isEmpty()) {
                    throw new RuntimeException("Resep bahan olahan (IngredientRecipe) untuk bahan baku #{$prepIngredientId} belum dikonfigurasi.");
                }

                foreach ($recipeItems as $recipeItem) {
                    $itemQty = (string) ($recipeItem->quantity_required ?? '1.0000');
                    $requiredQty = bcmul($itemQty, $actualProduced, 4);

                    $compIngredient = $recipeItem->componentIngredient;
                    $unitCost = (float) $compIngredient->cost_per_unit;
                    $lineCost = bcmul($requiredQty, (string) $unitCost, 4);
                    $totalMaterialCost = bcadd($totalMaterialCost, $lineCost, 4);

                    $ingredientName = $compIngredient->name;

                    $consumedSummary[] = [
                        'ingredient_id' => $recipeItem->component_ingredient_id,
                        'ingredient_name' => $ingredientName,
                        'quantity_consumed' => (float) $requiredQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => (float) $lineCost,
                    ];

                    $this->inventoryLedgerService->recordMovement(
                        tenantId: $order->tenant_id,
                        storeId: $order->store_id,
                        itemType: 'ingredient',
                        itemId: (int) $recipeItem->component_ingredient_id,
                        quantityDelta: -((float) $requiredQty),
                        type: IngredientStockMovementTypeEnum::AdjustmentOut,
                        unitCost: $unitCost,
                        reason: "Kitchen prep consumption MO #{$order->order_number}",
                        idempotencyKey: "mo-prep-ing-{$order->id}-{$recipeItem->component_ingredient_id}",
                        userId: $order->created_by
                    );
                }

                // Tambahkan stok bahan olahan (parent ingredient) melalui InventoryLedgerService
                $parentIngredient = Ingredient::where('id', $prepIngredientId)->first();
                $unitCostPrep = (float) bcdiv($totalMaterialCost, $actualProduced, 4);

                $this->inventoryLedgerService->recordMovement(
                    tenantId: $order->tenant_id,
                    storeId: $order->store_id,
                    itemType: 'ingredient',
                    itemId: $prepIngredientId,
                    quantityDelta: (float) $actualProduced,
                    type: StockMovementType::AdjustmentIn,
                    unitCost: $unitCostPrep > 0 ? $unitCostPrep : null,
                    reason: "Kitchen prep output MO #{$order->order_number}",
                    idempotencyKey: "mo-prep-output-{$order->id}",
                    userId: $order->created_by
                );

                if ($unitCostPrep > 0 && $parentIngredient instanceof Ingredient) {
                    $parentIngredient->cost_per_unit = (string) $unitCostPrep;
                    $parentIngredient->save();
                }

                $parentName = $parentIngredient instanceof Ingredient ? $parentIngredient->name : "#{$prepIngredientId}";
                $this->financialOutboxDispatcher->recordOutboxEvent(
                    tenantId: $order->tenant_id,
                    storeId: $order->store_id,
                    eventType: 'manufacturing.prep_production_completed',
                    sourceModule: 'manufacturing',
                    sourceType: 'manufacturing_order',
                    sourceId: $order->id,
                    sourceNumber: $order->order_number,
                    idempotencyKey: "outbox-mo-complete-{$order->id}",
                    payload: [
                        'manufacturing_order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'destination_type' => 'ingredient',
                        'destination_ingredient_id' => $prepIngredientId,
                        'ingredient_name' => $parentName,
                        'produced_quantity' => (float) $actualProduced,
                        'total_material_cost' => (float) $totalMaterialCost,
                        'unit_material_cost' => $unitCostPrep,
                        'currency' => 'IDR',
                        'ingredients_consumed' => $consumedSummary,
                    ]
                );
            } else {
                // Alur produksi Finished Good (Product)
                $ingredients = ProductIngredient::where('tenant_id', $order->tenant_id)
                    ->where('product_id', $order->product_id)
                    ->with('ingredient')
                    ->get();

                // 2. Potong stok bahan baku melalui InventoryLedgerService (append-only audit ledger)
                foreach ($ingredients as $bomItem) {
                    $itemQty = (string) ($bomItem->quantity_required ?? $bomItem->quantity ?? '1.0000');
                    $requiredQty = bcmul($itemQty, $actualProduced, 4);

                    $ingredient = $bomItem->ingredient;
                    $unitCost = $ingredient instanceof \App\Models\Ingredient ? (float) $ingredient->cost_per_unit : 0.0;
                    $lineCost = bcmul($requiredQty, (string) $unitCost, 4);
                    $totalMaterialCost = bcadd($totalMaterialCost, $lineCost, 4);

                    $ingredientName = $ingredient instanceof \App\Models\Ingredient ? $ingredient->name : "#{$bomItem->ingredient_id}";

                    $consumedSummary[] = [
                        'ingredient_id' => $bomItem->ingredient_id,
                        'ingredient_name' => $ingredientName,
                        'quantity_consumed' => (float) $requiredQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => (float) $lineCost,
                    ];

                    $this->inventoryLedgerService->recordMovement(
                        tenantId: $order->tenant_id,
                        storeId: $order->store_id,
                        itemType: 'ingredient',
                        itemId: (int) $bomItem->ingredient_id,
                        quantityDelta: -((float) $requiredQty),
                        type: IngredientStockMovementTypeEnum::AdjustmentOut,
                        unitCost: $unitCost,
                        reason: "Manufacturing consumption MO #{$order->order_number}",
                        idempotencyKey: "mo-ing-{$order->id}-{$bomItem->ingredient_id}",
                        userId: $order->created_by
                    );
                }

                // 3. Tambahkan stok barang jadi (finished good) melalui InventoryLedgerService
                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('id', $order->product_id)
                    ->first();

                $productUnitCost = $product instanceof Product ? (float) $product->standard_cost : 0.0;
                if ($productUnitCost <= 0.0 && (float) $actualProduced > 0) {
                    $productUnitCost = (float) bcdiv($totalMaterialCost, $actualProduced, 4);
                }

                $this->inventoryLedgerService->recordMovement(
                    tenantId: $order->tenant_id,
                    storeId: $order->store_id,
                    itemType: 'product',
                    itemId: (int) $order->product_id,
                    quantityDelta: (float) $actualProduced,
                    type: StockMovementType::AdjustmentIn,
                    unitCost: $productUnitCost,
                    reason: "Manufacturing output MO #{$order->order_number}",
                    idempotencyKey: "mo-prod-{$order->id}",
                    userId: $order->created_by
                );

                // 4. Catat WIP & Inventory Accounting Outbox Event (TASK-102)
                $productName = $product instanceof Product ? $product->product_name : "#{$order->product_id}";
                $this->financialOutboxDispatcher->recordOutboxEvent(
                    tenantId: $order->tenant_id,
                    storeId: $order->store_id,
                    eventType: 'manufacturing.production_completed',
                    sourceModule: 'manufacturing',
                    sourceType: 'manufacturing_order',
                    sourceId: $order->id,
                    sourceNumber: $order->order_number,
                    idempotencyKey: "outbox-mo-complete-{$order->id}",
                    payload: [
                        'manufacturing_order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'product_id' => $order->product_id,
                        'product_name' => $productName,
                        'produced_quantity' => (float) $actualProduced,
                        'total_material_cost' => (float) $totalMaterialCost,
                        'unit_material_cost' => (float) $productUnitCost,
                        'currency' => 'IDR',
                        'ingredients_consumed' => $consumedSummary,
                    ]
                );
            }

            // 5. Update order state
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
