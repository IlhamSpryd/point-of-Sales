<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service untuk memvalidasi, mengekspansi komponen Sales BOM (product_bundle_items),
 * serta mengecek ketersediaan komponen di cabang POS secara baca-saja (ADR-04).
 */
class BundleComponentExpanderService
{
    /**
     * Memvalidasi bahwa komponen bundel yang dimasukkan tidak membentuk siklus (DAG validation)
     * dan tidak mereferensikan bundel itu sendiri.
     *
     * @param  array<int>  $componentProductIds
     *
     * @throws DomainException
     */
    public function validateDag(int $bundleProductId, array $componentProductIds): void
    {
        // 1. Validasi direct self-reference
        if (in_array($bundleProductId, $componentProductIds, true)) {
            throw new DomainException("Produk paket/bundel (ID: {$bundleProductId}) tidak boleh memasukkan dirinya sendiri sebagai komponen.");
        }

        // 2. Validasi siklik rekursif: Pastikan $bundleProductId tidak ada di sub-komponen
        $visited = [$bundleProductId => true];

        foreach ($componentProductIds as $compId) {
            $this->detectCycle((int) $compId, $bundleProductId, $visited);
        }
    }

    /**
     * Traversal rekursif DFS untuk mendeteksi siklus dependensi bundel.
     *
     * @param  array<int, bool>  $visited
     *
     * @throws DomainException
     */
    protected function detectCycle(int $currentProductId, int $targetRootId, array &$visited): void
    {
        if ($currentProductId === $targetRootId) {
            throw new DomainException("Terdeteksi referensi bundel siklis (circular dependency) yang melibatkan Produk ID {$targetRootId}.");
        }

        if (isset($visited[$currentProductId])) {
            return;
        }

        $visited[$currentProductId] = true;

        // Ambil komponen turunan jika produk ini juga merupakan bundel
        $subComponents = ProductBundleItem::withoutGlobalScopes()
            ->where('bundle_product_id', $currentProductId)
            ->pluck('component_product_id')
            ->all();

        foreach ($subComponents as $subId) {
            $this->detectCycle((int) $subId, $targetRootId, $visited);
        }
    }

    /**
     * Mengekspansi seluruh komponen dari produk bundel secara rekursif (flattening).
     *
     * @param  int  $multiplier  Kuantitas bundel yang dibeli/diekspansi
     * @return Collection<int, array{
     *     component_product_id: int,
     *     component_product: Product,
     *     component_variant_id: int|null,
     *     component_variant: ProductVariant|null,
     *     quantity: float,
     *     uom_id: int|null,
     *     cost_allocation_ratio: float|null
     * }>
     */
    public function expand(Product $bundleProduct, int $multiplier = 1): Collection
    {
        $expanded = collect();

        $bundleItems = ProductBundleItem::withoutGlobalScopes()
            ->where('tenant_id', $bundleProduct->tenant_id)
            ->where('bundle_product_id', $bundleProduct->id)
            ->with(['componentProduct', 'componentVariant'])
            ->orderBy('sort_order', 'asc')
            ->get();

        foreach ($bundleItems as $item) {
            $compProduct = $item->componentProduct;
            if (! $compProduct instanceof Product) {
                continue;
            }

            $effectiveQty = (float) $item->quantity * $multiplier;

            // Jika komponen adalah sub-bundel rekursif, ekspansi lebih lanjut
            if ($compProduct->isBundle()) {
                $subExpanded = $this->expand($compProduct, (int) ceil($effectiveQty));
                $expanded = $expanded->merge($subExpanded);
            } else {
                $expanded->push([
                    'component_product_id' => $compProduct->id,
                    'component_product' => $compProduct,
                    'component_variant_id' => $item->component_variant_id,
                    'component_variant' => $item->componentVariant,
                    'quantity' => $effectiveQty,
                    'uom_id' => $item->uom_id,
                    'cost_allocation_ratio' => $item->cost_allocation_ratio ? (float) $item->cost_allocation_ratio : null,
                ]);
            }
        }

        return $expanded;
    }

    /**
     * Memeriksa ketersediaan komponen bundel di cabang tertentu secara baca-saja.
     *
     * @return array{
     *     available: bool,
     *     max_quantity: int,
     *     missing_components: array<int, array{name: string, required: float, available: float}>
     * }
     */
    public function checkAvailability(Product $bundleProduct, int $storeId, int $requestedQty = 1): array
    {
        $components = $this->expand($bundleProduct, $requestedQty);

        if ($components->isEmpty()) {
            return [
                'available' => true,
                'max_quantity' => $requestedQty,
                'missing_components' => [],
            ];
        }

        $missingComponents = [];
        $maxUnitsPossible = PHP_INT_MAX;

        foreach ($components as $comp) {
            /** @var Product $product */
            $product = $comp['component_product'];
            $requiredTotal = (float) $comp['quantity'];
            $requiredPerUnit = $requiredTotal / max(1, $requestedQty);

            // Layanan / non_inventory tidak dibatasi stok fisik
            if (! $product->track_inventory || in_array($product->product_type, ['service', 'non_inventory'], true)) {
                continue;
            }

            // Cek saldo stok di cabang
            $stockBalance = DB::table('product_stock_balances')
                ->where('tenant_id', $bundleProduct->tenant_id)
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->value('quantity');

            $currentStock = (float) ($stockBalance ?? 0);

            if ($requiredPerUnit > 0) {
                $possibleForThisComp = (int) floor($currentStock / $requiredPerUnit);
                if ($possibleForThisComp < $maxUnitsPossible) {
                    $maxUnitsPossible = max(0, $possibleForThisComp);
                }
            }

            if ($currentStock < $requiredTotal) {
                $compName = $product->product_name;
                if ($comp['component_variant']) {
                    $compName .= ' ('.$comp['component_variant']->variant_name.')';
                }

                $missingComponents[] = [
                    'name' => $compName,
                    'required' => $requiredTotal,
                    'available' => $currentStock,
                ];
            }
        }

        $maxQuantity = ($maxUnitsPossible === PHP_INT_MAX) ? $requestedQty : $maxUnitsPossible;

        return [
            'available' => empty($missingComponents),
            'max_quantity' => $maxQuantity,
            'missing_components' => $missingComponents,
        ];
    }
}
