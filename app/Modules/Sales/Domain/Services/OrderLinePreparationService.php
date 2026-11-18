<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\BundleComponentExpanderService;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Modifier;
use App\Modules\Pricing\Models\Discount;
use App\Modules\Pricing\Services\CatalogPriceResolverService;
use App\Modules\Sales\Application\Handlers\VoidAndRestoreOrderHandler;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prepares and validates order items, variants, modifiers, and BOM ingredient requirements (ADR-108).
 */
class OrderLinePreparationService
{
    public function __construct(
        protected OrderTotalsCalculatorService $totalsCalculator,
        protected VoidAndRestoreOrderHandler $voidHandler
    ) {}

    /**
     * @param  array<string, mixed>  $mergedItems
     * @param  array<string, mixed>  $data
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     subtotalAmount: float,
     *     ingredientRequirements: array<int, string>,
     *     bomBreakdownByLineIndex: array<int, list<array{ingredient_id: int, qty: string}>>,
     *     products: Collection<int, Product>,
     *     lockedIngredients: Collection<int, Ingredient>
     * }
     */
    public function prepareOrderLines(array $mergedItems, array $data, ?int $activeStoreId): array
    {
        $tenantContext = app(TenantContext::class);
        $lines = [];
        $ingredientRequirements = [];
        $bomBreakdownByLineIndex = [];
        $subtotalAmount = 0.0;

        $productIds = array_unique(array_map('intval', array_column($mergedItems, 'product_id')));
        sort($productIds);

        $modifierIds = [];
        foreach ($mergedItems as $item) {
            if (! empty($item['options'])) {
                foreach ($item['options'] as $opt) {
                    if (isset($opt['modifier_id'])) {
                        $modifierIds[] = $opt['modifier_id'];
                    }
                }
            }
        }
        $modifierIds = array_unique($modifierIds);
        $modifiersWithBom = Modifier::whereIn('id', $modifierIds)->with('ingredients')->get()->keyBy('id');

        $products = Product::whereIn('id', $productIds)
            ->with('ingredients')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $balancesAuthoritative = (bool) config('pos.stock_balances_authoritative', false);
        $storeIdForStock = $activeStoreId ?? $tenantContext->getStoreId();
        $lockedProductBalances = $balancesAuthoritative
            ? $this->voidHandler->lockProductBalances($productIds, $storeIdForStock)
            : collect();

        $catalogPriceResolver = app(CatalogPriceResolverService::class);

        foreach ($mergedItems as $item) {
            $product = $products->firstWhere('id', (int) $item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'One of the selected products is no longer available.',
                ]);
            }

            if (! $product->is_active || $product->status !== 'active') {
                throw ValidationException::withMessages([
                    'items' => 'Product "'.$product->product_name.'" is not active and cannot be sold.',
                ]);
            }

            if (! $catalogPriceResolver->isAvailableInStore($product, $activeStoreId)) {
                throw ValidationException::withMessages([
                    'items' => 'Product "'.$product->product_name.'" is not available at this branch.',
                ]);
            }

            $hasBom = $product->ingredients->isNotEmpty();
            $needsStockDeduction = ! $hasBom && $product->track_inventory && ! $product->isService() && ! $product->isNonInventory() && ! $product->isBundle();

            if ($product->isBundle()) {
                $bundleExpander = app(BundleComponentExpanderService::class);
                $avail = $bundleExpander->checkAvailability($product, (int) $activeStoreId, (int) $item['quantity']);
                if (! $avail['available']) {
                    $missingStr = collect($avail['missing_components'])
                        ->map(fn ($m) => "{$m['name']} (Req: {$m['required']}, Avail: {$m['available']})")
                        ->join(', ');
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for bundle '{$product->product_name}'. Missing: {$missingStr}",
                    ]);
                }
            }

            if ($needsStockDeduction) {
                $balance = $lockedProductBalances->get($product->id);
                $available = $balance ? $balance->quantity : $product->stock;

                if ($this->totalsCalculator->compareScaled((string) $available, (string) $item['quantity']) < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Insufficient stock for product "'.$product->product_name.'". (Available: '.$available.')',
                    ]);
                }
            }

            $verifiedOptions = [];
            $serverExtraPrice = 0;

            if (! empty($item['options'])) {
                foreach ($item['options'] as $opt) {
                    $modId = $opt['modifier_id'] ?? null;
                    $mod = $modId ? $modifiersWithBom->get($modId) : null;

                    if (! $mod || ! $mod->is_active) {
                        throw ValidationException::withMessages([
                            'items' => 'One of the selected variants is no longer available. Please refresh the page.',
                        ]);
                    }

                    $serverExtraPrice += (int) $mod->extra_price;
                    $verifiedOptions[] = [
                        'modifier_id' => $mod->id,
                        'name' => $mod->name,
                        'extra_price' => (int) $mod->extra_price,
                    ];
                }
            }

            $variantId = $item['product_variant_id'] ?? null;
            $customPrice = isset($item['custom_price']) ? (float) $item['custom_price'] : null;
            $baseProductPrice = $catalogPriceResolver->resolvePrice(
                product: $product,
                storeId: $activeStoreId,
                variantId: $variantId,
                quantity: $item['quantity'],
                customerId: $data['customer_id'] ?? null,
                loyaltyTierId: null,
                channel: $data['origin_channel'] ?? 'pos_cashier',
                uomId: isset($item['uom_id']) ? (int) $item['uom_id'] : null,
                customPrice: $customPrice
            );
            $unitPrice = $baseProductPrice + $serverExtraPrice;
            $itemSubtotal = $unitPrice * $item['quantity'];
            $subtotalAmount += $itemSubtotal;

            $lineIndex = count($lines);

            $itemDiscountAmount = (float) ($item['item_discount_amount'] ?? 0);
            if (! empty($item['item_discount_id']) && $itemDiscountAmount <= 0) {
                $itemDiscount = Discount::find((int) $item['item_discount_id']);
                if ($itemDiscount && $itemDiscount->is_active) {
                    if ($itemDiscount->type === 'percentage') {
                        $calc = (int) round($itemSubtotal * ($itemDiscount->value / 100));
                        if ($itemDiscount->max_discount_amount) {
                            $calc = min($calc, $itemDiscount->max_discount_amount);
                        }
                        $itemDiscountAmount = $calc;
                    } else {
                        $itemDiscountAmount = min((int) $itemSubtotal, $itemDiscount->value);
                    }
                }
            }
            $effectiveLineSubtotal = max(0, $itemSubtotal - $itemDiscountAmount);

            $lines[] = [
                'order_price' => $unitPrice,
                'qty' => $item['quantity'],
                'order_subtotal' => $itemSubtotal,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'uom_id' => isset($item['uom_id']) ? (int) $item['uom_id'] : null,
                'options' => $verifiedOptions,
                'notes' => $item['notes'] ?? null,
                'needs_stock_deduction' => $needsStockDeduction,
                'item_discount_id' => $item['item_discount_id'] ?? null,
                'item_discount_amount' => $itemDiscountAmount,
                'effective_subtotal' => $effectiveLineSubtotal,
                'product_name_snapshot' => $product->product_name,
                'sku_snapshot' => $product->sku,
                'hpp_snapshot' => $product->standard_cost ?? 0,
                'price_source' => $customPrice !== null ? 'OPEN_PRICE' : 'CATALOG',
                'applied_promotions_snapshot' => null,
            ];
            $bomBreakdownByLineIndex[$lineIndex] = [];

            if ($hasBom) {
                foreach ($product->ingredients as $ingredient) {
                    $qtyNeeded = bcmul(bc_num($ingredient->pivot->quantity_required), bc_num($item['quantity']), 4);
                    $ingredientRequirements[$ingredient->id] = bcadd(
                        $ingredientRequirements[$ingredient->id] ?? '0',
                        $qtyNeeded,
                        4
                    );
                    $bomBreakdownByLineIndex[$lineIndex][] = [
                        'ingredient_id' => $ingredient->id,
                        'qty' => $qtyNeeded,
                    ];
                }
            }

            if (! empty($item['options'])) {
                foreach ($item['options'] as $opt) {
                    if (isset($opt['modifier_id'])) {
                        /** @var Modifier|null $mod */
                        $mod = $modifiersWithBom->get($opt['modifier_id']);
                        if ($mod && $mod->ingredients->isNotEmpty()) {
                            foreach ($mod->ingredients as $ingredient) {
                                $qtyNeeded = bcmul(bc_num($ingredient->pivot->quantity_required), bc_num($item['quantity']), 4);
                                $ingredientRequirements[$ingredient->id] = bcadd(
                                    $ingredientRequirements[$ingredient->id] ?? '0',
                                    $qtyNeeded,
                                    4
                                );
                                $bomBreakdownByLineIndex[$lineIndex][] = [
                                    'ingredient_id' => $ingredient->id,
                                    'qty' => $qtyNeeded,
                                ];
                            }
                        }
                    }
                }
            }
        }

        $lockedIngredients = collect();
        if (! empty($ingredientRequirements)) {
            $ingredientIds = array_keys($ingredientRequirements);
            sort($ingredientIds);

            if ($balancesAuthoritative) {
                $lockedIngredients = Ingredient::whereIn('id', $ingredientIds)->get()->keyBy('id');
                $lockedIngredientBalances = $this->voidHandler->lockIngredientBalances($ingredientIds, $storeIdForStock);
            } else {
                $lockedIngredients = Ingredient::whereIn('id', $ingredientIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedIngredientBalances = collect();
            }

            foreach ($ingredientIds as $ingredientId) {
                $needed = $ingredientRequirements[$ingredientId];
                $ingredient = $lockedIngredients->get($ingredientId);

                if (! $ingredient) {
                    throw ValidationException::withMessages([
                        'items' => 'Ingredient with ID '.$ingredientId.' not found or has been archived.',
                    ]);
                }

                if (! $ingredient->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Ingredient "'.$ingredient->name.'" is disabled and cannot be used.',
                    ]);
                }

                $balance = $lockedIngredientBalances->get($ingredientId);
                $available = $balance ? $balance->quantity : $ingredient->current_stock;

                if ($this->totalsCalculator->compareScaled((string) $available, $needed) < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Insufficient stock for ingredient "'.$ingredient->name.'". '
                            .'(Required: '.$needed.' '.$ingredient->unit.', Available: '.$available.' '.$ingredient->unit.')',
                    ]);
                }
            }
        }

        return [
            'lines' => $lines,
            'subtotalAmount' => $subtotalAmount,
            'ingredientRequirements' => $ingredientRequirements,
            'bomBreakdownByLineIndex' => $bomBreakdownByLineIndex,
            'products' => $products,
            'lockedIngredients' => $lockedIngredients,
        ];
    }
}
