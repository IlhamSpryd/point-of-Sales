<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Services\AuditTrailService;
use App\Services\MenuCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogBulkEditService
{
    public function __construct(
        protected MenuCacheService $menuCache,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    /**
     * Memperbarui status siklus hidup sekelompok produk secara atomik dengan locking terurut.
     *
     * @param  array<int>  $productIds
     */
    public function bulkUpdateStatus(array $productIds, string $newStatus, int $tenantId): int
    {
        if (! in_array($newStatus, ['draft', 'active', 'inactive', 'discontinued'], true)) {
            throw ValidationException::withMessages(['status' => 'Status produk tidak valid.']);
        }

        if (empty($productIds)) {
            return 0;
        }

        return DB::transaction(function () use ($productIds, $newStatus, $tenantId) {
            // Urutan deterministik ID ASC untuk mencegah deadlock
            $products = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $isActive = ($newStatus === 'active');
            $updatedCount = 0;

            foreach ($products as $product) {
                $oldStatus = $product->status;
                $product->status = $newStatus;
                $product->is_active = $isActive;
                $product->save();
                $updatedCount++;
            }

            $this->menuCache->flush($tenantId);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'catalog.bulk_update_status',
                description: "Memperbarui status {$updatedCount} produk menjadi '{$newStatus}'.",
                metadata: [
                    'product_ids' => $products->pluck('id')->all(),
                    'new_status' => $newStatus,
                ]
            );

            return $updatedCount;
        });
    }

    /**
     * Memperbarui kategori sekelompok produk secara atomik.
     *
     * @param  array<int>  $productIds
     */
    public function bulkUpdateCategory(array $productIds, ?int $categoryId, int $tenantId): int
    {
        if (empty($productIds)) {
            return 0;
        }

        if ($categoryId !== null) {
            $catExists = Category::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $categoryId)
                ->exists();

            if (! $catExists) {
                throw ValidationException::withMessages(['category_id' => 'Kategori tidak valid atau milik tenant lain.']);
            }
        }

        return DB::transaction(function () use ($productIds, $categoryId, $tenantId) {
            $products = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $updatedCount = 0;
            foreach ($products as $product) {
                $product->category_id = $categoryId;
                $product->save();
                $updatedCount++;
            }

            $this->menuCache->flush($tenantId);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'catalog.bulk_update_category',
                description: "Memperbarui kategori untuk {$updatedCount} produk.",
                metadata: [
                    'product_ids' => $products->pluck('id')->all(),
                    'category_id' => $categoryId,
                ]
            );

            return $updatedCount;
        });
    }

    /**
     * Memperbarui harga jual sekelompok produk (fixed, percentage adjustment, atau nominal delta).
     *
     * @param  array<int>  $productIds
     * @param  float  $value  Nilai penyesuaian (misal: 10 untuk 10%, atau 5000 untuk +5000)
     * @param  string  $mode  'fixed' | 'percentage' | 'delta'
     */
    public function bulkUpdatePrice(array $productIds, float $value, string $mode, int $tenantId): int
    {
        if (empty($productIds)) {
            return 0;
        }

        if (! in_array($mode, ['fixed', 'percentage', 'delta'], true)) {
            throw ValidationException::withMessages(['mode' => 'Mode penyesuaian harga tidak valid.']);
        }

        if ($mode === 'fixed' && $value < 0) {
            throw ValidationException::withMessages(['value' => 'Harga tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($productIds, $value, $mode, $tenantId) {
            $products = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $updatedCount = 0;
            foreach ($products as $product) {
                $currentPrice = (float) $product->product_price;
                $newPrice = match ($mode) {
                    'fixed' => $value,
                    'percentage' => round($currentPrice * (1 + ($value / 100)), 2),
                    'delta' => round($currentPrice + $value, 2),
                };

                $product->product_price = (int) max(0, round($newPrice));
                $product->save();
                $updatedCount++;
            }

            $this->menuCache->flush($tenantId);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'catalog.bulk_update_price',
                description: "Memperbarui harga untuk {$updatedCount} produk dengan mode '{$mode}'.",
                metadata: [
                    'product_ids' => $products->pluck('id')->all(),
                    'mode' => $mode,
                    'value' => $value,
                ]
            );

            return $updatedCount;
        });
    }

    /**
     * Memperbarui ketersediaan atau harga cabang massal pada store_product_assortments.
     *
     * @param  array<int>  $productIds
     * @param  array{is_available?: bool, store_price_override?: float|null}  $data
     */
    public function bulkUpdateStoreAssortment(array $productIds, int $storeId, array $data, int $tenantId): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $storeExists = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $storeId)
            ->exists();

        if (! $storeExists) {
            throw ValidationException::withMessages(['store_id' => 'Cabang tidak valid atau milik tenant lain.']);
        }

        return DB::transaction(function () use ($productIds, $storeId, $data, $tenantId) {
            $products = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $updatedCount = 0;
            foreach ($products as $product) {
                $assortment = StoreProductAssortment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('product_id', $product->id)
                    ->first();

                $updateData = [];
                if (array_key_exists('is_available', $data)) {
                    $updateData['is_available'] = (bool) $data['is_available'];
                }
                if (array_key_exists('store_price_override', $data)) {
                    $updateData['store_price_override'] = $data['store_price_override'] !== null
                        ? max(0.00, (float) $data['store_price_override'])
                        : null;
                }

                if ($assortment) {
                    $assortment->update($updateData);
                } else {
                    StoreProductAssortment::create(array_merge([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'product_id' => $product->id,
                        'is_available' => true,
                    ], $updateData));
                }

                $updatedCount++;
            }

            $this->menuCache->flush($tenantId, $storeId);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'catalog.bulk_update_assortment',
                description: "Memperbarui asortimen cabang {$storeId} untuk {$updatedCount} produk.",
                metadata: [
                    'store_id' => $storeId,
                    'product_ids' => $products->pluck('id')->all(),
                    'data' => $data,
                ]
            );

            return $updatedCount;
        });
    }
}
