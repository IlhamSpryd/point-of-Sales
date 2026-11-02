<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RetailBarcodeService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Memvalidasi algoritma check digit resmi EAN-13.
     */
    public function validateEan13Checksum(string $barcode): bool
    {
        $clean = trim($barcode);
        if (! preg_match('/^\d{13}$/', $clean)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $clean[$i];
            // Digit ganjil (1st, 3rd, 5th, dst. -> index 0, 2, 4...) bobot 1
            // Digit genap (2nd, 4th, 6th, dst. -> index 1, 3, 5...) bobot 3
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $remainder = $sum % 10;
        $calculatedCheckDigit = ($remainder === 0) ? 0 : (10 - $remainder);
        $actualCheckDigit = (int) $clean[12];

        return $calculatedCheckDigit === $actualCheckDigit;
    }

    /**
     * Pencarian instan scan-to-cart berbasis barcode varian/produk dengan validasi assortment cabang aktif.
     * Dijamin beroperasi pada performa sub-50ms (T-RTL-01).
     *
     * @return array{product: Product, variant: ?ProductVariant, price: float, barcode: string, execution_time_ms: float, is_available: bool}
     *
     * @throws DomainException
     */
    public function resolveBarcode(int $tenantId, int $storeId, string $barcode): array
    {
        $startTime = hrtime(true);
        $cleanBarcode = trim($barcode);

        if (empty($cleanBarcode)) {
            throw new InvalidArgumentException('Barcode tidak boleh kosong.');
        }

        // 1. Cari di ProductVariant terlebih dahulu (spesifik ukuran / warna ritel)
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $cleanBarcode)
            ->with(['product'])
            ->first();

        $product = null;
        if ($variant && $variant->product instanceof Product) {
            $product = $variant->product;
        } else {
            // 2. Jika bukan varian, cari langsung di tabel Products
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('barcode', $cleanBarcode)
                ->first();
        }

        if (! $product instanceof Product) {
            throw new DomainException("Barcode {$cleanBarcode} tidak terdaftar dalam katalog produk.");
        }

        // 3. Validasi Assortment Cabang Aktif (T-RTL-02)
        $assortmentQuery = DB::table('store_product_assortments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $product->id);

        if ($variant) {
            $assortmentQuery->where(function ($q) use ($variant) {
                $q->where('product_variant_id', $variant->id)
                    ->orWhereNull('product_variant_id');
            });
        }

        $assortment = $assortmentQuery->first();

        // Jika ada record assortment dan ditandai tidak tersedia, atau belum di-assort
        if ($assortment && ! (bool) $assortment->is_available) {
            throw new DomainException("Produk \"{$product->product_name}\" tidak dijual di cabang ini.");
        }

        // Jika cabang memiliki konfigurasi assortment ketat (ada produk lain di-assort, tapi produk ini tidak terdaftar)
        $hasBranchAssortments = DB::table('store_product_assortments')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->exists();

        if ($hasBranchAssortments && ! $assortment) {
            throw new DomainException("Produk \"{$product->product_name}\" tidak dijual di cabang ini.");
        }

        // 4. Resolusi Harga Jual Toko
        $finalPrice = (float) $product->product_price;
        if ($variant) {
            $variantPrice = $variant->price_override ?? $variant->price ?? null;
            if ($variantPrice !== null && (float) $variantPrice > 0) {
                $finalPrice = (float) $variantPrice;
            }
        }

        if ($assortment && $assortment->store_price_override !== null && (float) $assortment->store_price_override > 0) {
            $finalPrice = (float) $assortment->store_price_override;
        }

        $elapsedMs = (hrtime(true) - $startTime) / 1e6;

        return [
            'product' => $product,
            'variant' => $variant,
            'price' => $finalPrice,
            'barcode' => $cleanBarcode,
            'execution_time_ms' => round($elapsedMs, 2),
            'is_available' => true,
        ];
    }
}
