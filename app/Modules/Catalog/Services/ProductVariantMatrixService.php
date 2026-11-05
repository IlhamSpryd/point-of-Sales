<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAttributeValue;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\ProductVariantAttributeValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ProductVariantMatrixService — Generator otomatis permutasi matriks varian ritel.
 *
 * Mengikuti spesifikasi:
 * - Mengambil kombinasi atribut produk (misal: 3 ukuran x 2 warna = 6 varian).
 * - Menghasilkan SKU dan barcode unik per tenant.
 * - Mengaitkan relasi pivot atribut-varian secara atomik.
 */
class ProductVariantMatrixService
{
    /**
     * Generate matriks varian dari kumpulan opsi atribut untuk suatu produk.
     *
     * @param  array<int, array<int, int>>  $attributeOptions  [attribute_id => [attribute_value_id, ...]]
     * @return Collection<int, ProductVariant>
     */
    public function generateMatrix(
        int $tenantId,
        int $productId,
        array $attributeOptions,
        ?string $skuPrefix = null,
        string|int|float|null $priceOverride = null,
        string|int|float|null $costPrice = null
    ): Collection {
        if (empty($attributeOptions)) {
            throw new InvalidArgumentException('Daftar opsi atribut varian tidak boleh kosong.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $productId,
            $attributeOptions,
            $skuPrefix,
            $priceOverride,
            $costPrice
        ) {
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new RuntimeException("Produk dengan ID {$productId} tidak ditemukan pada tenant {$tenantId}.");
            }

            // Pastikan produk berstatus VARIANT_PARENT
            $product->product_type = 'VARIANT_PARENT';
            $product->has_variants = true;
            $product->save();

            // Ambil seluruh detail opsi atribut dari database
            $optionsLists = [];
            foreach ($attributeOptions as $attributeId => $valueIds) {
                if (empty($valueIds)) {
                    continue;
                }

                $values = ProductAttributeValue::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('attribute_id', $attributeId)
                    ->whereIn('id', $valueIds)
                    ->orderBy('sort_order', 'asc')
                    ->get();

                if ($values->isNotEmpty()) {
                    $optionsLists[] = $values->all();
                }
            }

            if (empty($optionsLists)) {
                throw new InvalidArgumentException('Tidak ada nilai atribut valid yang ditemukan.');
            }

            // Hitung hasil Cartesian product permutasi
            $cartesianCombinations = $this->cartesianProduct($optionsLists);

            $baseSku = $skuPrefix ?? (string) ($product->product_code ?? $product->sku ?? ('PRD-'.$productId));
            $createdVariants = new Collection;

            $counter = 1;
            foreach ($cartesianCombinations as $combination) {
                // Susun nama varian dan bagian SKU
                $nameParts = [];
                $skuParts = [];

                /** @var ProductAttributeValue $val */
                foreach ($combination as $val) {
                    $nameParts[] = $val->value;
                    $skuParts[] = ! empty($val->code) ? strtoupper((string) $val->code) : strtoupper(Str::slug($val->value, ''));
                }

                $variantName = $product->name.' - '.implode(' / ', $nameParts);
                $suggestedSku = strtoupper($baseSku).'-'.implode('-', $skuParts);

                // Jamin keunikan SKU di tenant
                $uniqueSku = $this->ensureUniqueSku($tenantId, $suggestedSku, $counter);
                $uniqueBarcode = $this->generateUniqueBarcode($tenantId, $productId, $counter);

                $resolvedPriceOverride = null;
                if ($priceOverride !== null && is_numeric((string) $priceOverride)) {
                    /** @var numeric-string $priceStr */
                    $priceStr = (string) $priceOverride;
                    $resolvedPriceOverride = bcadd($priceStr, '0', 2);
                }

                $rawCost = $costPrice ?? $product->standard_cost ?? '0.00';
                /** @var numeric-string $costStr */
                $costStr = is_numeric((string) $rawCost) ? (string) $rawCost : '0.00';
                $resolvedStandardCost = bcadd($costStr, '0', 2);

                $variant = ProductVariant::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'variant_name' => $variantName,
                    'sku' => $uniqueSku,
                    'barcode' => $uniqueBarcode,
                    'price_override' => $resolvedPriceOverride,
                    'standard_cost' => $resolvedStandardCost,
                    'costing_method' => $product->costing_method ?? 'standard',
                    'is_active' => true,
                    'sort_order' => $counter,
                ]);

                // Pasangkan ke relasi pivot product_variant_attribute_values
                foreach ($combination as $val) {
                    ProductVariantAttributeValue::withoutGlobalScopes()->create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $variant->id,
                        'attribute_id' => $val->attribute_id,
                        'attribute_value_id' => $val->id,
                    ]);
                }

                $createdVariants->push($variant);
                $counter++;
            }

            return $createdVariants;
        });
    }

    /**
     * Menghitung Cartesian product dari array of arrays.
     *
     * @param  array<int, array<int, ProductAttributeValue>>  $arrays
     * @return array<int, array<int, ProductAttributeValue>>
     */
    private function cartesianProduct(array $arrays): array
    {
        $result = [[]];

        foreach ($arrays as $propertyValues) {
            $append = [];
            foreach ($result as $product) {
                foreach ($propertyValues as $item) {
                    $append[] = array_merge($product, [$item]);
                }
            }
            $result = $append;
        }

        return $result;
    }

    /**
     * Memastikan SKU unik di tingkat tenant.
     */
    private function ensureUniqueSku(int $tenantId, string $sku, int $seed): string
    {
        $currentSku = $sku;
        $attempt = 1;

        while (ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku', $currentSku)
            ->exists()
        ) {
            $currentSku = "{$sku}-{$seed}-{$attempt}";
            $attempt++;
        }

        return $currentSku;
    }

    /**
     * Menghasilkan barcode unik untuk varian produk di tingkat tenant.
     */
    private function generateUniqueBarcode(int $tenantId, int $productId, int $index): string
    {
        do {
            $timestampPart = substr((string) time(), -6);
            $randomPart = str_pad((string) mt_rand(10, 99), 2, '0', STR_PAD_LEFT);
            $barcode = sprintf('899%04d%04d%02d%s', $tenantId % 10000, $productId % 10000, $index % 100, $randomPart);
        } while (ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $barcode)
            ->exists()
        );

        return $barcode;
    }
}
