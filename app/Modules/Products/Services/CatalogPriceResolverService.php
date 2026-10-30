<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductAssortment;
use App\Modules\Products\DataTransferObjects\PriceResolutionContextDto;
use App\Modules\Products\DataTransferObjects\ResolvedPriceDto;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * CatalogPriceResolverService:
 * Bertanggung jawab menyelesaikan harga katalog dinamis deterministik (ADR-02 / Modul 6 Subdomain 5.4)
 * berdasarkan hierarki 4-level prioritas Tier-1 ERP:
 *
 * Prioritas 1 — Customer Contract Price:
 *   Harga aktif pada PriceList bertipe CUSTOMER_CONTRACT (atau customer_specific) yang terikat langsung
 *   ke customer_id pada rentang tanggal efektif dan memenuhi min_qty <= quantity.
 * Prioritas 2 — Customer Group Price List:
 *   Harga aktif pada PriceList yang terikat ke customer.customer_group_id (termasuk quantity break min_qty).
 * Prioritas 3 — Store / Channel Price List & Branch Override:
 *   Harga aktif pada PriceList spesifik cabang (store_id) atau kanal, atau override store_product_assortments.
 * Prioritas 4 — Base Product / Variant Price:
 *   Harga jual standar pada ProductVariant atau Product.
 *
 * Aturan Tie-Breaker:
 *   Priority (integer) terkecil -> min_quantity tertinggi -> harga terendah (menguntungkan pelanggan).
 */
class CatalogPriceResolverService
{
    /** @var array<string, mixed> */
    protected array $runtimeCache = [];

    public function clearRuntimeCache(): void
    {
        $this->runtimeCache = [];
    }

    /**
     * Menyelesaikan harga jual produk dengan hierarki prioritas penuh dan mengembalikan ResolvedPriceDto lengkap.
     */
    public function resolveCustomerPrice(PriceResolutionContextDto $context): ResolvedPriceDto
    {
        $tenantId = $context->tenantId;
        $productId = $context->productId;
        $variantId = $context->variantId;
        $uomId = $context->uomId;
        $quantity = max(1.0, (float) $context->quantity);
        $storeIdInt = $context->storeId !== null ? (int) $context->storeId : null;
        $customerId = $context->customerId;
        $customerGroupId = $context->customerGroupId;
        $channel = $context->channel;
        $loyaltyTierId = $context->loyaltyTierId;
        $now = $context->transactionDate ? Carbon::parse($context->transactionDate) : now();

        // 1. Ambil Produk Master untuk base price
        /** @var Product|null $product */
        $product = $context->product ?? Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($productId);

        $baseUnitPrice = (float) ($product?->product_price ?? $product?->price ?? 0.0);

        // Ambil Varian jika spesifik
        $variant = null;
        if ($variantId !== null) {
            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->find($variantId);
        }

        // 0. Prioritas 0 — Harga Terbuka (Open Price)
        if ($context->customPrice !== null) {
            $isOpenPrice = (bool) ($variant?->is_open_price ?? $product?->is_open_price ?? false);
            if ($isOpenPrice) {
                return new ResolvedPriceDto(
                    unitPrice: (float) $context->customPrice,
                    baseUnitPrice: (float) $context->customPrice,
                    pricingSource: ResolvedPriceDto::SOURCE_OPEN_PRICE,
                    meta: ['tier' => 0, 'open_price' => true]
                );
            }
        }

        // Resolusi Customer Group jika customerId ada namun customerGroupId belum dioper
        if ($customerId !== null && $customerGroupId === null) {
            $cgCacheKey = "cg_{$tenantId}_{$customerId}";
            if (! array_key_exists($cgCacheKey, $this->runtimeCache)) {
                $customer = Customer::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->find($customerId);
                $this->runtimeCache[$cgCacheKey] = $customer?->customer_group_id ? (int) $customer->customer_group_id : null;
            }
            $customerGroupId = $this->runtimeCache[$cgCacheKey];
        }

        // -------------------------------------------------------------
        // PRIORITAS 1: Kontrak Harga Khusus Pelanggan (CUSTOMER_CONTRACT)
        // -------------------------------------------------------------
        if ($customerId !== null) {
            $cKey = "cpls_{$tenantId}_{$customerId}";
            if (! isset($this->runtimeCache[$cKey])) {
                $this->runtimeCache[$cKey] = PriceList::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customerId)
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->where('target_type', PriceList::TARGET_CUSTOMER_CONTRACT)
                            ->orWhere('type', 'customer_specific');
                    })
                    ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                    ->orderBy('priority', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();
            }
            $contractPls = $this->runtimeCache[$cKey];

            if ($contractPls->isNotEmpty()) {
                $match = $this->findBestMatchingItemAcrossPriceLists($tenantId, $contractPls, $productId, $variantId, $quantity, $uomId, $baseUnitPrice);
                if ($match !== null) {
                    [$item, $priceList, $resolvedUnit] = $match;

                    return new ResolvedPriceDto(
                        unitPrice: $resolvedUnit,
                        baseUnitPrice: $baseUnitPrice,
                        priceListId: (int) $priceList->id,
                        priceListItemId: (int) $item->id,
                        pricingSource: ResolvedPriceDto::SOURCE_CUSTOMER_CONTRACT,
                        allowPromoStacking: (bool) $priceList->allow_promo_stacking,
                        discountPercentage: $item->discount_percentage !== null ? (float) $item->discount_percentage : null,
                        meta: ['tier' => 1, 'price_list_name' => $priceList->name]
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // PRIORITAS 2: Harga Grup Pelanggan (CUSTOMER_GROUP)
        // -------------------------------------------------------------
        if ($customerGroupId !== null) {
            $gKey = "gpls_{$tenantId}_{$customerGroupId}";
            if (! isset($this->runtimeCache[$gKey])) {
                $this->runtimeCache[$gKey] = PriceList::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_group_id', $customerGroupId)
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->where('target_type', PriceList::TARGET_CUSTOMER_GROUP)
                            ->orWhere('type', 'customer_tier');
                    })
                    ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                    ->orderBy('priority', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();
            }
            $groupPls = $this->runtimeCache[$gKey];

            if ($groupPls->isNotEmpty()) {
                $match = $this->findBestMatchingItemAcrossPriceLists($tenantId, $groupPls, $productId, $variantId, $quantity, $uomId, $baseUnitPrice);
                if ($match !== null) {
                    [$item, $priceList, $resolvedUnit] = $match;

                    return new ResolvedPriceDto(
                        unitPrice: $resolvedUnit,
                        baseUnitPrice: $baseUnitPrice,
                        priceListId: (int) $priceList->id,
                        priceListItemId: (int) $item->id,
                        pricingSource: ResolvedPriceDto::SOURCE_CUSTOMER_GROUP,
                        allowPromoStacking: (bool) $priceList->allow_promo_stacking,
                        discountPercentage: $item->discount_percentage !== null ? (float) $item->discount_percentage : null,
                        meta: ['tier' => 2, 'customer_group_id' => $customerGroupId, 'price_list_name' => $priceList->name]
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // PRIORITAS 3: Store / Channel / Standard Price List & Branch Override
        // -------------------------------------------------------------
        // 3a. Price list spesifik cabang, kanal, loyalty tier, atau standar
        $sKey = "spls_{$tenantId}_{$storeIdInt}_{$loyaltyTierId}_{$channel}";
        if (! isset($this->runtimeCache[$sKey])) {
            $this->runtimeCache[$sKey] = PriceList::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereIn('target_type', [PriceList::TARGET_GENERAL, PriceList::TARGET_STORE])
                        ->orWhereNull('target_type');
                })
                ->whereNotIn('type', ['customer_specific'])
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                ->where(function ($q) use ($storeIdInt, $loyaltyTierId, $channel) {
                    if ($storeIdInt !== null) {
                        $q->orWhere(fn ($sub) => $sub->where('store_id', $storeIdInt));
                        $q->orWhere(fn ($sub) => $sub->where('type', 'branch')->where('store_id', $storeIdInt));
                    }
                    if ($loyaltyTierId !== null) {
                        $q->orWhere(fn ($sub) => $sub->where('type', 'customer_tier')->where('loyalty_tier_id', $loyaltyTierId));
                    }
                    if ($channel !== null) {
                        $q->orWhere(fn ($sub) => $sub->where('type', 'channel')->where('channel', $channel));
                    }
                    $q->orWhereIn('type', ['wholesale', 'standard']);
                })
                ->orderBy('priority', 'asc')
                ->orderBy('id', 'asc')
                ->get();
        }
        $storePls = $this->runtimeCache[$sKey];

        if ($storePls->isNotEmpty()) {
            $match = $this->findBestMatchingItemAcrossPriceLists($tenantId, $storePls, $productId, $variantId, $quantity, $uomId, $baseUnitPrice);
            if ($match !== null) {
                [$item, $priceList, $resolvedUnit] = $match;

                return new ResolvedPriceDto(
                    unitPrice: $resolvedUnit,
                    baseUnitPrice: $baseUnitPrice,
                    priceListId: (int) $priceList->id,
                    priceListItemId: (int) $item->id,
                    pricingSource: ResolvedPriceDto::SOURCE_STORE_PRICE_LIST,
                    allowPromoStacking: (bool) $priceList->allow_promo_stacking,
                    discountPercentage: $item->discount_percentage !== null ? (float) $item->discount_percentage : null,
                    meta: ['tier' => 3, 'price_list_name' => $priceList->name]
                );
            }
        }

        // 3b. Override Harga Cabang (store_product_assortments)
        if ($storeIdInt !== null) {
            if ($variantId !== null) {
                $variantAssortment = StoreProductAssortment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeIdInt)
                    ->where('product_variant_id', $variantId)
                    ->first();

                if ($variantAssortment && $variantAssortment->store_price_override !== null) {
                    return new ResolvedPriceDto(
                        unitPrice: (float) $variantAssortment->store_price_override,
                        baseUnitPrice: $baseUnitPrice,
                        pricingSource: ResolvedPriceDto::SOURCE_STORE_OVERRIDE,
                        meta: ['tier' => 3, 'scope' => 'variant_assortment']
                    );
                }
            }

            $productAssortment = StoreProductAssortment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeIdInt)
                ->where('product_id', $productId)
                ->whereNull('product_variant_id')
                ->first();

            if ($productAssortment && $productAssortment->store_price_override !== null) {
                return new ResolvedPriceDto(
                    unitPrice: (float) $productAssortment->store_price_override,
                    baseUnitPrice: $baseUnitPrice,
                    pricingSource: ResolvedPriceDto::SOURCE_STORE_OVERRIDE,
                    meta: ['tier' => 3, 'scope' => 'product_assortment']
                );
            }
        }

        // -------------------------------------------------------------
        // PRIORITAS 4: Base Product / Variant Price
        // -------------------------------------------------------------
        if ($variant !== null && $variant->price_override !== null) {
            return new ResolvedPriceDto(
                unitPrice: (float) $variant->price_override,
                baseUnitPrice: $baseUnitPrice,
                pricingSource: ResolvedPriceDto::SOURCE_VARIANT_OVERRIDE,
                meta: ['tier' => 4, 'variant_id' => $variant->id]
            );
        }

        return new ResolvedPriceDto(
            unitPrice: $baseUnitPrice,
            baseUnitPrice: $baseUnitPrice,
            pricingSource: ResolvedPriceDto::SOURCE_BASE_PRODUCT,
            meta: ['tier' => 4]
        );
    }

    /**
     * Backward-compatible helper method yang mengembalikan float unit price.
     */
    public function resolvePrice(
        Product $product,
        int|string|null $storeId = null,
        ?int $variantId = null,
        float|int $quantity = 1,
        ?int $customerId = null,
        ?int $loyaltyTierId = null,
        ?string $channel = null,
        ?int $uomId = null,
        ?float $customPrice = null
    ): float {
        $context = new PriceResolutionContextDto(
            tenantId: (int) $product->tenant_id,
            productId: (int) $product->id,
            storeId: $storeId,
            variantId: $variantId,
            uomId: $uomId,
            quantity: $quantity,
            customerId: $customerId,
            loyaltyTierId: $loyaltyTierId,
            channel: $channel,
            customPrice: $customPrice,
            product: $product,
        );

        return $this->resolveCustomerPrice($context)->unitPrice;
    }

    /**
     * Mencari item harga terbaik dari kumpulan price lists dengan mempertimbangkan prioritas,
     * kuantitas minimum (quantity break), dan tie-breaker harga terendah.
     *
     * @param  Collection<int, PriceList>  $priceLists
     * @return array{0: PriceListItem, 1: PriceList, 2: float}|null
     */
    protected function findBestMatchingItemAcrossPriceLists(
        int $tenantId,
        Collection $priceLists,
        int $productId,
        ?int $variantId,
        float $quantity,
        ?int $uomId,
        float $baseUnitPrice
    ): ?array {
        $priceListIds = $priceLists->pluck('id')->all();
        $priceListsById = $priceLists->keyBy('id');

        $query = PriceListItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('price_list_id', $priceListIds)
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($q) use ($quantity) {
                $q->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            });

        if ($variantId !== null) {
            $query->where(function ($q) use ($variantId) {
                $q->where('product_variant_id', $variantId)
                    ->orWhereNull('product_variant_id');
            });
        } else {
            $query->whereNull('product_variant_id');
        }

        if ($uomId !== null) {
            $query->where(function ($q) use ($uomId) {
                $q->where('uom_id', $uomId)
                    ->orWhereNull('uom_id');
            });
        }

        $items = $query->get();
        if ($items->isEmpty()) {
            return null;
        }

        // Sorting in-memory multi-kriteria:
        // 1. Prioritas PriceList (urutan indeks pada $priceListIds yang sudah terurut priority ASC, id ASC)
        // 2. Spesifisitas Varian (Varian cocok > Varian null)
        // 3. Kuantitas Minimum Tertinggi (Tier grosir / quantity break paling relevan)
        // 4. Harga Terendah (Tie-breaker yang menguntungkan pelanggan)
        $sorted = $items->sort(function (PriceListItem $a, PriceListItem $b) use ($priceListIds, $variantId, $baseUnitPrice) {
            $plIdxA = array_search($a->price_list_id, $priceListIds, true);
            $plIdxB = array_search($b->price_list_id, $priceListIds, true);
            if ($plIdxA !== $plIdxB) {
                return $plIdxA <=> $plIdxB;
            }

            if ($variantId !== null) {
                $aHasVar = ($a->product_variant_id === $variantId) ? 1 : 0;
                $bHasVar = ($b->product_variant_id === $variantId) ? 1 : 0;
                if ($aHasVar !== $bHasVar) {
                    return $bHasVar <=> $aHasVar;
                }
            }

            if ($a->min_quantity !== $b->min_quantity) {
                return $b->min_quantity <=> $a->min_quantity;
            }

            // Hitung harga efektif masing-masing
            $priceA = $this->calculateItemEffectivePrice($a, $baseUnitPrice);
            $priceB = $this->calculateItemEffectivePrice($b, $baseUnitPrice);

            return $priceA <=> $priceB;
        });

        /** @var PriceListItem|null $bestItem */
        $bestItem = $sorted->first();
        if ($bestItem === null) {
            return null;
        }

        /** @var PriceList $bestPl */
        $bestPl = $priceListsById->get($bestItem->price_list_id);
        $effectivePrice = $this->calculateItemEffectivePrice($bestItem, $baseUnitPrice);

        return [$bestItem, $bestPl, $effectivePrice];
    }

    /**
     * Menghitung harga efektif baris price_list_item (apakah nominal tetap atau persentase diskon).
     */
    protected function calculateItemEffectivePrice(PriceListItem $item, float $baseUnitPrice): float
    {
        if ($item->price > 0 || $item->discount_percentage === null) {
            return (float) $item->price;
        }

        $discount = (float) $item->discount_percentage;
        $discountAmount = ($discount / 100.0) * $baseUnitPrice;

        return max(0.0, round($baseUnitPrice - $discountAmount, 2));
    }

    /**
     * Memeriksa ketersediaan produk di cabang tertentu.
     */
    public function isAvailableInStore(Product $product, int|string|null $storeId = null, ?string $channel = null): bool
    {
        if (! $product->is_active || $product->status !== 'active') {
            return false;
        }

        if ($storeId === null) {
            return true;
        }

        $storeIdInt = (int) $storeId;

        if ($product->relationLoaded('storeAssortments')) {
            /** @var StoreProductAssortment|null $assortment */
            $assortment = $product->storeAssortments
                ->first(fn (StoreProductAssortment $a): bool => (int) $a->store_id === $storeIdInt);
        } else {
            /** @var StoreProductAssortment|null $assortment */
            $assortment = StoreProductAssortment::withoutGlobalScopes()
                ->where('tenant_id', $product->tenant_id)
                ->where('store_id', $storeIdInt)
                ->where('product_id', $product->id)
                ->first();
        }

        if ($assortment instanceof StoreProductAssortment) {
            if (! $assortment->is_available) {
                return false;
            }

            if ($channel !== null && ! empty($assortment->allowed_channels)) {
                if (! in_array($channel, $assortment->allowed_channels, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Menyelesaikan harga cabang massal tanpa N+1 query.
     *
     * @param  array<int>  $productIds
     * @return array<int, float>
     */
    public function resolveBatchPrices(int $tenantId, int $storeId, array $productIds, ?int $customerId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $resolved = [];
        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $pid) {
            $prod = $products->get($pid);
            if (! $prod) {
                $resolved[$pid] = 0.0;

                continue;
            }

            $resolved[$pid] = $this->resolvePrice(
                product: $prod,
                storeId: $storeId,
                customerId: $customerId
            );
        }

        return $resolved;
    }
}
