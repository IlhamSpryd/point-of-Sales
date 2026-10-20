<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductAssortment;
use Illuminate\Support\Facades\DB;

/**
 * CatalogPriceResolverService:
 * Bertanggung jawab menyelesaikan harga katalog dinamis deterministik (ADR-02)
 * berdasarkan hierarki prioritas Tier-1 ERP:
 *
 * 1. price_lists aktif customer_specific untuk customer_id terkait (min_quantity <= qty tertinggi).
 * 2. price_lists aktif (valid_from <= NOW() <= valid_until) yang cocok dengan konteks
 *    (store_id, loyalty_tier_id, channel, min_quantity <= qty), diurutkan priority ASC, min_quantity DESC, id ASC.
 * 3. store_product_assortments.store_price_override pada cabang (store_id) aktif (level varian -> level produk).
 * 4. product_variants.price_override (jika item adalah varian).
 * 5. products.product_price (harga dasar master produk).
 */
class CatalogPriceResolverService
{
    /**
     * Menyelesaikan harga jual produk dengan hierarki prioritas penuh.
     *
     * @param  int|string|null  $storeId  Cabang transaksi
     * @param  int|null  $variantId  Varian spesifik (jika ada)
     * @param  float|int  $quantity  Kuantitas yang dibeli (untuk harga grosir/tier kuantitas)
     * @param  int|null  $customerId  ID Pelanggan (untuk kontrak harga spesifik)
     * @param  int|null  $loyaltyTierId  ID Tier Loyalti/Member
     * @param  string|null  $channel  Kanal penjualan (pos_cashier, self_order, delivery, dll)
     * @param  int|null  $uomId  Satuan ukur (jika ada harga berbasis UoM)
     */
    public function resolvePrice(
        Product $product,
        int|string|null $storeId = null,
        ?int $variantId = null,
        float|int $quantity = 1,
        ?int $customerId = null,
        ?int $loyaltyTierId = null,
        ?string $channel = null,
        ?int $uomId = null
    ): float {
        $tenantId = $product->tenant_id;
        $storeIdInt = $storeId !== null ? (int) $storeId : null;
        $qty = max(1.0, (float) $quantity);
        $now = now();

        // -------------------------------------------------------------
        // TIER 1: Kontrak Harga Khusus Pelanggan (customer_specific)
        // -------------------------------------------------------------
        if ($customerId !== null) {
            $customerPlIds = PriceList::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('type', 'customer_specific')
                ->where('customer_id', $customerId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                ->orderBy('priority', 'asc')
                ->pluck('id');

            if ($customerPlIds->isNotEmpty()) {
                $item = $this->findBestMatchingItem($tenantId, $customerPlIds->all(), $product->id, $variantId, $qty, $uomId);
                if ($item !== null) {
                    return (float) $item->price;
                }
            }
        }

        // -------------------------------------------------------------
        // TIER 2: Price Lists Aktif Konteks (branch, wholesale, customer_tier, channel, standard)
        // -------------------------------------------------------------
        $contextPlQuery = PriceList::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('type', '!=', 'customer_specific')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
            ->where(function ($q) use ($storeIdInt, $loyaltyTierId, $channel) {
                // Kecocokan tipe branch
                if ($storeIdInt !== null) {
                    $q->orWhere(fn ($sub) => $sub->where('type', 'branch')->where('store_id', $storeIdInt));
                }
                // Kecocokan tipe customer_tier
                if ($loyaltyTierId !== null) {
                    $q->orWhere(fn ($sub) => $sub->where('type', 'customer_tier')->where('loyalty_tier_id', $loyaltyTierId));
                }
                // Kecocokan tipe channel
                if ($channel !== null) {
                    $q->orWhere(fn ($sub) => $sub->where('type', 'channel')->where('channel', $channel));
                }
                // Tipe wholesale atau standard berlaku luas
                $q->orWhereIn('type', ['wholesale', 'standard']);
            })
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->pluck('id');

        if ($contextPlQuery->isNotEmpty()) {
            $item = $this->findBestMatchingItem($tenantId, $contextPlQuery->all(), $product->id, $variantId, $qty, $uomId);
            if ($item !== null) {
                return (float) $item->price;
            }
        }

        // -------------------------------------------------------------
        // TIER 3: Override Harga Cabang (store_product_assortments)
        // -------------------------------------------------------------
        if ($storeIdInt !== null) {
            // 3a. Cek override harga cabang untuk varian spesifik
            if ($variantId !== null) {
                $variantAssortment = StoreProductAssortment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeIdInt)
                    ->where('product_variant_id', $variantId)
                    ->first();

                if ($variantAssortment && $variantAssortment->store_price_override !== null) {
                    return (float) $variantAssortment->store_price_override;
                }
            }

            // 3b. Cek override harga cabang untuk master produk
            $productAssortment = StoreProductAssortment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeIdInt)
                ->where('product_id', $product->id)
                ->whereNull('product_variant_id')
                ->first();

            if ($productAssortment && $productAssortment->store_price_override !== null) {
                return (float) $productAssortment->store_price_override;
            }
        }

        // -------------------------------------------------------------
        // TIER 4: Override Harga Varian (product_variants.price_override)
        // -------------------------------------------------------------
        if ($variantId !== null) {
            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('id', $variantId)
                ->first();

            if ($variant && $variant->price_override !== null) {
                return (float) $variant->price_override;
            }
        }

        // -------------------------------------------------------------
        // TIER 5: Harga Dasar Master Produk (products.product_price)
        // -------------------------------------------------------------
        return (float) ($product->product_price ?? $product->price ?? 0.0);
    }

    /**
     * Mencari item harga terbaik dari kumpulan price list yang cocok dengan kuantitas dan varian.
     *
     * @param  array<int>  $priceListIds
     */
    protected function findBestMatchingItem(
        int $tenantId,
        array $priceListIds,
        int $productId,
        ?int $variantId,
        float $quantity,
        ?int $uomId = null
    ): ?PriceListItem {
        $query = PriceListItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('price_list_id', $priceListIds)
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity);

        if ($variantId !== null) {
            // Utamakan item varian, jika tidak ada fallback ke item produk tanpa varian
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

        // Urutkan:
        // 1. Yang varian cocok diletakkan sebelum null varian
        // 2. min_quantity tertinggi (tier kuantitas grosir paling pas)
        // 3. price_list_id sesuai urutan prioritas
        $items = $query->get();

        if ($items->isEmpty()) {
            return null;
        }

        // Sorting in-memory untuk memastikan prioritas multi-kriteria presisi
        $sorted = $items->sort(function (PriceListItem $a, PriceListItem $b) use ($priceListIds, $variantId) {
            // Prioritas 1: Kecocokan Price List sesuai index di $priceListIds (priority terendah/teratas lebih dulu)
            $plIndexA = array_search($a->price_list_id, $priceListIds, true);
            $plIndexB = array_search($b->price_list_id, $priceListIds, true);
            if ($plIndexA !== $plIndexB) {
                return $plIndexA <=> $plIndexB;
            }

            // Prioritas 2: Spesifisitas Varian (Varian spesifik > Varian null)
            if ($variantId !== null) {
                $aHasVar = ($a->product_variant_id === $variantId) ? 1 : 0;
                $bHasVar = ($b->product_variant_id === $variantId) ? 1 : 0;
                if ($aHasVar !== $bHasVar) {
                    return $bHasVar <=> $aHasVar;
                }
            }

            // Prioritas 3: Kuantitas Minimum Tertinggi (Wholesale bracket tertinggi yang terpenuhi)
            return $b->min_quantity <=> $a->min_quantity;
        });

        return $sorted->first();
    }

    /**
     * Memeriksa apakah produk layak jual dan tersedia di cabang dan kanal tertentu.
     * Aturan backward-compatibility: Jika baris assortment belum dibuat untuk cabang tersebut,
     * produk dianggap tersedia secara default (is_available = true) selama produk master berstatus 'active'.
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
     * Mengambil mapping harga cabang untuk sekelompok produk sekaligus.
     * Berguna untuk optimasi batch di Kasir atau Menu pelanggan.
     *
     * @param  array<int>  $productIds
     * @return array<int, float>
     */
    public function resolveBatchPrices(int $tenantId, int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $overrides = DB::table('store_product_assortments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->whereNull('product_variant_id')
            ->whereNotNull('store_price_override')
            ->pluck('store_price_override', 'product_id')
            ->all();

        $basePrices = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->pluck('product_price', 'id')
            ->all();

        $resolved = [];
        foreach ($productIds as $pid) {
            if (isset($overrides[$pid])) {
                $resolved[$pid] = (float) $overrides[$pid];
            } else {
                $resolved[$pid] = (float) ($basePrices[$pid] ?? 0.0);
            }
        }

        return $resolved;
    }
}
