<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Services;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Modifier;
use App\Modules\Sales\Services\TransactionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Coordinates cart item validation, multi-variant/modifier line creation,
 * UoM conversions, barcode resolution, and batch cart lines for POS terminals (ADR-108).
 */
class PosCartCoordinatorService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected \App\Modules\Pricing\Services\CatalogPriceResolverService $priceResolver,
        protected \App\Modules\Catalog\Services\UnitConversionService $unitConversionService,
        protected \App\Modules\Catalog\Services\RetailBarcodeService $barcodeService
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array{
     *     variant_id?: int|null,
     *     uom_id?: int|null,
     *     modifier_ids?: array<int, int>,
     *     qty?: int,
     *     notes?: string|null
     * }  $pending
     * @return array<int, array<string, mixed>>
     */
    public function validateAndBuildCartItem(
        Product $product,
        array $cart,
        array $pending,
        ?int $storeId
    ): array {
        if (! $this->priceResolver->isAvailableInStore($product, $storeId)) {
            throw ValidationException::withMessages([
                'cart' => "Product '{$product->product_name}' is not available at this branch.",
            ]);
        }

        $pendingQty = max(1, (int) ($pending['qty'] ?? 1));
        $pendingVariantId = $pending['variant_id'] ?? null;
        $pendingUomId = $pending['uom_id'] ?? null;
        $pendingModifierIds = $pending['modifier_ids'] ?? [];
        $pendingNotes = isset($pending['notes']) && trim((string) $pending['notes']) !== '' ? trim((string) $pending['notes']) : null;

        $activeVariants = $product->variants->where('is_active', true);
        $selectedVariant = null;
        if ($activeVariants->isNotEmpty()) {
            if (! $pendingVariantId) {
                throw ValidationException::withMessages([
                    'cart' => "Pilih varian untuk produk {$product->product_name}.",
                ]);
            }
            $selectedVariant = $activeVariants->firstWhere('id', $pendingVariantId);
            if (! $selectedVariant) {
                throw ValidationException::withMessages([
                    'cart' => "Varian yang dipilih tidak valid untuk produk {$product->product_name}.",
                ]);
            }
        }

        $selectedModifiers = collect();
        if (! empty($pendingModifierIds)) {
            $selectedModifiers = Modifier::whereIn('id', $pendingModifierIds)->get();
            if ($selectedModifiers->count() !== count($pendingModifierIds)) {
                throw ValidationException::withMessages([
                    'cart' => 'Invalid modifiers selected.',
                ]);
            }
        }

        foreach ($product->modifierGroups as $group) {
            $selectedInGroup = $selectedModifiers->where('modifier_group_id', $group->id)->count();

            if ($group->is_required && $selectedInGroup === 0) {
                throw ValidationException::withMessages([
                    'cart' => "Required option for {$group->name} has not been selected.",
                ]);
            }

            if (! empty($group->min_select) && $selectedInGroup < $group->min_select) {
                throw ValidationException::withMessages([
                    'cart' => "Minimum {$group->min_select} option(s) required for {$group->name}.",
                ]);
            }

            if ($group->selection_type === 'single' && $selectedInGroup > 1) {
                throw ValidationException::withMessages([
                    'cart' => "Only one option may be selected for {$group->name}.",
                ]);
            }

            if (! empty($group->max_select) && $selectedInGroup > $group->max_select) {
                throw ValidationException::withMessages([
                    'cart' => "Maximum {$group->max_select} option(s) allowed for {$group->name}.",
                ]);
            }
        }

        $basePrice = (float) ($selectedVariant !== null && $selectedVariant->price_override !== null
            ? $selectedVariant->price_override
            : $product->product_price);
        $uomFactor = 1.0;
        if ($pendingUomId && $pendingUomId !== $product->base_uom_id && $product->base_uom_id) {
            try {
                $tenantId = (int) app(TenantContext::class)->requireTenantId();
                $converted = $this->unitConversionService->convertBetweenUoms(
                    tenantId: $tenantId,
                    fromUomId: (int) $pendingUomId,
                    toUomId: (int) $product->base_uom_id,
                    quantity: 1,
                    productId: (int) $product->id,
                    scale: 6
                );
                $uomFactor = (float) $converted;
            } catch (\Throwable) {
                $conversion = $product->unitConversions->firstWhere('from_uom_id', $pendingUomId);
                if ($conversion) {
                    $uomFactor = (float) $conversion->conversion_factor;
                }
            }
        } elseif ($pendingUomId && $pendingUomId !== $product->base_uom_id) {
            $conversion = $product->unitConversions->firstWhere('from_uom_id', $pendingUomId);
            if ($conversion) {
                $uomFactor = (float) $conversion->conversion_factor;
            }
        }

        $effectiveBasePrice = (float) bcmul((string) $basePrice, (string) $uomFactor, 2);

        $normalizedPendingMods = $pendingModifierIds;
        sort($normalizedPendingMods);
        $normalizedPendingUom = ($pendingUomId === $product->base_uom_id) ? null : $pendingUomId;

        foreach ($cart as $index => $item) {
            $itemMods = $item['modifier_ids'] ?? [];
            sort($itemMods);
            $itemUom = (($item['uom_id'] ?? null) === $product->base_uom_id) ? null : ($item['uom_id'] ?? null);
            if (
                $item['product_id'] === $product->id &&
                ($item['product_variant_id'] ?? null) === $pendingVariantId &&
                $itemUom === $normalizedPendingUom &&
                $itemMods === $normalizedPendingMods &&
                ($item['notes'] ?? null) === $pendingNotes
            ) {
                $cart[$index]['qty'] += $pendingQty;

                return $cart;
            }
        }

        $cartItem = [
            'product_id' => $product->id,
            'product_variant_id' => $pendingVariantId,
            'uom_id' => $pendingUomId,
            'qty' => $pendingQty,
            'modifier_ids' => $pendingModifierIds,
            'notes' => $pendingNotes,
        ];

        if ($uomFactor !== 1.0) {
            $cartItem['custom_price'] = $effectiveBasePrice;
        }

        $cart[] = $cartItem;

        return $cart;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    public function buildCartLines(array $cart, ?int $customerId, ?int $storeId): Collection
    {
        $productIds = collect($cart)->pluck('product_id')->unique()->values();
        $variantIds = collect($cart)->pluck('product_variant_id')->filter()->unique()->values();
        $uomIds = collect($cart)->pluck('uom_id')->filter()->unique()->values();

        $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = \App\Modules\Catalog\Models\ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
        $uoms = \App\Modules\Catalog\Models\UnitOfMeasure::whereIn('id', $uomIds)->get()->keyBy('id');

        return collect($cart)->map(function ($raw, $i) use ($products, $variants, $uoms, $customerId) {
            $product = $products->get($raw['product_id']);
            if (! $product) {
                return null;
            }

            $qty = max(1, (int) ($raw['qty'] ?? 1));
            $variantId = isset($raw['product_variant_id']) ? (int) $raw['product_variant_id'] : null;
            $uomId = isset($raw['uom_id']) ? (int) $raw['uom_id'] : null;

            $line = $this->transactionService->resolveOrderItemLine(
                $product,
                $raw['modifier_ids'] ?? [],
                $qty,
                $raw['notes'] ?? null,
                $variantId,
                $customerId,
                'pos_cashier',
                isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                $uomId
            );

            if ($variantId && isset($variants[$variantId])) {
                $line['variant_name'] = $variants[$variantId]->variant_name;
                $line['product_name'] = "{$product->product_name} - {$variants[$variantId]->variant_name}";
            }

            if ($uomId && isset($uoms[$uomId])) {
                $line['uom_name'] = $uoms[$uomId]->name;
                $line['product_name'] .= " ({$uoms[$uomId]->code})";
            }

            $subtotal = $line['order_subtotal'] ?? ($line['order_price'] * $line['qty']);
            $line['line_subtotal'] = $subtotal;
            $line['order_subtotal'] = $subtotal;

            return [...$line, 'index' => $i];
        })->filter()->values();
    }

    /**
     * @return array<int, int>
     */
    public function getVariantStocks(Product $product, ?int $storeId): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $variantStocks = [];

        if (! $product->track_inventory) {
            foreach ($product->variants as $v) {
                $variantStocks[$v->id] = 999999;
            }

            return $variantStocks;
        }

        $parentStock = DB::table('product_stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->value('quantity') ?? ($product->stock ?? 0);

        $hasVariantMovements = Schema::hasColumn('stock_movements', 'variant_id');
        foreach ($product->variants as $v) {
            if ($hasVariantMovements) {
                $movementCount = DB::table('stock_movements')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('variant_id', $v->id)
                    ->count();
                if ($movementCount > 0) {
                    $vQty = DB::table('stock_movements')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('variant_id', $v->id)
                        ->sum('quantity');
                    $variantStocks[$v->id] = (int) $vQty;

                    continue;
                }
            }
            $variantStocks[$v->id] = (int) $parentStock;
        }

        return $variantStocks;
    }

    /**
     * @return array{action: string, variant?: \App\Modules\Catalog\Models\ProductVariant, product?: Product, scaleProduct?: Product, scaleData?: array<string, mixed>, message?: string, error?: string}
     */
    public function resolveBarcode(string $code, ?int $storeId): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $variant = \App\Modules\Catalog\Models\ProductVariant::with(['product.modifierGroups.modifiers', 'attributeValues'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->first();

        if ($variant && $variant->product) {
            if (! $this->priceResolver->isAvailableInStore($variant->product, $storeId)) {
                return ['action' => 'error', 'error' => "Product '{$variant->product->product_name}' ({$variant->variant_name}) is not available at this branch."];
            }

            return [
                'action' => 'variant',
                'variant' => $variant,
                'message' => "Added: {$variant->product->product_name} ({$variant->variant_name})",
            ];
        }

        $product = Product::with(['modifierGroups', 'variants'])->where('tenant_id', $tenantId)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('product_code', $code);
            })
            ->where('is_active', true)
            ->first();

        if ($product) {
            $product->loadMissing(['unitConversions', 'modifierGroups.modifiers', 'variants' => fn ($q) => $q->where('is_active', true)]);
            $hasActiveVariants = $product->variants->where('is_active', true)->isNotEmpty();
            $hasModifierGroups = $product->modifierGroups->isNotEmpty();
            $hasUnitConversions = $product->unitConversions->isNotEmpty();

            if ($hasActiveVariants || $hasModifierGroups || $hasUnitConversions) {
                return ['action' => 'picker', 'product' => $product];
            }

            return ['action' => 'direct', 'product' => $product, 'message' => "Added: {$product->product_name}"];
        }

        $scaleData = $this->barcodeService->parseScaleBarcode($code);
        if ($scaleData !== null) {
            $itemCode = $scaleData['item_code'];
            $scaleProduct = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($q) use ($itemCode) {
                    $q->where('product_code', $itemCode)
                        ->orWhere('product_code', 'PLU-'.$itemCode)
                        ->orWhere('barcode', 'like', "%{$itemCode}%");
                })
                ->first();

            if ($scaleProduct) {
                if (! $this->priceResolver->isAvailableInStore($scaleProduct, $storeId)) {
                    return ['action' => 'error', 'error' => "Produk timbangan '{$scaleProduct->product_name}' tidak tersedia di cabang ini."];
                }

                return [
                    'action' => 'scale',
                    'scaleProduct' => $scaleProduct,
                    'scaleData' => $scaleData,
                    'message' => "Produk timbangan: {$scaleProduct->product_name} ({$scaleData['weight_kg']} kg)",
                ];
            }
        }

        return ['action' => 'error', 'error' => "Produk dengan barcode/SKU '{$code}' tidak ditemukan."];
    }

    /**
     * @param  array<int, int>  $modifierIds
     */
    public function calculateConfiguratorPrice(
        ?int $productId,
        ?int $variantId,
        ?int $uomId,
        array $modifierIds,
        int $qty
    ): float {
        if (! $productId) {
            return 0.0;
        }

        $product = Product::with([
            'variants' => fn ($q) => $q->where('is_active', true),
            'unitConversions',
        ])->find($productId);

        if (! $product) {
            return 0.0;
        }

        $basePrice = (float) $product->product_price;
        if ($variantId) {
            $variant = $product->variants->firstWhere('id', $variantId);
            if ($variant && $variant->price_override !== null) {
                $basePrice = (float) $variant->price_override;
            }
        }

        $uomFactor = 1.0;
        if ($uomId && $uomId !== $product->base_uom_id && $product->base_uom_id) {
            try {
                $tenantId = (int) app(TenantContext::class)->requireTenantId();
                $converted = $this->unitConversionService->convertBetweenUoms(
                    tenantId: $tenantId,
                    fromUomId: (int) $uomId,
                    toUomId: (int) $product->base_uom_id,
                    quantity: 1,
                    productId: (int) $product->id,
                    scale: 6
                );
                $uomFactor = (float) $converted;
            } catch (\Throwable) {
                $conversion = $product->unitConversions->firstWhere('from_uom_id', $uomId);
                if ($conversion) {
                    $uomFactor = (float) $conversion->conversion_factor;
                }
            }
        } elseif ($uomId && $uomId !== $product->base_uom_id) {
            $conversion = $product->unitConversions->firstWhere('from_uom_id', $uomId);
            if ($conversion) {
                $uomFactor = (float) $conversion->conversion_factor;
            }
        }

        $effectiveBase = (float) bcmul((string) $basePrice, (string) $uomFactor, 2);

        $extraPrice = 0.0;
        if (! empty($modifierIds)) {
            $extraPrice = (float) Modifier::whereIn('id', $modifierIds)->sum('extra_price');
        }

        $unitPrice = (float) bcadd((string) $effectiveBase, (string) $extraPrice, 2);

        return (float) bcmul((string) $unitPrice, (string) max(1, $qty), 2);
    }

    /**
     * @param  array<int, int>  $modifierIds
     */
    public function isConfiguratorValid(?int $productId, ?int $variantId, array $modifierIds): bool
    {
        if (! $productId) {
            return false;
        }

        $product = Product::with([
            'modifierGroups',
            'variants' => fn ($q) => $q->where('is_active', true),
        ])->find($productId);

        if (! $product) {
            return false;
        }

        if ($product->variants->isNotEmpty() && ! $variantId) {
            return false;
        }

        $requiredGroups = $product->modifierGroups->where('is_required', true);
        if ($requiredGroups->isNotEmpty()) {
            $selectedMods = Modifier::whereIn('id', $modifierIds)->get();
            $selectedGroupIds = $selectedMods->pluck('modifier_group_id')->unique();
            foreach ($requiredGroups as $group) {
                if (! $selectedGroupIds->contains($group->id)) {
                    return false;
                }
                $minSelect = $group->min_select ?? 1;
                if ($selectedMods->where('modifier_group_id', $group->id)->count() < $minSelect) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    public function buildCalculationInput(
        int $tenantId,
        int $storeId,
        array $cart,
        ?int $customerId,
        ?string $voucherCode,
        ?int $discountId,
        float $appliedManualDiscount,
        bool $hasCashPayment
    ): \App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto {
        $items = [];
        foreach ($cart as $raw) {
            $items[] = [
                'product_id' => (int) $raw['product_id'],
                'product_variant_id' => $raw['product_variant_id'] ?? null,
                'uom_id' => $raw['uom_id'] ?? null,
                'quantity' => (float) ($raw['qty'] ?? 1),
                'custom_price' => isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                'modifier_ids' => $raw['modifier_ids'] ?? [],
                'item_discount_amount' => (float) ($raw['item_discount_amount'] ?? 0),
                'item_discount_id' => $raw['item_discount_id'] ?? null,
            ];
        }

        return new \App\Modules\Pricing\DataTransferObjects\CheckoutCalculationInputDto(
            tenantId: $tenantId,
            storeId: $storeId,
            channel: 'pos_cashier',
            customerId: $customerId,
            items: $items,
            voucherCode: $voucherCode,
            discountId: $discountId,
            manualDiscountAmount: $appliedManualDiscount,
            hasCashPayment: $hasCashPayment
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<int, array{method: string, amount: int, amount_tendered: int, change_amount: int, card_number?: string|null, reference_number?: string|null, pin?: string|null}>
     */
    public function normalizePaymentLegs(array $legs, ?int $cashReceived, int $totalDue, string $defaultPaymentMethod): array
    {
        $normalized = [];
        if (empty($legs) && $cashReceived !== null) {
            if ($cashReceived < $totalDue) {
                throw ValidationException::withMessages(['cart' => 'Cash received is less than total amount due.']);
            }
            $normalized[] = [
                'method' => $defaultPaymentMethod,
                'amount' => $totalDue,
                'amount_tendered' => $cashReceived,
                'change_amount' => max(0, $cashReceived - $totalDue),
            ];

            return $normalized;
        }

        foreach ($legs as $leg) {
            $method = \App\Enums\PaymentMethod::tryFrom((string) $leg['method']);
            $amount = (int) $leg['amount'];

            if (! $method || $amount <= 0) {
                throw ValidationException::withMessages(['cart' => 'One of the payment methods is invalid.']);
            }

            $tendered = isset($leg['amount_tendered'])
                ? (int) $leg['amount_tendered']
                : ($method === \App\Enums\PaymentMethod::Cash && ($cashReceived ?? 0) >= $amount ? (int) $cashReceived : $amount);
            $changeAmount = isset($leg['change_amount'])
                ? (int) $leg['change_amount']
                : ($method === \App\Enums\PaymentMethod::Cash ? max(0, $tendered - $amount) : 0);

            $normalized[] = [
                'method' => $method->value,
                'amount' => $amount,
                'amount_tendered' => $tendered,
                'change_amount' => $changeAmount,
                'card_number' => $leg['card_number'] ?? $leg['reference_number'] ?? null,
                'reference_number' => $leg['reference_number'] ?? $leg['card_number'] ?? null,
                'pin' => $leg['pin'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Builds the domain transaction payload from Livewire POS cashier cart state (ADR-108).
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<int, array<string, mixed>>  $normalizedLegs
     * @param  array<string, mixed>  $meta
     * @return array{payload: array<string, mixed>, uses_midtrans_gateway: bool}
     */
    public function buildPosOrderPayload(array $cart, array $normalizedLegs, array $meta): array
    {
        $productIds = collect($cart)->pluck('product_id')->unique()->values();
        $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

        $items = [];
        foreach ($cart as $raw) {
            $product = $products->get($raw['product_id']) ?? Product::findOrFail($raw['product_id']);
            $line = $this->transactionService->resolveOrderItemLine(
                $product,
                $raw['modifier_ids'] ?? [],
                max(1, (int) ($raw['qty'] ?? 1)),
                $raw['notes'] ?? null,
                $raw['product_variant_id'] ?? null,
                $meta['customer_id'] ?? null,
                'pos_cashier',
                isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                isset($raw['uom_id']) ? (int) $raw['uom_id'] : null
            );

            $items[] = [
                'product_id' => $line['product_id'],
                'product_variant_id' => $raw['product_variant_id'] ?? null,
                'uom_id' => isset($raw['uom_id']) ? (int) $raw['uom_id'] : null,
                'custom_price' => isset($raw['custom_price']) ? (float) $raw['custom_price'] : null,
                'scale_barcode' => $raw['scale_barcode'] ?? null,
                'quantity' => $line['qty'],
                'extra_price' => $line['order_price'] - $product->product_price,
                'options' => $line['options'],
                'notes' => $line['notes'] ?? null,
                'item_discount_amount' => (int) ($raw['item_discount_amount'] ?? 0),
                'item_approval_id' => $raw['item_approval_id'] ?? null,
            ];
        }

        $isMidtrans = ! empty($meta['use_midtrans_gateway'])
            && count($normalizedLegs) === 1
            && in_array($normalizedLegs[0]['method'], ['qris', 'ewallet'], true);

        $orderType = $meta['order_type'] ?? 'takeaway';
        $isOfd = in_array($orderType, ['grabfood', 'gofood'], true);

        $payload = [
            'items' => $items,
            'customer_id' => $meta['customer_id'] ?? null,
            'table_id' => $meta['table_id'] ?? null,
            'order_type' => $isOfd ? 'takeaway' : $orderType,
            'order_channel' => $isOfd ? $orderType : 'pos_cashier',
            'origin_channel' => $isOfd ? $orderType : 'pos_cashier',
            'external_short_code' => $isOfd ? ($meta['external_short_code'] ?? null) : null,
            'driver_name' => $isOfd ? ($meta['driver_name'] ?? null) : null,
            'driver_phone' => $isOfd ? ($meta['driver_phone'] ?? null) : null,
            'platform_commission_rate' => $meta['platform_commission_rate'] ?? 0.00,
            'platform_commission_amount' => $meta['platform_commission_amount'] ?? 0.00,
            'merchant_promo_subsidy' => $meta['merchant_promo_subsidy'] ?? 0.00,
            'net_payout_amount' => $meta['net_payout_amount'] ?? 0.00,
            'device_id' => $meta['device_id'] ?? null,
            'shift_id' => $meta['shift_id'] ?? null,
            'discount_id' => $meta['discount_id'] ?? null,
            'manual_discount_amount' => (int) ($meta['manual_discount_amount'] ?? 0),
            'approval_id' => $meta['approval_id'] ?? null,
            'cart_uuid' => $meta['cart_uuid'] ?? null,
            'idempotency_key' => $meta['idempotency_key'] ?? null,
        ];

        if ($isMidtrans) {
            $payload['payment_method'] = $normalizedLegs[0]['method'];
            $payload['use_midtrans_gateway'] = true;
        } else {
            $payload['payments'] = $normalizedLegs;
        }

        return ['payload' => $payload, 'uses_midtrans_gateway' => $isMidtrans];
    }
}
