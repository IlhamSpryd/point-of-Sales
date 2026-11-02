<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\StoreProductAssortment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * StoreAssortmentService — Layanan tata kelola ketersediaan katalog produk per cabang gerai.
 *
 * Mengikuti kriteria penerimaan T-CAT-02:
 * Produk yang dinonaktifkan pada assortment cabang A tidak muncul di katalog cabang A,
 * tetapi tetap aktif dan dapat diakses di cabang B.
 */
class StoreAssortmentService
{
    /**
     * Tetapkan status ketersediaan produk pada cabang tertentu.
     */
    public function setProductAvailability(
        int $tenantId,
        int $storeId,
        int $productId,
        bool $isAvailable,
        ?int $variantId = null,
        ?float $priceOverride = null
    ): StoreProductAssortment {
        return DB::transaction(function () use (
            $tenantId,
            $storeId,
            $productId,
            $isAvailable,
            $variantId,
            $priceOverride
        ) {
            /** @var StoreProductAssortment $assortment */
            $assortment = StoreProductAssortment::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                ],
                [
                    'is_available' => $isAvailable,
                    'is_active' => $isAvailable,
                    'store_price_override' => $priceOverride,
                ]
            );

            return $assortment;
        });
    }

    /**
     * Periksa apakah produk tertentu aktif dan tersedia di suatu cabang.
     */
    public function isProductAvailable(
        int $tenantId,
        int $storeId,
        int $productId,
        ?int $variantId = null
    ): bool {
        // Cek record spesifik di store_product_assortments
        $assortment = StoreProductAssortment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($assortment !== null) {
            return (bool) $assortment->is_available;
        }

        // Jika tidak ada record spesifik assortment, cek apakah produk induk aktif
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Ambil katalog produk yang aktif dan tersedia untuk suatu cabang kasir.
     *
     * @return Collection<int, Product>
     */
    public function getStoreCatalog(
        int $tenantId,
        int $storeId,
        ?string $search = null
    ): Collection {
        $query = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            // Exclude produk yang eksplisit dinonaktifkan pada cabang ini
            ->whereNotExists(function ($subQuery) use ($tenantId, $storeId) {
                $subQuery->select(DB::raw(1))
                    ->from('store_product_assortments')
                    ->whereColumn('store_product_assortments.product_id', 'products.id')
                    ->where('store_product_assortments.tenant_id', $tenantId)
                    ->where('store_product_assortments.store_id', $storeId)
                    ->whereNull('store_product_assortments.product_variant_id')
                    ->where('store_product_assortments.is_available', false);
            });

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('product_name', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhere('barcode', 'like', $term);
            });
        }

        return $query->orderBy('product_name', 'asc')->get();
    }
}
