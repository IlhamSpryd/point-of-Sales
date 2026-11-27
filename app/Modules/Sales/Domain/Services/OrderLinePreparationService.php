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
     *     approvalRequiredItemDiscountSum: float,
     *     ingredientRequirements: array<int, string>,
     *     bomBreakdownByLineIndex: array<int, list<array{ingredient_id: int, qty: string}>>,
     *     products: Collection<int, Product>,
     *     lockedIngredients: Collection<int, Ingredient>
     * }
     */
    public function prepareOrderLines(array $mergedItems, array $data, ?int $activeStoreId, ?int $userId = null): array
    {
        $tenantContext = app(TenantContext::class);
        $lines = [];
        $ingredientRequirements = [];
        $bomBreakdownByLineIndex = [];
        $subtotalAmount = 0.0;
        $approvalRequiredItemDiscountSum = 0.0;

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
        $discountApprovalService = app(\App\Modules\Pricing\Services\DiscountApprovalService::class);
        $resolvedUserId = $userId ?? (isset($data['user_id']) ? (int) $data['user_id'] : null);
        $firstProduct = $products->first();
        $firstTenantId = $firstProduct !== null ? $firstProduct->tenant_id : $tenantContext->getTenantId();
        $actor = null;
        if ($resolvedUserId !== null) {
            $actor = $firstTenantId !== null
                ? $tenantContext->runAs((int) $firstTenantId, $activeStoreId, fn () => \App\Modules\Organization\Models\User::with('role')->where('tenant_id', (int) $firstTenantId)->find($resolvedUserId))
                : \App\Modules\Organization\Models\User::with('role')->find($resolvedUserId);
        }
        if ($actor === null) {
            $authUser = auth()->user();
            if ($authUser instanceof \App\Modules\Organization\Models\User) {
                $actor = $authUser;
            }
        }

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

            $variantId = isset($item['product_variant_id']) && $item['product_variant_id'] !== '' ? (int) $item['product_variant_id'] : null;
            $uomId = isset($item['uom_id']) && $item['uom_id'] !== '' ? (int) $item['uom_id'] : null;
            $customPrice = $this->resolveValidatedCustomPrice(
                product: $product,
                item: $item,
                variantId: $variantId,
                uomId: $uomId,
                activeStoreId: $activeStoreId,
                customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                channel: (string) ($data['origin_channel'] ?? 'pos_cashier'),
                actor: $actor,
                catalogPriceResolver: $catalogPriceResolver
            );

            $baseProductPrice = $catalogPriceResolver->resolvePrice(
                product: $product,
                storeId: $activeStoreId,
                variantId: $variantId,
                quantity: $item['quantity'],
                customerId: $data['customer_id'] ?? null,
                loyaltyTierId: null,
                channel: $data['origin_channel'] ?? 'pos_cashier',
                uomId: $uomId,
                customPrice: $customPrice
            );
            $unitPrice = $baseProductPrice + $serverExtraPrice;
            $itemSubtotal = $unitPrice * $item['quantity'];
            $subtotalAmount += $itemSubtotal;

            $lineIndex = count($lines);

            $itemDiscountAmount = 0.0;
            if (! empty($item['item_discount_id'])) {
                $itemDiscount = Discount::where('tenant_id', $product->tenant_id)->find((int) $item['item_discount_id']);
                $now = now();
                if (! $itemDiscount || ! $itemDiscount->is_active
                    || ($itemDiscount->valid_from && $now->lt($itemDiscount->valid_from))
                    || ($itemDiscount->valid_until && $now->gt($itemDiscount->valid_until))) {
                    throw ValidationException::withMessages([
                        'items' => 'Selected item discount voucher is invalid or expired.',
                    ]);
                }

                if ($itemDiscount->type === 'percentage') {
                    $calc = (int) round($itemSubtotal * ($itemDiscount->value / 100));
                    if ($itemDiscount->max_discount_amount) {
                        $calc = min($calc, $itemDiscount->max_discount_amount);
                    }
                    $itemDiscountAmount = (float) min((int) $itemSubtotal, $calc);
                } else {
                    $itemDiscountAmount = (float) min((int) $itemSubtotal, $itemDiscount->value);
                }
            } elseif (isset($item['item_discount_amount']) && (float) $item['item_discount_amount'] !== 0.0) {
                $itemDiscountAmount = (float) $item['item_discount_amount'];
                if ($itemDiscountAmount < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Diskon item tidak boleh bernilai negatif.',
                    ]);
                }

                if ($itemDiscountAmount > $itemSubtotal) {
                    throw ValidationException::withMessages([
                        'items' => "Diskon item untuk '{$product->product_name}' tidak boleh melebihi subtotal item.",
                    ]);
                }

                $itemPercent = $itemSubtotal > 0 ? (($itemDiscountAmount / $itemSubtotal) * 100.0) : 100.0;
                if ($discountApprovalService->evaluateThreshold($itemPercent, $itemDiscountAmount, $itemSubtotal)) {
                    $itemApprovalId = ! empty($item['item_approval_id']) ? (int) $item['item_approval_id'] : (! empty($data['approval_id']) ? (int) $data['approval_id'] : null);
                    if ($itemApprovalId === null) {
                        throw ValidationException::withMessages([
                            'items' => "Diskon item untuk '{$product->product_name}' melebihi batas aman kasir dan memerlukan persetujuan Supervisor.",
                        ]);
                    }

                    if (! empty($item['item_approval_id'])) {
                        $discountApprovalService->assertValidApproval(
                            approvalId: $itemApprovalId,
                            tenantId: (int) $product->tenant_id,
                            storeId: $activeStoreId,
                            actorUserId: (int) ($actor !== null ? $actor->id : ($resolvedUserId ?? 0)),
                            requestedAmount: $itemDiscountAmount
                        );
                    } else {
                        $approvalRequiredItemDiscountSum += $itemDiscountAmount;
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
            'approvalRequiredItemDiscountSum' => $approvalRequiredItemDiscountSum,
            'ingredientRequirements' => $ingredientRequirements,
            'bomBreakdownByLineIndex' => $bomBreakdownByLineIndex,
            'products' => $products,
            'lockedIngredients' => $lockedIngredients,
        ];
    }

    /**
     * Validates custom_price in domain layer across UOM conversions, scale barcodes, and open-price permissions (P0-02).
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveValidatedCustomPrice(
        Product $product,
        array $item,
        ?int $variantId,
        ?int $uomId,
        ?int $activeStoreId,
        ?int $customerId,
        string $channel,
        ?\App\Modules\Organization\Models\User $actor,
        CatalogPriceResolverService $catalogPriceResolver
    ): ?float {
        if (! array_key_exists('custom_price', $item) || $item['custom_price'] === null || $item['custom_price'] === '') {
            return null;
        }

        $customPrice = (float) $item['custom_price'];
        if ($customPrice < 0) {
            throw ValidationException::withMessages([
                'items' => 'Harga kustom tidak boleh bernilai negatif.',
            ]);
        }

        $variant = $variantId !== null
            ? \App\Modules\Catalog\Models\ProductVariant::where('tenant_id', $product->tenant_id)->find($variantId)
            : null;
        $isOpenPrice = $variant !== null ? (bool) ($variant->is_open_price ?? $product->is_open_price ?? false) : (bool) ($product->is_open_price ?? false);

        if (! $isOpenPrice && $uomId !== null && $product->base_uom_id && $uomId !== (int) $product->base_uom_id) {
            try {
                $factor = (float) app(\App\Modules\Catalog\Services\UnitConversionService::class)->convertBetweenUoms(
                    (int) $product->tenant_id, $uomId, (int) $product->base_uom_id, 1, (int) $product->id, 6
                );
            } catch (\Throwable) {
                $conversion = $product->unitConversions()->where('from_uom_id', $uomId)->first();
                $factor = $conversion ? (float) $conversion->conversion_factor : 0.0;
            }

            if ($factor > 0) {
                $catalogBase = $catalogPriceResolver->resolvePrice(
                    $product, $activeStoreId, $variantId, $item['quantity'] ?? 1, $customerId, null, $channel, $uomId, null
                );
                $expectedUomPrice = (float) bcmul((string) $catalogBase, (string) $factor, 2);
                if (abs($customPrice - $expectedUomPrice) <= 0.01) {
                    return $expectedUomPrice;
                }
            }

            throw ValidationException::withMessages(['items' => "Harga konversi UOM tidak valid untuk produk '{$product->product_name}'."]);
        }

        if (! $isOpenPrice && ! empty($item['scale_barcode'])) {
            $parsed = app(\App\Modules\Catalog\Services\RetailBarcodeService::class)->parseScaleBarcode((string) $item['scale_barcode']);
            $matchesProduct = $parsed !== null && (
                $product->product_code === $parsed['item_code'] || str_starts_with((string) $product->barcode, $parsed['prefix'].$parsed['item_code'])
            );

            if ($parsed !== null && $matchesProduct) {
                $basePrice = $parsed['prefix'] === '02'
                    ? $catalogPriceResolver->resolvePrice($product, $activeStoreId, $variantId, 1, $customerId, null, $channel, null, null)
                    : 0.0;
                $expectedScalePrice = $parsed['prefix'] === '02'
                    ? (float) bcmul((string) $parsed['weight_kg'], (string) $basePrice, 2)
                    : (float) $parsed['embedded_price'];

                if (abs($customPrice - $expectedScalePrice) <= 0.01) {
                    return $expectedScalePrice;
                }
            }

            throw ValidationException::withMessages(['items' => "Barcode timbangan tidak valid untuk produk '{$product->product_name}'."]);
        }

        if (! $isOpenPrice) {
            throw ValidationException::withMessages(['items' => "Produk '{$product->product_name}' tidak mendukung harga manual (open price)."]);
        }

        $actorRole = $actor !== null ? $actor->role : null;
        $hasOpenPricePermission = $actor !== null && (
            $actor->hasPermission('pos.price.open_entry')
            || ($actorRole !== null && ($actorRole->is_admin || in_array($actorRole->name, ['Owner', 'Superadmin'], true)))
        );

        if (! $hasOpenPricePermission) {
            throw ValidationException::withMessages([
                'items' => 'Anda tidak memiliki hak akses untuk memasukkan harga terbuka (open price).',
            ]);
        }

        return $customPrice;
    }
}
