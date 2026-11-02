<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductCostHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ProductCostHistoryService — Layanan pencatatan append-only riwayat perubahan HPP produk.
 *
 * Mengikuti spesifikasi:
 * - Setiap perubahan harga pokok barang wajib mencatat entri riwayat baru.
 * - Bersifat append-only dan dilindungi trigger database anti-mutasi.
 */
class ProductCostHistoryService
{
    /**
     * Catat mutasi perubahan HPP produk secara append-only dan mutasikan harga standar produk.
     */
    public function recordCostChange(
        int $tenantId,
        int $productId,
        string|float|int $newCost,
        ?int $userId = null,
        ?int $storeId = null,
        ?string $reason = null,
        string $costingMethod = 'standard'
    ): ProductCostHistory {
        return DB::transaction(function () use (
            $tenantId,
            $productId,
            $newCost,
            $userId,
            $storeId,
            $reason,
            $costingMethod
        ) {
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new RuntimeException("Produk #{$productId} tidak ditemukan pada tenant {$tenantId}.");
            }

            $oldCost = (float) ($product->standard_cost ?? $product->cost_price ?? 0.0);
            $numericNewCost = (float) $newCost;

            // Catat entri riwayat biaya ke tabel product_cost_histories (append-only)
            $history = ProductCostHistory::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'costing_method' => $costingMethod,
                'old_cost' => $oldCost,
                'new_cost' => $numericNewCost,
                'cost_price' => $numericNewCost,
                'reason' => $reason ?? 'Penyesuaian HPP standar',
                'changed_by' => $userId,
                'created_by_user_id' => $userId,
                'effective_at' => Carbon::now(),
                'effective_date' => Carbon::now(),
            ]);

            // Mutasikan harga pokok terkini pada produk induk
            $product->standard_cost = $numericNewCost;
            $product->costing_method = $costingMethod;
            $product->save();

            return $history;
        });
    }

    /**
     * Ambil histori riwayat HPP produk dari yang terbaru.
     *
     * @return Collection<int, ProductCostHistory>
     */
    public function getCostHistory(int $tenantId, int $productId, int $limit = 50): Collection
    {
        return ProductCostHistory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
